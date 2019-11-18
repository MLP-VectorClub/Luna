<?php

declare(strict_types=1);

use Carbon\Carbon;

if (!function_exists('isoTime')) {
    function isoTime($date): string
    {
        if ($date === null) {
            return '';
        }

        if ($date instanceof Carbon) {
            return $date->toW3cString();
        }

        return (string) $date;
    }
}
