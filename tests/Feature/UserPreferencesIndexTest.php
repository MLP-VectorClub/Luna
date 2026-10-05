<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPreferencesIndexTest extends TestCase
{
    use RefreshDatabase;

    public function testOnlyTheUserAndStaffReadAllPreferences(): void
    {
        $user = User::factory()->create(['role' => Role::Member]);
        $this->getJson("/users/{$user->id}/preferences")->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => Role::Member]), 'sanctum');
        $this->getJson("/users/{$user->id}/preferences")->assertForbidden();

        $this->actingAs($user, 'sanctum');
        $own = $this->getJson("/users/{$user->id}/preferences")->assertOk();
        $this->assertArrayHasKey('cg_itemsperpage', $own->json());

        $this->actingAs(User::factory()->create(['role' => Role::Staff]), 'sanctum');
        $this->getJson("/users/{$user->id}/preferences")->assertOk()->assertJsonStructure(['cg_itemsperpage']);
        $this->getJson('/users/987654/preferences')->assertNotFound();
    }
}
