<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Models\Appearance;
use App\Models\CutieMark;
use App\Models\DeviantartUser;
use App\Models\Event;
use App\Models\EventEntry;
use App\Models\Post;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostsAndProfileTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role = Role::User, string $name = null): User
    {
        return User::factory()->create(['role' => $role] + ($name ? ['name' => $name] : []));
    }

    private function show(): Show
    {
        return Show::create(['type' => 'episode', 'season' => 1, 'episode' => 1, 'title' => 'Friendship is Magic', 'posted_by' => $this->user()->id, 'airs' => '2010-10-10 15:00', 'no' => 1]);
    }

    private function makePost(Show $show, array $attributes): Post
    {
        return Post::create($attributes + ['show_id' => $show->id, 'preview' => 'https://example.com/p.png', 'fullsize' => 'https://example.com/f.png', 'label' => 'A post']);
    }

    public function testPostListFiltersAndPermissions(): void
    {
        $show = $this->show();
        $requester = $this->user(Role::User, 'Requester');
        $admin = $this->user(Role::Staff);
        $open = $this->makePost($show, ['type' => 'chr', 'requested_by' => $requester->id, 'requested_at' => now()->subDay()]);
        $reserved = $this->makePost($show, ['type' => 'chr', 'requested_by' => $requester->id, 'requested_at' => now()->subDays(2), 'reserved_by' => $admin->id, 'reserved_at' => now()->subDay(), 'deviation_id' => 'dfin001', 'finished_at' => now()]);
        $broken = $this->makePost($show, ['type' => 'obj', 'requested_by' => $requester->id, 'requested_at' => now(), 'broken' => true]);
        $reservation = $this->makePost($show, ['reserved_by' => $admin->id, 'reserved_at' => now()]);

        $guest = $this->getJson("/posts?showId={$show->id}&kind=request")->assertOk();
        $posts = collect($guest->json('posts'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$open->id, $reserved->id], $posts->keys()->all());
        $this->assertSame('chr', $posts[$open->id]['type']);
        $this->assertSame(['id' => $requester->id, 'name' => 'Requester'], $posts[$open->id]['postedBy']);
        $this->assertNull($posts[$open->id]['reservedBy']);
        $this->assertSame('dfin001', $posts[$reserved->id]['deviationId']);
        $this->assertFalse($posts[$open->id]['canEdit']);

        $this->actingAs($requester, 'sanctum');
        $mine = collect($this->getJson("/posts?showId={$show->id}&kind=request")->json('posts'))->keyBy('id');
        $this->assertTrue($mine[$open->id]['canEdit']);
        $this->assertFalse($mine[$reserved->id]['canEdit']);

        $this->actingAs($admin, 'sanctum');
        $staff = collect($this->getJson("/posts?showId={$show->id}&kind=request")->json('posts'))->keyBy('id');
        $this->assertTrue($staff[$broken->id]['broken']);
        $this->assertTrue($staff[$reserved->id]['canEdit']);

        $reservations = $this->getJson("/posts?showId={$show->id}&kind=reservation")->assertOk()->json('posts');
        $this->assertSame([$reservation->id], array_column($reservations, 'id'));
        $this->assertArrayNotHasKey('type', $reservations[0]);
    }

    public function testPostListValidation(): void
    {
        $this->getJson('/posts?kind=request')->assertJsonValidationErrors('showId');
        $this->getJson('/posts?showId=1')->assertJsonValidationErrors('kind');
        $this->getJson('/posts?showId=1&kind=nope')->assertJsonValidationErrors('kind');
        $this->getJson('/posts?showId=987654&kind=request')->assertNotFound();
    }

    public function testOverdueRequests(): void
    {
        $show = $this->show();
        $reserver = $this->user();
        $post = $this->makePost($show, ['type' => 'chr', 'requested_by' => $this->user()->id, 'requested_at' => now()->subMonths(2), 'reserved_by' => $reserver->id, 'reserved_at' => now()->subWeeks(4)]);

        $this->assertTrue($this->getJson("/posts?showId={$show->id}&kind=request")->json("posts.0.overdue"));
        $post->update(['reserved_at' => now()->subWeek()]);
        $this->assertFalse($this->getJson("/posts?showId={$show->id}&kind=request")->json("posts.0.overdue"));
    }

    public function testProfile(): void
    {
        $show = $this->show();
        $user = $this->user(Role::Member, 'Profile');
        $requester = $this->user();
        $pending = $this->makePost($show, ['type' => 'chr', 'requested_by' => $requester->id, 'requested_at' => now(), 'reserved_by' => $user->id, 'reserved_at' => now(), 'deviation_id' => 'dpend01', 'finished_at' => now()]);
        Appearance::create(['label' => 'Mine', 'owner_id' => $user->id, 'notes_src' => null]);
        Appearance::create(['label' => 'Secret', 'owner_id' => $user->id, 'notes_src' => null, 'private' => true]);

        $guest = $this->getJson("/users/{$user->id}/profile")->assertOk()
            ->assertJsonPath('user.name', 'Profile')
            ->assertJsonPath('sameUser', false)
            ->assertJsonPath('canEdit', false)
            ->assertJsonPath('editableRoles', null)
            ->assertJsonPath('previousUsernames', null)
            ->assertJsonPath('awaitingApproval.0.id', $pending->id);
        $guides = collect($guest->json('personalGuides'))->keyBy('label');
        $this->assertSame([], $guides['Secret']['previewData']);
        $this->assertTrue($guides['Secret']['private']);
        $this->assertEqualsCanonicalizing(['finished-posts'], array_column($guest->json('contributions'), 'type'));

        $this->actingAs($user, 'sanctum');
        $this->getJson("/users/{$user->id}/profile")->assertJsonPath('sameUser', true)->assertJsonPath('canEdit', false)->assertJsonPath('previousUsernames', null);

        $this->actingAs($this->user(Role::Admin), 'sanctum');
        $staff = $this->getJson("/users/{$user->id}/profile")->assertJsonPath('canEdit', true);
        $this->assertArrayHasKey('member', $staff->json('editableRoles'));
        $this->assertArrayNotHasKey('guest', $staff->json('editableRoles'));
        $this->assertArrayNotHasKey('developer', $staff->json('editableRoles'));
        $this->getJson('/users/987654/profile')->assertNotFound();
    }

    public function testProfileHasTheDataOfThePendingReservationsAndTheSlotProgress(): void
    {
        $show = $this->show();
        $member = $this->user(Role::Member, 'Pending Member');
        $reserved = $this->makePost($show, ['reserved_by' => $member->id, 'reserved_at' => now(), 'requested_by' => $this->user()->id, 'requested_at' => now(), 'type' => 'chr']);
        $this->makePost($show, ['reserved_by' => $member->id, 'reserved_at' => now(), 'deviation_id' => 'dfin001', 'finished_at' => now()]);
        DB::table('locked_posts')->insert(['post_id' => $reserved->id, 'user_id' => $member->id, 'created_at' => now(), 'updated_at' => now()]);

        // Nobody else sees the reservations or the progress
        $guest = $this->getJson("/users/{$member->id}/profile")->assertOk();
        $guest->assertJsonPath('pendingReservations', null)->assertJsonPath('personalGuideProgress', null);
        $this->assertContains('approved-posts', collect($guest->json('contributions'))->pluck('type')->all());

        $this->actingAs($member, 'sanctum');
        $own = $this->getJson("/users/{$member->id}/profile")->assertOk();
        $this->assertSame([$reserved->id], collect($own->json('pendingReservations'))->pluck('id')->all());
        $this->assertSame($show->id, $own->json('pendingReservations.0.show.id'));
        $this->assertSame(1, $own->json('personalGuideProgress.slots'));
        $this->assertSame(10, $own->json('personalGuideProgress.requestsToNext'));
    }

    public function testNonMembersHaveNoAwaitingApprovalList(): void
    {
        $user = $this->user();
        $this->getJson("/users/{$user->id}/profile")->assertOk()->assertJsonPath('awaitingApproval', null);
    }

    public function testContributions(): void
    {
        $show = $this->show();
        $user = $this->user(Role::Member);
        $other = $this->user(Role::User);
        $this->makePost($show, ['type' => 'chr', 'requested_by' => $user->id, 'requested_at' => now()]);
        $this->makePost($show, ['reserved_by' => $user->id, 'reserved_at' => now()]);
        $this->makePost($show, ['type' => 'chr', 'requested_by' => $other->id, 'requested_at' => now(), 'reserved_by' => $user->id, 'reserved_at' => now(), 'deviation_id' => 'dfin001', 'finished_at' => now(), 'lock' => true]);

        $reservations = $this->getJson("/users/{$user->id}/contributions/reservations")->assertOk()->assertJsonPath('type', 'reservations')->assertJsonPath('pagination.itemsPerPage', 10);
        $this->assertCount(1, $reservations->json('items'));
        $this->getJson("/users/{$user->id}/contributions/finished-posts")->assertJsonPath('pagination.totalItems', 1);
        $this->getJson("/users/{$user->id}/contributions/fulfilled-requests")->assertJsonPath('items.0.approved', true);

        $this->getJson("/users/{$user->id}/contributions/requests")->assertUnauthorized();
        $this->actingAs($other, 'sanctum');
        $this->getJson("/users/{$user->id}/contributions/requests")->assertForbidden();
        $this->actingAs($user, 'sanctum');
        $this->getJson("/users/{$user->id}/contributions/requests")->assertOk()->assertJsonPath('pagination.totalItems', 1);

        $this->getJson("/users/{$user->id}/contributions/reservations?size=99")->assertJsonValidationErrors('size');
        $this->getJson("/users/{$user->id}/contributions/reservations?page=0")->assertJsonValidationErrors('page');
        $this->getJson("/users/{$user->id}/contributions/nonsense")->assertNotFound();
        $this->getJson("/users/987654/contributions/reservations")->assertNotFound();
    }

    public function testCutieMarkContributions(): void
    {
        $user = $this->user();
        $da = new DeviantartUser(['name' => 'Contributor', 'avatar_url' => 'https://example.com/a.png']);
        $da->forceFill(['id' => '0f0e0d0c-0b0a-4000-8000-000000000001', 'user_id' => $user->id])->save();
        $appearance = Appearance::create(['label' => 'Twilight', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => null, 'order' => 1]);
        DB::table('cutiemarks')->insert(['appearance_id' => $appearance->id, 'contributor_id' => $da->id, 'favme' => 'dabc123', 'rotation' => 0]);

        $this->getJson("/users/{$user->id}/contributions/cms-provided")->assertOk()
            ->assertJsonPath('items.0.favMe', 'dabc123')
            ->assertJsonPath('items.0.appearance.label', 'Twilight');
        $this->assertSame('cms-provided', $this->getJson("/users/{$user->id}/profile")->json('contributions.0.type'));
    }

    public function testEvents(): void
    {
        $creator = $this->user(Role::Staff, 'Creator');
        $event = Event::create(['name' => 'Contest', 'starts_at' => now()->subWeek(), 'ends_at' => now()->subDay(), 'entry_role' => 'user', 'desc_src' => 'Rules', 'desc_rend' => '<p>Rules</p>', 'added_by' => $creator->id]);
        EventEntry::create(['event_id' => $event->id, 'title' => 'Entry', 'sub_prov' => 'fav.me', 'sub_id' => 'dabc123', 'submitted_by' => $creator->id, 'prev_thumb' => 'https://example.com/t.png']);

        $this->getJson('/events')->assertOk()->assertJsonPath('events.0.name', 'Contest')->assertJsonPath('pagination.itemsPerPage', 20);
        $this->getJson('/events?size=99')->assertJsonValidationErrors('size');
        $this->getJson("/events/{$event->id}")->assertOk()
            ->assertJsonPath('addedBy.name', 'Creator')
            ->assertJsonPath('entries.0.submissionId', 'dabc123')
            ->assertJsonPath('canEnter', false)
            ->assertJsonPath('ended', true);
        $this->getJson('/events/987654')->assertNotFound();
    }

    public function testDisabledEventWrites(): void
    {
        $this->postJson('/events')->assertUnauthorized();
        $this->actingAs($this->user(), 'sanctum');
        $this->postJson('/events')->assertForbidden();
        $this->postJson('/events/1/entries/check')->assertStatus(501);

        $this->actingAs($this->user(Role::Staff), 'sanctum');
        foreach ([['POST', '/events'], ['PUT', '/events/1'], ['DELETE', '/events/1'], ['POST', '/events/1/finalize']] as [$method, $path]) {
            $this->json($method, $path)->assertStatus(501)->assertJsonStructure(['message']);
        }
    }
}
