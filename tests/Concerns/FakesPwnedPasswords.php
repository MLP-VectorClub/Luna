<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * valorin/pwned-validator calls the Have I Been Pwned API through curl and caches the result per hash prefix,
 * so seeding that cache keeps the tests off the network
 */
trait FakesPwnedPasswords
{
    protected function fakePwnedPassword(string $password, int $times): void
    {
        $hash = strtoupper(sha1($password));
        Cache::put('pwned:'.substr($hash, 0, 5), [substr($hash, 5) => $times], 3600);
    }
}
