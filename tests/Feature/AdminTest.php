<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Models\Appearance;
use App\Models\Log;
use App\Models\Notification;
use App\Models\User;
use Elasticsearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function as(Role $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function testLogsAreStaffOnly(): void
    {
        $this->getJson('/admin/logs')->assertUnauthorized();
        $this->getJson('/admin/logs/1')->assertUnauthorized();
        $this->as(Role::Member);
        $this->getJson('/admin/logs')->assertForbidden();
        $this->getJson('/admin/logs/1')->assertForbidden();
    }

    public function testLogListFiltersAndPaginates(): void
    {
        $admin = $this->as(Role::Staff);
        Log::create(['entry_type' => 'rolechange', 'initiator' => $admin->id, 'ip' => '127.0.0.1', 'data' => ['target' => 1, 'oldrole' => 'user', 'newrole' => 'member']]);
        Log::create(['entry_type' => 'cgs', 'initiator' => null, 'ip' => '127.0.0.1', 'data' => null]);

        $this->getJson('/admin/logs?size=5')->assertOk()
            ->assertJsonPath('pagination.itemsPerPage', 5)
            ->assertJsonPath('pagination.totalItems', 2)
            ->assertJsonPath('entries.0.type', 'cgs')
            ->assertJsonPath('entries.0.initiator', null)
            ->assertJsonPath('entries.0.hasDetails', false)
            ->assertJsonPath('entries.1.initiator.name', $admin->name)
            ->assertJsonPath('entries.1.typeLabel', 'User group change');
        $this->getJson('/admin/logs?type=rolechange')->assertJsonCount(1, 'entries');
        $this->getJson("/admin/logs?initiatorId={$admin->id}")->assertJsonCount(1, 'entries');
        $this->getJson('/admin/logs?initiatorId=0')->assertJsonPath('entries.0.type', 'cgs');

        $this->getJson('/admin/logs?type=nonsense')->assertJsonValidationErrors('type');
        $this->getJson('/admin/logs?initiatorId=x')->assertJsonValidationErrors('initiatorId');
        $this->getJson('/admin/logs?size=500')->assertJsonValidationErrors('size');
        $this->getJson('/admin/logs?page=0')->assertJsonValidationErrors('page');
    }

    public function testLogDetail(): void
    {
        $this->as(Role::Staff);
        $with = Log::create(['entry_type' => 'rolechange', 'ip' => '127.0.0.1', 'data' => ['target' => 5]]);
        $without = Log::create(['entry_type' => 'cgs', 'ip' => '127.0.0.1']);

        $this->getJson("/admin/logs/{$with->id}")->assertOk()->assertJsonPath('data.target', 5)->assertJsonPath('details', []);
        $this->getJson("/admin/logs/{$without->id}")->assertStatus(409)->assertJsonPath('unclickable', true);
        $this->getJson('/admin/logs/987654')->assertNotFound();
    }

    public function testMarkingNotificationsAsRead(): void
    {
        $user = $this->as(Role::User);
        $other = User::factory()->create();
        $mine = Notification::create(['recipient_id' => $user->id, 'type' => 'post-approved', 'data' => []]);
        $theirs = Notification::create(['recipient_id' => $other->id, 'type' => 'post-approved', 'data' => []]);

        $this->postJson("/notifications/{$mine->id}/read")->assertNoContent();
        $this->assertNotNull($mine->fresh()->read_at);
        $this->postJson("/notifications/{$theirs->id}/read")->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at);
        $this->postJson('/notifications/987654/read')->assertNotFound();
    }

    public function testReindexAndExportNeedADeveloper(): void
    {
        $this->postJson('/color-guide/reindex')->assertUnauthorized();
        $this->as(Role::Staff);
        $this->postJson('/color-guide/reindex')->assertForbidden();
        $this->getJson('/color-guide/export')->assertForbidden();
    }

    public function testExport(): void
    {
        Appearance::create(['label' => 'Twilight', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => 'Notes', 'order' => 1]);
        Appearance::create(['label' => 'Personal', 'owner_id' => User::factory()->create()->id, 'notes_src' => null]);
        $this->as(Role::Developer);

        $response = $this->getJson('/color-guide/export')->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename="mlpvc-colorguide.json"');
        $this->assertCount(1, $response->json('Appearances'));
        $this->assertSame('Twilight', array_values($response->json('Appearances'))[0]['label']);
    }
}
