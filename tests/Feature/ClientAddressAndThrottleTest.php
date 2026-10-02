<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ClientAddressAndThrottleTest extends TestCase
{
    private function addressSeenBy(string $remote_addr, ?string $forwarded_for): string
    {
        Route::middleware('api')->get('/_ip', fn(Request $request) => response()->json(['ip' => $request->ip()]));

        return $this->withServerVariables(['REMOTE_ADDR' => $remote_addr])
            ->getJson('/_ip', $forwarded_for === null ? [] : ['X-Forwarded-For' => $forwarded_for])
            ->json('ip');
    }

    public function testTheVisitorAddressForwardedByTheLocalNginxIsUsed(): void
    {
        $this->assertSame('203.0.113.7', $this->addressSeenBy('127.0.0.1', '203.0.113.7'));
        $this->assertSame('2001:db8::5', $this->addressSeenBy('::1', '2001:db8::5'));
    }

    public function testTheHeaderIsIgnoredWhenItDoesNotComeFromALocalProcess(): void
    {
        $this->assertSame('198.51.100.9', $this->addressSeenBy('198.51.100.9', '203.0.113.7'));
    }

    public function testReadsGetAMuchLargerAllowanceThanWrites(): void
    {
        $limiter = RateLimiter::limiter('api');

        $read = $limiter(Request::create('/show/1', 'GET'));
        $write = $limiter(Request::create('/show/1/vote', 'POST'));

        $this->assertSame(1200, $read->maxAttempts);
        $this->assertSame(60, $write->maxAttempts);
        $this->assertNotSame($read->key, $write->key);
    }

    public function testEachClientHasItsOwnBucket(): void
    {
        $limiter = RateLimiter::limiter('api');
        $a = Request::create('/show/1', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.1']);
        $b = Request::create('/show/1', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.2']);

        $this->assertNotSame($limiter($a)->key, $limiter($b)->key);
    }
}
