<?php

namespace Tests\Unit\Rules;

use App\Rules\Email;
use App\Rules\StrictEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Only covers what can be decided without network access, the DNS part of the rule is left alone on purpose
 */
class StrictEmailRuleTest extends TestCase
{
    public static function invalidAddresses(): array
    {
        return [
            'no at sign' => ['someone.example.com'],
            'no domain' => ['someone@'],
            'spaces' => ['some one@example.com'],
            'double dot' => ['some..one@example.com'],
            'too long' => [str_repeat('a', Email::MAXIMUM_LENGTH).'@example.com'],
        ];
    }

    #[DataProvider('invalidAddresses')]
    public function testRejectsInvalidSyntax(string $address): void
    {
        $this->assertFalse((new StrictEmail())->passes('email', $address));
    }

    public function testRejectsNonStrings(): void
    {
        $this->assertFalse((new StrictEmail())->passes('email', ['someone@example.com']));
        $this->assertFalse((new StrictEmail())->passes('email', null));
    }

    public function testRejectsDomainsThatCannotResolve(): void
    {
        // .invalid is reserved and never resolves, with or without network access
        $this->assertFalse((new StrictEmail())->passes('email', 'someone@this-domain-does-not-exist.invalid'));
    }
}
