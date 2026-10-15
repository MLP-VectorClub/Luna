<?php

namespace App\Utils;

/** Constraints of path parameters */
final class RoutePatterns
{
    /**
     * Numbers in a path have to fit the database's bigint (and PHP's int): up to 18 digits. More, or letters, is a page that does not exist, not a server
     * error (`whereNumber` lets any number of digits through)
     */
    public const ID = '[0-9]{1,18}';
}
