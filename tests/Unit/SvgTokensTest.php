<?php

namespace Tests\Unit;

use App\Utils\SvgHelper;
use PHPUnit\Framework\TestCase;

class SvgTokensTest extends TestCase
{
    private const SVG = '<svg><path fill="#ff0000" stroke="#0F0"/><path style="fill:rgba(0, 0, 255, 0.5)"/><path fill="#123456"/></svg>';

    public function testKnownColorsBecomeTokensAndComeBackWithTheCurrentValue(): void
    {
        $tokenized = SvgHelper::tokenize(self::SVG, [7 => '#FF0000', 8 => '#00FF00', 9 => '#0000FF']);

        $this->assertStringContainsString('fill="<!--@7:FF0000-->"', $tokenized);
        $this->assertStringContainsString('stroke="<!--@8:00FF00-->"', $tokenized);
        $this->assertStringContainsString('fill:<!--@9:0000FF,0.5-->', $tokenized);
        $this->assertStringContainsString('fill="<!--#/123456FF-->"', $tokenized);

        // The guide's color 7 was edited
        $warnings = [];
        $rendered = SvgHelper::untokenize($tokenized, [7 => '#AABBCC', 8 => '#00FF00', 9 => '#0000FF'], $warnings);
        $this->assertStringContainsString('fill="#aabbcc"', strtolower($rendered));
        $this->assertStringContainsString('stroke="#00ff00"', strtolower($rendered));
        $this->assertStringNotContainsString('<!--', $rendered);
        $this->assertSame(['Unexpected color #123456 (not found in Cutie Mark color group)'], $warnings);
    }

    public function testADeletedColorKeepsTheValueStoredInTheToken(): void
    {
        $tokenized = SvgHelper::tokenize('<svg><path fill="#ff0000"/></svg>', [7 => '#FF0000']);

        $rendered = SvgHelper::untokenize($tokenized, []);

        $this->assertStringContainsString('fill="#ff0000"', strtolower($rendered));
    }

    public function testWithoutKnownColorsEverythingIsUnexpected(): void
    {
        $tokenized = SvgHelper::tokenize('<svg><path fill="#ff0000"/></svg>', []);

        $this->assertStringContainsString('<!--#/FF0000FF-->', $tokenized);
    }
}
