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

        // The old filter box
        $this->getJson('/admin/logs')->assertJsonPath('entryTypes.rolechange', 'User group change');
        $this->getJson('/admin/logs?by=me')->assertJsonCount(1, 'entries')->assertJsonPath('entries.0.type', 'rolechange');
        $this->getJson('/admin/logs?by='.strtoupper($admin->name))->assertJsonCount(1, 'entries');
        $this->getJson('/admin/logs?by=Web%20server')->assertJsonPath('entries.0.type', 'cgs')->assertJsonCount(1, 'entries');
        $this->getJson('/admin/logs?by=127.0.0.1')->assertJsonCount(2, 'entries');
        $this->getJson('/admin/logs?by=10.9.8.7')->assertJsonCount(0, 'entries');
        $this->getJson('/admin/logs?by=nobody-by-that-name')->assertJsonCount(0, 'entries');

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

    public function testReindexNeedsADeveloper(): void
    {
        $this->postJson('/color-guide/reindex')->assertUnauthorized();
        $this->as(Role::Staff);
        $this->postJson('/color-guide/reindex')->assertForbidden();
    }

    public function testExport(): void
    {
        Appearance::create(['label' => 'Twilight', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => 'Notes', 'order' => 1]);
        Appearance::create(['label' => 'Personal', 'owner_id' => User::factory()->create()->id, 'notes_src' => null]);
        // The old site published this file for everybody (other tools read it)
        $response = $this->getJson('/color-guide/export')->assertOk();
        $this->assertStringEndsWith('/dist/mlpvc-colorguide-schema.json?v1.1', $response->json('$schema'));
        $this->assertCount(1, $response->json('Appearances'));
        $this->assertSame('Twilight', array_values($response->json('Appearances'))[0]['label']);
    }

    public function testRecentPostsAreForStaff(): void
    {
        $this->getJson('/admin/posts/recent')->assertUnauthorized();
        $this->as(Role::Member);
        $this->getJson('/admin/posts/recent')->assertForbidden();

        $user = $this->as(Role::Staff);
        $show = \App\Models\Show::create(['type' => 'episode', 'season' => 1, 'episode' => 1, 'title' => 'First', 'posted_by' => $user->id, 'airs' => '2010-10-10 15:00', 'no' => 1]);
        foreach (range(1, 22) as $i) {
            \App\Models\Post::create(['show_id' => $show->id, 'requested_by' => $user->id, 'requested_at' => now()->subMinutes(30 - $i), 'type' => 'chr', 'preview' => 'https://example.com/p.png', 'fullsize' => 'https://example.com/f.png', 'label' => "Post $i"]);
        }

        $posts = $this->getJson('/admin/posts/recent')->assertOk()->json('posts');
        $this->assertCount(20, $posts);
        $this->assertSame('Post 22', $posts[0]['label']);
        $this->assertSame($show->id, $posts[0]['show']['id']);
    }

    public function testSearchStatusIsForDevelopers(): void
    {
        $this->getJson('/admin/search-status')->assertUnauthorized();
        $this->as(Role::Staff);
        $this->getJson('/admin/search-status')->assertForbidden();
        $this->as(Role::Developer);
        $this->getJson('/admin/search-status')->assertOk()->assertJsonStructure(['down', 'indices', 'nodes']);
    }
}
