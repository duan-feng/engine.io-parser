<?php

namespace SwooleIO\EngineIO\Parser;

use Exception;
use InvalidArgumentException;

class Parser
{
    const PROTOCOL = 3;

    const PACKETS = [
        'open'=>     0,
        'close'=>    1,
        'ping'=>     2,
        'pong'=>     3,
        'message'=>  4,
        'upgrade'=>  5,
        'noop'=>     6,
    ];

    /**
     * @var null|string[]
     */
    private static $packetsList = null;

    /**
     * @var array
     */
    protected static $err = [
        'type' => 'error',
        'data' => 'parser error',
    ];

    /**
     * @var Parser|null
     */
    private static $instance;

    protected function __construct()
    {
        if (self::$packetsList === null) {
            self::$packetsList = array_keys(self::PACKETS);
        }
    }

    protected function checkPacket(array &$packet)
    {
        $type = $packet['type'] ?? '';

        if (!isset(self::PACKETS[$type])) {
            throw new InvalidArgumentException(
                'Invalid Packet Type [' . $type . '].'
            );
        }

        if (isset($packet['data']) && (!is_string($packet['data'])) && (!$packet['data'] instanceof Buffer)) {
            throw new InvalidArgumentException(
                'Invalid packet data type.'
            );
        }
    }

    public function encodePacket(array &$packet, callable $callback,
                                 bool $supportsBinary = false, bool $utf8encode = false)
    {
        $this->checkPacket($packet);

        if (($packet['data'] ?? null) instanceof Buffer) {
            return $this->encodeBuffer($packet, $callback, $supportsBinary);
        }

        $encoded = self::PACKETS[$packet['type']];

        if (isset($packet['data']) && !empty($packet['data'])) {
            $encoded .= $utf8encode
                ? Utf8::encode($packet['data'], ['strict' => false])
                : $packet['data'];

//            if ($utf8encode && !mb_check_encoding($packet['data'], 'UTF-8')) {
//                $encoded .= mb_convert_encoding(
//                    $packet['data'],
//                    'UTF-8',
//                    (mb_detect_encoding($packet['data']) ?: 'auto')
//                );
//            } else {
//                $encoded .= $packet['data'];
//            }
        }

        return call_user_func($callback, (string) $encoded);
    }

    protected function encodeBuffer(array &$packet, callable $callback, bool $supportsBinary)
    {
        if (!$supportsBinary) {
            return $this->encodeBase64Packet($packet, $callback);
        }

        /* @var Buffer $data */
        $data = $packet['data'];

        $buf = new Buffer(1 + $data->length);
        $buf->append(self::PACKETS[$packet['type']]);
        $buf->append($data->read());

        return call_user_func($callback, $buf);
    }

    public function encodeBase64Packet(array &$packet, callable $callback)
    {
        $this->checkPacket($packet);

        $buf = 'b' . self::PACKETS[$packet['type']];
        $buf .= base64_encode($packet['data'] ?? '');

        return call_user_func($callback, $buf);
    }

    public function decodePacket($data, $binaryType, $utf8decode)
    {
        if (!is_string($data) && !$data instanceof Buffer) {
            return static::$err;
        }

        $ret = [];
        if (is_string($data)) {
            /* @var string $data */
            $dataLength = strlen($data);
            if ($dataLength < 1) {
                return static::$err;
            }

            if ($data[0] === 'b') {
                return $this->decodeBase64Packet(substr($data, 1), $binaryType);
            }

            if ($utf8decode) {
                $data = Utf8::decode($data, ['strict' => false]);
            }

            $type = (int) $data[0];
            if (!isset(self::$packetsList[$type])) {
                return static::$err;
            }

            $ret['type'] = self::$packetsList[$type];

            if ($dataLength > 1) {
                $ret['data'] = substr($data, 1);
            }
        } else {
            /* @var Buffer $data */
            if ($data->length < 1) {
                return static::$err;
            }

            $type = (int) $data->read(0, 1);
            if (!isset(self::$packetsList[$type])) {
                return static::$err;
            }

            $ret['type'] = self::$packetsList[$type];

            if ($data->length > 1) {
                $ret['data'] = $data->read(1);
            }
        }

        return $ret;
    }

    public function decodeBase64Packet(string $msg, $binaryType)
    {
        if (strlen($msg) < 1 || !isset(self::$packetsList[$msg[0]])) {
            return static::$err;
        }

        $data = base64_decode(substr($msg, 1), true);
        if ($data === false) {
            return static::$err;
        }

        return ['type' => self::$packetsList[$msg[0]], 'data' => $data];
    }

