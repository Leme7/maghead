<?php

namespace Maghead\DSN;

use Exception;

/**
 * DataSourceName class provides a basic DSN parser.
 *
 * @see http://php.net/manual/en/ref.pdo-pgsql.connection.php
 * @see http://php.net/manual/en/ref.pdo-mysql.connection.php
 */
class DSNParser
{
    public static function parse($dsn)
    {
        if (preg_match('/^(\w+):/', $dsn, $matches)) {
            $driver = $matches[1];
        } elseif (preg_match('/^(\w+)$/', $dsn, $matches)) {
            return new DSN($dsn, [], [], '');
        } else {
            throw new Exception("Invalid DSN string: '$dsn'");
        }
        $reststr = preg_replace('/^\w+:/', '', $dsn);
        $attributes = [];
        $arguments = [];
        $parts = preg_split('/[ ;]/', $reststr);
        foreach ($parts as $part) {
            if (strpos($part, '=') === false) {
                $arguments[] = $part;
            } else {
                list($key, $val) = explode('=', $part);
                $attributes[ trim($key) ] = trim($val);
            }
        }

        return new DSN($driver, $attributes, $arguments, $dsn);
    }
}
