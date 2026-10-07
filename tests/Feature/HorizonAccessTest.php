<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    public function testOnlyDevelopersMayViewTheQueueDashboard(): void
    {
        $this->assertFalse(Gate::forUser(null)->allows('viewHorizon'));
        foreach ([Role::User, Role::Member, Role::Staff, Role::Admin] as $role) {
            $this->assertFalse(Gate::forUser(User::factory()->create(['role' => $role]))->allows('viewHorizon'), $role->name);
        }
        $this->assertTrue(Gate::forUser(User::factory()->create(['role' => Role::Developer]))->allows('viewHorizon'));
    }
}
