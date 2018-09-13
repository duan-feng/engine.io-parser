<?php

namespace SwooleIO\EngineIO\Parser;

class Utf8
{
    /**
     * Dummy utf8 encode.
     *
     * @param string $string
     * @param array $opts
     * @return string
     */
    public static function encode(string &$string, array $opts)
    {
        return $string;
    }

    /**
     * Dummy utf8 decode.
     *
     * @param string $string
     * @param array $opts
     * @return string
     */
    public static function decode(string &$string, array $opts)
    {
        return $string;
    }
}