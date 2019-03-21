<?php

namespace Nldou\AliyunOSS;

class Util
{
    /**
     * Normalize a dirname return value.
     *
     * @param string $dirname
     *
     * @return string normalized dirname
     */
    public static function normalizeDirname($dirname)
    {
        return $dirname === '.' ? '' : $dirname;
    }

    /**
     * Get a normalized dirname from a path.
     *
     * @param string $path
     *
     * @return string dirname
     */
    public static function dirname($path)
    {
        return static::normalizeDirname(dirname($path));
    }

    /**
     * Map result arrays.
     *
     * @param array $object
     * @param array $map
     *
     * @return array mapped result
     */
    public static function map(array $object, array $map)
    {
        $result = [];

        foreach ($map as $from => $to) {
            if ( ! isset($object[$from])) {
                continue;
            }

            $result[$to] = $object[$from];
        }

        return $result;
    }

    /**
     * check whether is a timestamp.
     *
     * @param int|string $object
     *
     * @return boolean check result
     */
    public static function is_timestamp($timestamp) {
        return is_numeric($timestamp) && (int)$timestamp == $timestamp;
    }

}
