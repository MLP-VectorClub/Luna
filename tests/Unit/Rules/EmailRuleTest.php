<?php

namespace Tests\Unit\Rules;

use App\Rules\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailRuleTest extends TestCase
{
    public static function validAddresses(): array
    {
        return [
            'plain' => ['someone@example.com'],
            'plus tag' => ['someone+tag@example.com'],
            'subdomain' => ['a.b@mail.example.co.uk'],
        ];
    }

    public static function invalidAddresses(): array
    {
        return [
            'no at sign' => ['someone.example.com'],
            'no domain' => ['someone@'],
            'no local part' => ['@example.com'],
            'spaces' => ['some one@example.com'],
            'double dot' => ['some..one@example.com'],
            'empty' => [''],
            'too long' => [str_repeat('a', Email::MAXIMUM_LENGTH).'@example.com'],
        ];
    }

    #[DataProvider('validAddresses')]
    public function testAcceptsValidSyntax(string $address): void
    {
        $this->assertTrue((new Email())->passes('email', $address));
    }

    #[DataProvider('invalidAddresses')]
    public function testRejectsInvalidSyntax(string $address): void
    {
        $this->assertFalse((new Email())->passes('email', $address));
    }

    public function testRejectsNonStrings(): void
    {
        $this->assertFalse((new Email())->passes('email', ['someone@example.com']));
        $this->assertFalse((new Email())->passes('email', null));
        $this->assertFalse((new Email())->passes('email', 123));
    }

    public function testDoesNotNeedDns(): void
    {
        // The lax rule is only about syntax, so it must accept domains that don't resolve
        $this->assertTrue((new Email())->passes('email', 'someone@this-domain-does-not-exist.invalid'));
    }
}
