<?php

namespace Maghead\Types;

use DateTime as PHPDateTime;

/**
 * Extended DateTime class from PHP built-in DateTime.
 *
 * @codeCoverageIgnore
 */
class DateTime extends PHPDateTime
{
    public function __toString()
    {
        return $this->format(PHPDateTime::ATOM);
    }
}
