<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Models\Appearance;
use App\Models\User;
use App\Utils\NutshellNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NutshellNamesTest extends TestCase
{
    use RefreshDatabase;

    private function official(int $id, string $label): Appearance
    {
        $appearance = new Appearance(['label' => $label, 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => null, 'order' => $id]);
        $appearance->forceFill(['id' => $id])->save();

        return $appearance;
    }

    public function testAppearancesCarryTheirAlternativeNames(): void
    {
        $with = $this->official(1, 'Twilight Sparkle');
        // The ids of the names file are the ones of production, keep the generated ids out of its range
        DB::statement("SELECT setval(pg_get_serial_sequence('appearances', 'id'), 5000)");
        $without = $this->official(900001, 'Nobody Special');
        $personal = Appearance::create(['label' => 'Mine', 'owner_id' => User::factory()->create(['role' => Role::User])->id, 'notes_src' => null]);

        $this->assertContains('twinkle sprinkle', NutshellNames::for($with));
        $this->assertSame([], NutshellNames::for($without));
        $this->assertSame([], NutshellNames::for($personal));

        $response = $this->getJson('/appearances/full?guide=pony')->assertOk();
        $by_id = collect($response->json('appearances'))->keyBy('id');
        $this->assertContains('twinkle sprinkle', $by_id[1]['nutshellNames']);
        $this->assertSame([], $by_id[900001]['nutshellNames']);

        $this->getJson('/appearances/1')->assertOk()->assertJsonPath('nutshellNames.0', 'twinkle sprinkle');
        $this->getJson('/appearances/900001')->assertOk()->assertJsonPath('nutshellNames', []);
    }

    public function testTheDataMatchesWhatWinterchillaHasWhenItIsAround(): void
    {
        $file = dirname(__DIR__, 3).'/Winterchilla/config/nutshell_names.php';
        if (!is_file($file)) {
            $this->markTestSkipped('The Winterchilla repository is not next to Luna');
        }
        $source = preg_replace('/\);\s*$/', ';', preg_replace("/^<\?php\s*define\('NUTSHELL_NAMES',/", 'return ', rtrim(file_get_contents($file))));

        $this->assertEquals(eval($source), json_decode(file_get_contents(resource_path('data/nutshell_names.json')), true));
    }
}
