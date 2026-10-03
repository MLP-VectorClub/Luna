<?php

namespace Tests\Feature;

use Tests\TestCase;

class NutshellNamesTest extends TestCase
{
    public function testTheNamesAreServedByAppearanceId(): void
    {
        $response = $this->getJson('/appearances/nutshell-names')->assertOk();

        $names = $response->json('names');
        $this->assertSame(['common hues'], $names['0']);
        $this->assertContains('twinkle sprinkle', $names['1']);
        $this->assertGreaterThan(300, count($names));
        foreach ($names as $id => $list) {
            $this->assertIsString((string) $id);
            $this->assertNotEmpty($list);
            $this->assertContainsOnly('string', $list);
        }
    }

    public function testTheDataMatchesWhatWinterchillaHasWhenItIsAround(): void
    {
        $file = dirname(__DIR__, 3).'/Winterchilla/config/nutshell_names.php';
        if (!is_file($file)) {
            $this->markTestSkipped('The Winterchilla repository is not next to Luna');
        }
        $source = preg_replace('/\);\s*$/', ';', preg_replace("/^<\?php\s*define\('NUTSHELL_NAMES',/", 'return ', rtrim(file_get_contents($file))));
        $expected = eval($source);

        $this->assertEquals($expected, json_decode(file_get_contents(resource_path('data/nutshell_names.json')), true));
    }
}