    public function encodePayload(array &$packets, callable $callback, bool $supportsBinary = false)
    {
        if ($supportsBinary) {
            return $this->encodePayloadAsBinary($packets, $callback);
        }

        if (count($packets) < 1) {
            call_user_func($callback, '0:');
        }

        $encodedArray = [];
        foreach ($packets as &$packet) {
            $this->encodePacket($packet, function ($message) use (&$encodedArray) {
                $encodedArray[] = strlen($message) . ':' . $message;
            });
        }

        return call_user_func($callback, implode('', $encodedArray));
    }

    public function decodePayload($data, callable $callback, $binaryType)
    {
        if ($data instanceof Buffer) {
            return $this->decodePayloadAsBinary($data, $callback, $binaryType);
        }

        $data = trim($data);

        /* @var string $data */
        if ($data === '') {
            return call_user_func($callback, static::$err, 0, 1);
        }

        $payloadLength = strlen($data);
        $packetLength = '';

        for ($i = 0; $i < $payloadLength; ++$i) {
            $chr = $data[$i];

            if ($chr !== ':') {
                $packetLength .= $chr;
                continue;
            }

            if ($packetLength === '' || $packetLength != ($n = (int) $packetLength)) {
                return call_user_func($callback, static::$err, 0, 1);
            }

            $packet = substr($data, $i + 1, $n);

            if ($packet === false || strlen($packet) !== $n) {
                return call_user_func($callback, static::$err, 0, 1);
            }

            if (strlen($packet) > 0) {
                $packet = $this->decodePacket($packet, $binaryType, false);

                if ($packet['type'] = static::$err['type'] && $packet['data'] = static::$err['data']) {
                    return call_user_func($callback, static::$err, 0, 1);
                }

                if (call_user_func($callback, $packet, $i + $n, $payloadLength) === false) {
                    return;
                }
            }

            $i += $n;
            $packetLength = '';
        }

        if ($packetLength !== '') {
            return call_user_func($callback, static::$err, 0, 1);
        }
    }

    public function encodePayloadAsBinary(array &$packets, callable $callback)
    {
        if (count($packets) < 1) {
            return call_user_func($callback, new Buffer(0));
        }

        $returnBuffer = new Buffer();
        foreach ($packets as &$packet) {
            $this->encodePacket($packet, function ($encoded) use ($returnBuffer) {
                if (is_string($encoded)) {
                    /* @var string $encoded */
                    $encodedLength = strlen($encoded);
                } else {
                    /* @var Buffer $encoded */
                    $encodedLength = $encoded->length;
                }

                $sizeBuffer = is_string($encoded) ? chr(0) : chr(1);
                $sizeBuffer .= call_user_func('pack', 'C*', ...str_split($encodedLength));
                $sizeBuffer .= chr(255);

                if (is_string($encoded)) {
                    /* @var string $encoded */
                    $sizeBuffer .= $encoded;
                } else {
                    /* @var Buffer $encoded */
                    $sizeBuffer .= $encoded->read();
                }

                $returnBuffer->append($sizeBuffer);
            }, true, true);
        }

        return call_user_func($callback, $returnBuffer);
    }

    public function decodePayloadAsBinary(Buffer $data, callable $callback, $binaryType)
    {
        $offset = 0;
        $buffers = [];

        while ($offset < $data->length) {
            $msgLength = '';
//            $isString = ord($data->read($offset++, 1)) === 0;
            ++$offset;

            for (;;) {
                $char = $data->read($offset++, 1);
                if ($char === false || strlen($msgLength) > 310) {
                    return call_user_func($callback, static::$err, 0, 1);
                }

                $char = ord($char);
                if ($char === 255) {
                    break;
                }

                $msgLength .= $char;
            }

            $msgLength = (int) $msgLength;

            if (($buffers[] = $data->read($offset, $msgLength)) === false) {
                return call_user_func($callback, static::$err, 0, 1);
            }

            $offset += $msgLength;
        }

        $total = count($buffers);
        foreach ($buffers as $idx => &$buffer) {
            call_user_func(
                $callback,
                $this->decodePacket($buffer, $binaryType, true),
                $idx,
                $total
            );
        }
    }

    public static function getInstance()
    {
        return self::$instance ?? new static();
    }

    private function __clone()
    {
        // do nothing
    }

    private function __wakeup()
    {
        throw new Exception('Cannot unserialize singleton');
    }

    private function __sleep()
    {
        throw new Exception('Cannot serialize singleton');
    }
}