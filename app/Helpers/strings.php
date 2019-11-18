<?php

declare(strict_types=1);


if (!function_exists('camel_case')) {
    function camel_case(string $string): string
    {
        return \Illuminate\Support\Str::camel($string);
    }
}
