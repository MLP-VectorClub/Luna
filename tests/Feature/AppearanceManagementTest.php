<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Enums\UserPrefKey;
use App\Models\Appearance;
use App\Models\PinnedAppearance;
use App\Models\Show;
use App\Models\Tag;
use App\Models\User;
use App\Utils\UserPrefHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppearanceManagementTest extends TestCase
{
    use RefreshDatabase;

    private function as(Role $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function official(string $label = 'Official'): Appearance
    {
        return Appearance::create(['label' => $label, 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => null, 'order' => 1]);
    }

    public function testAuthenticationAndPermissions(): void
    {
        $appearance = $this->official();
        foreach ([['GET', 'metadata'], ['PUT', ''], ['DELETE', ''], ['POST', 'pin'], ['GET', 'shows'], ['POST', 'sprite']] as [$method, $suffix]) {
            $this->json($method, "/appearances/{$appearance->id}".($suffix ? "/$suffix" : ''))->assertUnauthorized();
        }

        $this->as(Role::User);
        foreach ([['GET', 'metadata'], ['PUT', ''], ['DELETE', ''], ['POST', 'pin'], ['GET', 'shows']] as [$method, $suffix]) {
            $this->json($method, "/appearances/{$appearance->id}".($suffix ? "/$suffix" : ''))->assertForbidden();
        }
        $this->postJson('/appearances', ['guide' => 'pony', 'label' => 'Not allowed'])->assertForbidden();
        $this->getJson('/appearances/987654/metadata')->assertNotFound();
    }

    public function testOfficialLifecycle(): void
    {
        $this->as(Role::Staff);

        $id = $this->postJson('/appearances', ['guide' => 'pony', 'label' => 'Contract Pony', 'template' => true])->assertCreated()->json('id');
        $this->assertDatabaseHas('logs', ['entry_type' => 'appearances']);
        $this->assertDatabaseHas('color_groups', ['appearance_id' => $id, 'label' => 'Coat']);
        $this->postJson('/appearances', ['guide' => 'pony', 'label' => 'Contract Pony'])->assertJsonValidationErrors('label');
        $this->assertSame(1, Appearance::find($id)->order);

        $this->getJson("/appearances/$id/metadata")->assertOk()->assertExactJson(['label' => 'Contract Pony', 'notes' => null, 'private' => false]);
        $this->putJson("/appearances/$id", ['label' => 'Contract Pony II', 'notes' => 'Some notes >>123'])->assertOk()->assertJsonPath('label', 'Contract Pony II');
        $this->getJson("/appearances/$id")->assertJsonPath('notes', fn($notes) => str_contains($notes, 'derpibooru.org/123'));
        $this->assertDatabaseHas('logs', ['entry_type' => 'appearance_modify']);

        $this->deleteJson("/appearances/$id")->assertNoContent();
        $this->getJson("/appearances/$id/metadata")->assertNotFound();
    }

    public function testValidation(): void
    {
        $this->as(Role::Staff);

        $this->postJson('/appearances', ['guide' => 'pony'])->assertJsonValidationErrors('label');
        $this->postJson('/appearances', ['guide' => 'nonsense', 'label' => 'Valid label'])->assertJsonValidationErrors('guide');
        $this->postJson('/appearances', ['guide' => 'pony', 'label' => 'x'])->assertJsonValidationErrors('label');
        $this->postJson('/appearances', ['guide' => 'pony', 'label' => 'Valid label', 'notes' => str_repeat('a', 1001)])->assertJsonValidationErrors('notes');
    }

    public function testPinning(): void
    {
        $appearance = $this->official();
        $personal = Appearance::create(['label' => 'Mine', 'owner_id' => User::factory()->create()->id, 'notes_src' => null]);
        $this->as(Role::Staff);

        $this->postJson("/appearances/{$appearance->id}/pin")->assertOk()->assertJsonStructure(['message']);
        $this->assertSame(1, PinnedAppearance::count());
        $this->deleteJson("/appearances/{$appearance->id}")->assertStatus(409);
        $this->deleteJson("/appearances/{$appearance->id}/pin")->assertOk();
        $this->assertSame(0, PinnedAppearance::count());
        $this->postJson("/appearances/{$personal->id}/pin")->assertStatus(409);
    }

    public function testPersonalGuideCreationNeedsSlots(): void
    {
        $user = $this->as(Role::User);

        $id = $this->postJson('/appearances', ['label' => 'My pony'])->assertCreated()->json('id');
        $this->assertSame($user->id, Appearance::find($id)->owner_id);
        $this->assertSame(0, UserPrefHelper::get($user->fresh(), UserPrefKey::Pcg_Slots));

        // The free trial slots are used up now
        $this->postJson('/appearances', ['label' => 'Another pony'])->assertStatus(409);

        $this->deleteJson("/appearances/$id")->assertNoContent();
        $this->assertSame(10, UserPrefHelper::get($user->fresh(), UserPrefKey::Pcg_Slots));
    }

    public function testPersonalGuideCreationCanBeSwitchedOff(): void
    {
        $user = $this->as(Role::User);
        UserPrefHelper::set($user, UserPrefKey::Admin_CanMakePcgAppearances, false);

        $this->postJson('/appearances', ['label' => 'My pony'])->assertForbidden();
    }

    public function testColorGroupOrderAndRelationsAndTagsAndShows(): void
    {
        $appearance = $this->official('Main');
        $other = $this->official('Other');
        $this->as(Role::Staff);

        $this->getJson("/appearances/{$appearance->id}/color-groups/order")->assertStatus(409);
        foreach (['Coat', 'Mane'] as $label) {
            $this->postJson('/color-groups', ['appearanceId' => $appearance->id, 'label' => $label, 'colors' => [['label' => 'Color A']]]);
        }
        $groups = $this->getJson("/appearances/{$appearance->id}/color-groups/order")->assertOk()->json('cgs');
        $this->putJson("/appearances/{$appearance->id}/color-groups/order", ['cgs' => array_reverse(array_column($groups, 'id'))])->assertOk();
        $this->getJson("/appearances/{$appearance->id}/color-groups/order")->assertJsonPath('cgs.0.label', 'Mane');
        $this->putJson("/appearances/{$appearance->id}/color-groups/order", ['cgs' => [987654]])->assertJsonValidationErrors('cgs');

        $this->putJson("/appearances/{$appearance->id}/relations", ['ids' => [$other->id], 'mutuals' => [$other->id]])->assertOk();
        $this->getJson("/appearances/{$appearance->id}/relations")->assertOk()->assertJsonPath('linked.0.mutual', true)->assertJsonPath('unlinked', []);
        $this->putJson("/appearances/{$appearance->id}/relations", ['ids' => []])->assertOk();
        $this->assertDatabaseCount('related_appearances', 0);

        $this->putJson("/appearances/{$appearance->id}/tags", ['tags' => 'blue coat, new tag'])->assertNoContent();
        $this->getJson("/appearances/{$appearance->id}/tags")->assertJsonPath('tags', 'blue coat, new tag');
        $this->putJson("/appearances/{$appearance->id}/tags", ['origTags' => 'blue coat, new tag', 'tags' => 'new tag'])->assertNoContent();
        $this->assertSame(['new tag'], $appearance->tags()->pluck('name')->all());
        $this->assertSame(1, Tag::where('name', 'new tag')->value('uses'));
        $this->putJson("/appearances/{$appearance->id}/tags", ['tags' => '-bad'])->assertJsonValidationErrors('tags');

        $show = Show::create(['type' => 'episode', 'season' => 1, 'episode' => 1, 'title' => 'Pilot', 'posted_by' => User::first()->id, 'no' => 1]);
        $this->putJson("/appearances/{$appearance->id}/shows", ['ids' => [$show->id]])->assertOk();
        $this->getJson("/appearances/{$appearance->id}/shows")->assertJsonPath('linkedIds', [$show->id])->assertJsonPath('groups.episode', 'Episode');

        $this->putJson('/appearances/order', ['guide' => 'pony', 'list' => [$other->id, $appearance->id]])->assertOk();
        $this->assertSame(1, $other->fresh()->order);
    }

    public function testPersonalAppearancesHaveNoTagsOrRelations(): void
    {
        $user = $this->as(Role::User);
        $personal = Appearance::create(['label' => 'Mine', 'owner_id' => $user->id, 'notes_src' => null]);

        $this->getJson("/appearances/{$personal->id}/metadata")->assertOk();
        $this->getJson("/appearances/{$personal->id}/tags")->assertStatus(409);
        $this->getJson("/appearances/{$personal->id}/relations")->assertStatus(409);
    }

    public function testSelectiveClear(): void
    {
        $appearance = $this->official();
        $appearance->update(['notes_src' => 'Notes']);
        $this->as(Role::Staff);
        $this->postJson('/color-groups', ['appearanceId' => $appearance->id, 'label' => 'Coat', 'colors' => [['label' => 'Color A', 'hex' => '#112233']]]);

        $this->deleteJson("/appearances/{$appearance->id}/contents", ['wipeColors' => 'color_hex', 'wipeNotes' => true, 'mkpriv' => true])->assertNoContent();
        $this->assertDatabaseHas('colors', ['label' => 'Color A', 'hex' => null]);
        $fresh = $appearance->fresh();
        $this->assertNull($fresh->notes_src);
        $this->assertTrue((bool) $fresh->private);

        $this->deleteJson("/appearances/{$appearance->id}/contents", ['wipeColors' => 'all'])->assertNoContent();
        $this->assertDatabaseCount('color_groups', 0);
    }

    public function testSpriteUploadAndRemoval(): void
    {
        Storage::fake('public');
        $appearance = $this->official();
        $this->as(Role::Staff);
        $png = fn(int $w, int $h) => UploadedFile::fake()->image('sprite.png', $w, $h);

        $this->postJson("/appearances/{$appearance->id}/sprite", ['sprite' => UploadedFile::fake()->create('x.txt', 1, 'text/plain')])->assertJsonValidationErrors('file');
        $this->postJson("/appearances/{$appearance->id}/sprite", ['sprite' => $png(1, 1)])->assertJsonValidationErrors('file');
        $this->postJson("/appearances/{$appearance->id}/sprite", ['sprite' => $png(300, 300)])->assertOk()->assertJsonStructure(['path']);
        $this->assertTrue($appearance->fresh()->hasSprite());

        $this->deleteJson("/appearances/{$appearance->id}/sprite")->assertOk();
        $this->deleteJson("/appearances/{$appearance->id}/sprite")->assertNotFound();
    }
}
