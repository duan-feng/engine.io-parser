<?php

namespace SwooleIO\EngineIO\Parser;

use Swoole\Buffer as SwBuffer;

class Buffer extends SwBuffer
{
    public function __toString()
    {
        return $this->read();
    }

    public function read(int $offset = 0, int $length = null): string
    {
        if (is_null($length)) {
            $length = $offset < 0 ? -$offset : ($this->length - $offset);
        }

        return parent::read($offset, $length);
    }
}