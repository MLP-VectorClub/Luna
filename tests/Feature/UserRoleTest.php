<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\PcgSlotHistory;
use App\Models\User;
use App\Utils\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function testPermissions(): void
    {
        $target = $this->user(Role::User);
        $this->putJson("/users/{$target->id}/role", ['value' => 'member'])->assertUnauthorized();

        $this->actingAs($this->user(Role::Member), 'sanctum');
        $this->putJson("/users/{$target->id}/role", ['value' => 'member'])->assertForbidden();

        $admin = $this->user(Role::Admin);
        $this->actingAs($admin, 'sanctum');
        $this->putJson('/users/987654/role', ['value' => 'member'])->assertNotFound();
        $this->putJson("/users/{$admin->id}/role", ['value' => 'user'])->assertForbidden();
        $this->putJson("/users/{$this->user(Role::Developer)->id}/role", ['value' => 'user'])->assertForbidden();
        $this->putJson("/users/{$target->id}/role", ['value' => 'nonsense'])->assertJsonValidationErrors('value');
        $this->putJson("/users/{$target->id}/role")->assertJsonValidationErrors('value');
    }

    public function testChangingRolesIsLoggedAndGrantsStaffSlots(): void
    {
        $target = $this->user(Role::User);
        $this->actingAs($this->user(Role::Admin), 'sanctum');

        $this->putJson("/users/{$target->id}/role", ['value' => 'user'])->assertOk()->assertExactJson(['alreadyIn' => true]);
        $this->putJson("/users/{$target->id}/role", ['value' => 'member'])->assertNoContent();
        $this->assertSame(Role::Member, $target->fresh()->role);
        $this->assertDatabaseHas('logs', ['entry_type' => 'rolechange']);

        $this->putJson("/users/{$target->id}/role", ['value' => 'staff'])->assertNoContent();
        $this->assertSame('staff_join', PcgSlotHistory::where('user_id', $target->id)->value('change_type'));
        $this->putJson("/users/{$target->id}/role", ['value' => 'user'])->assertNoContent();
        $this->assertSame(['staff_join', 'staff_leave'], PcgSlotHistory::where('user_id', $target->id)->orderBy('id')->pluck('change_type')->all());
    }

    public function testChangingADevelopersRoleChangesTheLabel(): void
    {
        $developer = $this->user(Role::Developer);
        $other = $this->user(Role::Developer);
        $this->actingAs($developer, 'sanctum');

        $this->putJson("/users/{$other->id}/role", ['value' => 'developer'])->assertOk()->assertExactJson(['alreadyIn' => true]);
        $this->putJson("/users/{$other->id}/role", ['value' => 'staff'])->assertNoContent();
        $this->assertSame(Role::Developer, $other->fresh()->role);
        $this->assertSame('staff', SettingsHelper::get('dev_role_label'));
    }
}
