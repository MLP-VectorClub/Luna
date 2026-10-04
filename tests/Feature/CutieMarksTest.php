<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Models\Appearance;
use App\Models\ColorGroup;
use App\Models\Color;
use App\Models\CutieMark;
use App\Models\DeviantartUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CutieMarksTest extends TestCase
{
    use RefreshDatabase;

    private function as(Role $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function appearance(): Appearance
    {
        return Appearance::create(['label' => 'CM Pony', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => null, 'order' => 1]);
    }

    private function svg(): string
    {
        return file_get_contents(__DIR__.'/../fixtures/cutiemark.svg');
    }

    private function putCms(Appearance $appearance, array $items)
    {
        return $this->putJson("/appearances/{$appearance->id}/cutie-marks", ['cutieMarks' => json_encode($items)]);
    }

    public function testPermissions(): void
    {
        $appearance = $this->appearance();
        $this->getJson("/appearances/{$appearance->id}/cutie-marks")->assertUnauthorized();
        $this->postJson("/appearances/{$appearance->id}/sanitize-svg")->assertUnauthorized();
        $this->as(Role::User);
        $this->getJson("/appearances/{$appearance->id}/cutie-marks")->assertForbidden();
        $this->putJson("/appearances/{$appearance->id}/cutie-marks", ['cutieMarks' => '[]'])->assertForbidden();
        $this->getJson('/appearances/987654/cutie-marks')->assertNotFound();
    }

    public function testAddRenameAndRemove(): void
    {
        Storage::fake('public');
        $this->as(Role::Staff);
        $appearance = $this->appearance();

        $this->putCms($appearance, [['svgdata' => $this->svg(), 'facing' => 'left', 'attribution' => 'none', 'rotation' => 0, 'label' => 'Scratch CM']])
            ->assertOk()->assertExactJson([]);
        $cms = $this->getJson("/appearances/{$appearance->id}/cutie-marks")->assertOk()->json('cms');
        $this->assertCount(1, $cms);
        $this->assertSame('Scratch CM', $cms[0]['label']);
        $this->assertSame('left', $cms[0]['facing']);
        $this->assertSame($appearance->id, $cms[0]['appearanceId']);
        $this->assertStringContainsString('.svg', $cms[0]['rendered']);
        $this->assertDatabaseHas('logs', ['entry_type' => 'cm_modify']);
        $this->assertStringNotContainsString('<script', file_get_contents(CutieMark::first()->vectorFile()->getPath()));

        $this->putCms($appearance, [['id' => $cms[0]['id'], 'facing' => 'right', 'attribution' => 'none', 'rotation' => 10, 'label' => 'Renamed CM']])->assertOk();
        $cm = $this->getJson("/appearances/{$appearance->id}/cutie-marks")->json('cms.0');
        $this->assertSame('Renamed CM', $cm['label']);
        $this->assertSame('right', $cm['facing']);
        $this->assertSame(10, $cm['rotation']);

        $this->putCms($appearance, [])->assertOk();
        $this->assertSame([], $this->getJson("/appearances/{$appearance->id}/cutie-marks")->json('cms'));
        $this->assertDatabaseHas('logs', ['entry_type' => 'cm_delete']);
    }

    public function testSymmetricalCutieMarkHasNoFacing(): void
    {
        Storage::fake('public');
        $this->as(Role::Staff);
        $appearance = $this->appearance();

        $this->putCms($appearance, [['svgdata' => $this->svg(), 'attribution' => 'none', 'rotation' => 0]])->assertOk();
        $this->assertNull($this->getJson("/appearances/{$appearance->id}/cutie-marks")->json('cms.0.facing'));
        $this->putCms($appearance, [['id' => CutieMark::first()->id, 'facing' => null, 'attribution' => 'none', 'rotation' => 0]])->assertOk();
        $this->assertNull(CutieMark::first()->facing);
    }

    public function testValidation(): void
    {
        Storage::fake('public');
        $this->as(Role::Staff);
        $appearance = $this->appearance();
        $base = ['svgdata' => $this->svg(), 'facing' => 'left', 'attribution' => 'none', 'rotation' => 0];

        foreach ([['rotation' => 90], ['attribution' => 'nonsense'], ['facing' => 'sideways'], ['svgdata' => 'nope'], ['svgdata' => ''], ['label' => str_repeat('a', 33)], ['attribution' => 'user'], ['attribution' => 'user', 'username' => 'No Such_User']] as $bad) {
            $this->putCms($appearance, [$bad + $base])->assertJsonValidationErrors('cutiemarks');
        }
        $this->putCms($appearance, array_fill(0, 3, $base))->assertJsonValidationErrors('cutiemarks');
        $this->putCms($appearance, [['id' => 99999] + $base])->assertJsonValidationErrors('cutiemarks');
        $this->putCms($appearance, [['label' => 'Same'] + $base, ['label' => 'Same'] + $base])->assertJsonValidationErrors('cutiemarks');
        $this->putJson("/appearances/{$appearance->id}/cutie-marks", [])->assertJsonValidationErrors('cutieMarks');
        $this->assertSame(0, CutieMark::count());
    }

    public function testAttributionToKnownUser(): void
    {
        Storage::fake('public');
        $this->as(Role::Staff);
        $appearance = $this->appearance();
        $da = new DeviantartUser(['name' => 'Contributor', 'avatar_url' => 'https://example.com/a.png']);
        $da->forceFill(['id' => '0f0e0d0c-0b0a-4000-8000-000000000001', 'user_id' => User::factory()->create()->id])->save();

        $this->putCms($appearance, [['svgdata' => $this->svg(), 'attribution' => 'user', 'username' => $da->name, 'rotation' => 0]])->assertOk();
        $cm = $this->getJson("/appearances/{$appearance->id}/cutie-marks")->json('cms.0');
        $this->assertSame($da->name, $cm['username']);
    }

    public function testSanitizeSvg(): void
    {
        $this->as(Role::Staff);
        $appearance = $this->appearance();
        $group = ColorGroup::create(['appearance_id' => $appearance->id, 'label' => 'Cutie Mark', 'order' => 0]);
        Color::create(['group_id' => $group->id, 'label' => 'Fill', 'order' => 0, 'hex' => '#123456']);

        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script><path d="M0 0h10v10z" fill="#ABCDEF" onclick="x()"/></svg>';
        $r = $this->post("/appearances/{$appearance->id}/sanitize-svg", ['file' => UploadedFile::fake()->createWithContent('cm.svg', $dirty)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('keepDialog', true);
        $this->assertStringNotContainsString('script', $r->json('svgel'));
        $this->assertStringNotContainsString('onclick', $r->json('svgel'));
        $this->assertSame($dirty, $r->json('svgdata'));
        $this->assertSame(['Unexpected color #ABCDEF (not found in Cutie Mark color group)'], $r->json('warnings'));

        $this->post("/appearances/{$appearance->id}/sanitize-svg", ['file' => UploadedFile::fake()->createWithContent('cm.svg', 'not svg')], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
        $this->postJson("/appearances/{$appearance->id}/sanitize-svg")->assertJsonValidationErrors('file');
    }

    public function testCutieMarkColorsFollowTheGuide(): void
    {
        $this->as(Role::Staff);
        $appearance = $this->appearance();
        $group = ColorGroup::create(['appearance_id' => $appearance->id, 'label' => 'Cutie Mark', 'order' => 0]);
        $color = Color::create(['group_id' => $group->id, 'label' => 'Fill', 'order' => 1, 'hex' => '#FF0000']);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10z" fill="#ff0000"/><path d="M0 0h5v5z" fill="#00ff00"/></svg>';

        $this->putCms($appearance, [['svgdata' => $svg, 'attribution' => 'none', 'rotation' => 0]])->assertOk();
        $cm = CutieMark::first();
        $this->assertStringContainsString('#ff0000', strtolower(file_get_contents($cm->vectorFile()->getPath())));
        $this->assertStringContainsString("<!--@{$color->id}:FF0000-->", $cm->vectorFile()->getCustomProperty('tokenized'));
        $old_url = $cm->vectorFile()->getFullUrl();

        $this->putJson("/color-groups/{$group->id}", ['label' => 'Cutie Mark', 'colors' => [['id' => $color->id, 'label' => 'Fill', 'hex' => '#0000FF']]])->assertOk();

        $file = CutieMark::first()->vectorFile();
        $content = strtolower(file_get_contents($file->getPath()));
        $this->assertStringContainsString('fill="#0000ff"', $content);
        $this->assertStringNotContainsString('#ff0000', $content);
        $this->assertStringContainsString('#00ff00', $content, 'colors the guide does not know are kept');
        $this->assertNotSame($old_url, $file->getFullUrl());
        $this->assertSame(1, CutieMark::first()->media()->count());
    }

    public function testCutieMarksWithoutAColorGroupAreStoredAsUploaded(): void
    {
        $this->as(Role::Staff);
        $appearance = $this->appearance();

        $this->putCms($appearance, [['svgdata' => $this->svg(), 'attribution' => 'none', 'rotation' => 0]])->assertOk();

        $this->assertNull(CutieMark::first()->vectorFile()->getCustomProperty('tokenized'));
    }

    public function testTheTokenizeCommandLinksExistingCutieMarks(): void
    {
        $this->as(Role::Staff);
        $appearance = $this->appearance();
        $this->putCms($appearance, [['svgdata' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10z" fill="#ff0000"/></svg>', 'attribution' => 'none', 'rotation' => 0]])->assertOk();
        $this->assertNull(CutieMark::first()->vectorFile()->getCustomProperty('tokenized'));
        $group = ColorGroup::create(['appearance_id' => $appearance->id, 'label' => 'Cutie Mark', 'order' => 0]);
        $color = Color::create(['group_id' => $group->id, 'label' => 'Fill', 'order' => 1, 'hex' => '#FF0000']);

        $this->artisan('cutiemarks:tokenize')->expectsOutput('Tokenized 1 cutie mark(s), 0 skipped (no Cutie Mark color group)')->assertSuccessful();

        $this->assertStringContainsString("<!--@{$color->id}:FF0000-->", CutieMark::first()->vectorFile()->getCustomProperty('tokenized'));
    }
}
