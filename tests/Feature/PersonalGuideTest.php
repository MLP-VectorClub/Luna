<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\UserPrefKey;
use App\Models\Appearance;
use App\Models\PcgPointGrant;
use App\Models\PcgSlotHistory;
use App\Models\User;
use App\Utils\UserPrefHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalGuideTest extends TestCase
{
    use RefreshDatabase;

    private function as(Role $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function testAppearancesHidePrivateOnesFromVisitors(): void
    {
        $owner = User::factory()->create(['role' => Role::User]);
        Appearance::create(['label' => 'Public', 'owner_id' => $owner->id, 'notes_src' => null, 'order' => 1]);
        Appearance::create(['label' => 'Secret', 'owner_id' => $owner->id, 'notes_src' => null, 'order' => 2, 'private' => true]);

        $guest = $this->getJson("/users/{$owner->id}/personal-guide/appearances")->assertOk()->assertJsonPath('canManage', false);
        $this->assertSame(['id', 'label', 'private'], array_keys($guest->json('appearances.1')));
        $this->assertArrayHasKey('colorGroups', $guest->json('appearances.0'));

        $this->actingAs($owner, 'sanctum');
        $this->getJson("/users/{$owner->id}/personal-guide/appearances")->assertJsonPath('canManage', true)->assertJsonStructure(['appearances' => [1 => ['colorGroups']]]);
        $this->getJson("/users/{$owner->id}/personal-guide/appearances?size=99")->assertJsonValidationErrors('size');
        $this->getJson('/users/987654/personal-guide/appearances')->assertNotFound();
    }

    public function testGuidesCanBeHiddenByTheirOwner(): void
    {
        $owner = User::factory()->create(['role' => Role::User]);
        UserPrefHelper::set($owner, UserPrefKey::Personal_PrivatePersonalGuide, true);

        $this->getJson("/users/{$owner->id}/personal-guide/appearances")->assertForbidden();
        $this->as(Role::Staff);
        $this->getJson("/users/{$owner->id}/personal-guide/appearances")->assertOk();
    }

    public function testPointHistoryIsBuiltOnDemandAndHidesGranters(): void
    {
        $staff = User::factory()->create(['role' => Role::Staff]);
        $user = User::factory()->create(['role' => Role::User]);
        PcgPointGrant::create(['receiver_id' => $user->id, 'sender_id' => $staff->id, 'amount' => 5, 'comment' => 'Nice work']);

        $this->getJson("/users/{$user->id}/personal-guide/point-history")->assertUnauthorized();
        $this->as(Role::Member);
        $this->getJson("/users/{$user->id}/personal-guide/point-history")->assertForbidden();

        $this->actingAs($user, 'sanctum');
        $own = $this->getJson("/users/{$user->id}/personal-guide/point-history")->assertOk()->assertJsonPath('pagination.itemsPerPage', 20);
        $types = array_column($own->json('entries'), 'changeType');
        $this->assertContains('free_trial', $types);
        $this->assertContains('manual_give', $types);
        $give = collect($own->json('entries'))->firstWhere('changeType', 'manual_give');
        $this->assertArrayNotHasKey('by', $give['data']);

        $this->actingAs($staff, 'sanctum');
        $give = collect($this->getJson("/users/{$user->id}/personal-guide/point-history")->json('entries'))->firstWhere('changeType', 'manual_give');
        $this->assertSame($staff->id, $give['data']['by']);
        $this->getJson('/users/987654/personal-guide/point-history')->assertNotFound();
        $this->getJson("/users/{$user->id}/personal-guide/point-history?size=500")->assertJsonValidationErrors('size');
    }

    public function testSlotsAndPoints(): void
    {
        $user = $this->as(Role::User);

        $this->getJson("/users/{$user->id}/personal-guide/slots")->assertNoContent();
        Appearance::create(['label' => 'Mine', 'owner_id' => $user->id, 'notes_src' => null]);
        $user->recalculatePcgSlotHistory();
        $this->getJson("/users/{$user->id}/personal-guide/slots")->assertStatus(409);
        $this->getJson("/users/{$user->id}/personal-guide/points")->assertForbidden();

        $this->as(Role::Staff);
        $this->getJson("/users/{$user->id}/personal-guide/points")->assertOk()->assertJsonPath('amount', -10);
        $this->postJson("/users/{$user->id}/personal-guide/points", ['amount' => 0])->assertJsonValidationErrors('amount');
        $this->postJson("/users/{$user->id}/personal-guide/points", ['amount' => 5])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("/users/{$user->id}/personal-guide/points", ['amount' => 10, 'comment' => 'Welcome'])->assertCreated();
        $this->assertSame(10, UserPrefHelper::get($user->fresh(), UserPrefKey::Pcg_Slots));
        $this->assertSame(1, PcgSlotHistory::where('user_id', $user->id)->where('change_type', 'manual_give')->count());

        $this->actingAs($user, 'sanctum');
        $this->getJson("/users/{$user->id}/personal-guide/slots")->assertNoContent();
    }

    public function testRecalculationNeedsADeveloperAndIsRepeatable(): void
    {
        $user = User::factory()->create(['role' => Role::User]);
        $this->as(Role::Staff);
        $this->postJson("/users/{$user->id}/personal-guide/point-history/recalculation")->assertForbidden();

        $this->as(Role::Developer);
        $this->postJson("/users/{$user->id}/personal-guide/point-history/recalculation")->assertNoContent();
        $this->postJson("/users/{$user->id}/personal-guide/point-history/recalculation")->assertNoContent();
        $this->assertSame(1, PcgSlotHistory::where('user_id', $user->id)->count());
        $this->assertSame(10, UserPrefHelper::get($user->fresh(), UserPrefKey::Pcg_Slots));
    }

    public function testSlotCountCannotBeSetThroughPreferences(): void
    {
        $user = $this->as(Role::User);
        $this->putJson("/users/{$user->id}/preferences/pcg_slots", ['value' => 99])->assertForbidden();
    }
}
