<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Models\Appearance;
use App\Models\ColorGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ColorGroupsTest extends TestCase
{
    use RefreshDatabase;

    private function official(): Appearance
    {
        return Appearance::create(['label' => 'Official', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => '']);
    }

    private function as(Role $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function testRequiresAuthenticationAndPermission(): void
    {
        $appearance = $this->official();
        $this->getJson('/color-groups/1')->assertUnauthorized();
        $this->postJson('/color-groups', ['appearanceId' => $appearance->id])->assertUnauthorized();

        $this->as(Role::User);
        $this->postJson('/color-groups', ['appearanceId' => $appearance->id, 'label' => 'Nope', 'colors' => [['label' => 'Color A']]])->assertForbidden();
        $this->postJson('/color-groups', ['appearanceId' => 987654, 'label' => 'Nope', 'colors' => [['label' => 'Color A']]])->assertNotFound();
    }

    public function testLifecycleWithRoundedHexAndLogs(): void
    {
        $appearance = $this->official();
        $this->as(Role::Staff);

        $id = $this->postJson('/color-groups', ['appearanceId' => $appearance->id, 'label' => 'Coat', 'colors' => json_encode([['label' => 'Base', 'hex' => 'fefefe'], ['label' => 'Shade']])])
            ->assertCreated()->json('id');
        $this->assertDatabaseHas('logs', ['entry_type' => 'cgs']);

        $this->getJson("/color-groups/$id")->assertOk()
            ->assertJsonPath('colors.0.hex', '#FFFFFF')
            ->assertJsonPath('colors.1.hex', null)
            ->assertJsonPath('order', 1);

        $colorId = $this->getJson("/color-groups/$id")->json('colors.0.id');
        $this->putJson("/color-groups/$id", ['label' => 'Coat 2', 'colors' => [['id' => $colorId, 'label' => 'Renamed', 'hex' => '#112233']]])->assertOk();
        $this->getJson("/color-groups/$id")->assertJsonCount(1, 'colors')->assertJsonPath('colors.0.hex', '#112233');
        $this->assertDatabaseHas('logs', ['entry_type' => 'cg_modify']);

        $this->deleteJson("/color-groups/$id")->assertNoContent();
        $this->getJson("/color-groups/$id")->assertNotFound();
    }

    public function testRejectedRequestsSaveNothing(): void
    {
        $appearance = $this->official();
        $this->as(Role::Staff);
        $base = ['appearanceId' => $appearance->id, 'label' => 'Rejected'];

        $this->postJson('/color-groups')->assertJsonValidationErrors('appearanceId');
        $this->postJson('/color-groups', ['appearanceId' => $appearance->id])->assertJsonValidationErrors('label');
        $this->postJson('/color-groups', $base)->assertJsonValidationErrors('colors');
        $this->postJson('/color-groups', $base + ['colors' => []])->assertJsonValidationErrors('colors');
        $this->postJson('/color-groups', $base + ['colors' => [['label' => 'Bad hex', 'hex' => 'nope']]])->assertJsonValidationErrors('colors');
        $this->postJson('/color-groups', $base + ['colors' => [['label' => 'ab']]])->assertJsonValidationErrors('colors');
        $this->postJson('/color-groups', $base + ['colors' => [['label' => 'Twin'], ['label' => 'Twin']]])->assertJsonValidationErrors('colors');
        $this->postJson('/color-groups', $base + ['major' => true, 'colors' => [['label' => 'Color A']]])->assertJsonValidationErrors('reason');

        $this->assertSame(0, ColorGroup::count());
    }

    public function testMajorChangeIsRecorded(): void
    {
        $appearance = $this->official();
        $user = $this->as(Role::Staff);

        $this->postJson('/color-groups', ['appearanceId' => $appearance->id, 'label' => 'Major', 'major' => true, 'reason' => 'Fixed the shade', 'colors' => [['label' => 'Color A']]])->assertCreated();
        $this->assertDatabaseHas('major_changes', ['appearance_id' => $appearance->id, 'reason' => 'Fixed the shade', 'user_id' => $user->id]);
    }

    public function testOwnersManageTheirPersonalAppearance(): void
    {
        $user = $this->as(Role::User);
        $personal = Appearance::create(['label' => 'Mine', 'owner_id' => $user->id, 'notes_src' => '']);

        $id = $this->postJson('/color-groups', ['appearanceId' => $personal->id, 'label' => 'Mine', 'colors' => [['label' => 'Color A', 'hex' => '#fefefe']]])->assertCreated()->json('id');
        // Hex values are only rounded for the official guide
        $this->getJson("/color-groups/$id")->assertJsonPath('colors.0.hex', '#FEFEFE');
        $this->deleteJson("/color-groups/$id")->assertNoContent();

        $this->as(Role::User);
        $this->postJson('/color-groups', ['appearanceId' => $personal->id, 'label' => 'Not mine', 'colors' => [['label' => 'Color A']]])->assertForbidden();
    }
}
