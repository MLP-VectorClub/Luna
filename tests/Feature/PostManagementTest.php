<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\UserPrefKey;
use App\Models\BrokenPost;
use App\Models\DeviantartUser;
use App\Models\Notification;
use App\Models\PcgSlotHistory;
use App\Models\Post;
use App\Models\Show;
use App\Models\User;
use App\Utils\UserPrefHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostManagementTest extends TestCase
{
    use RefreshDatabase;

    private Show $show;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->show = Show::create(['type' => 'episode', 'season' => 1, 'episode' => 1, 'title' => 'Friendship is Magic', 'posted_by' => User::factory()->create(['role' => Role::User])->id, 'airs' => '2010-10-10 15:00', 'no' => 1]);
    }

    private function as(Role $role, ?string $name = null): User
    {
        $user = User::factory()->create(['role' => $role] + ($name ? ['name' => $name] : []));
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function request(User $requester, array $attributes = []): Post
    {
        return Post::create($attributes + ['type' => 'chr', 'requested_by' => $requester->id, 'requested_at' => now(), 'show_id' => $this->show->id, 'preview' => 'https://img.example/p.png', 'fullsize' => 'https://img.example/f.png', 'label' => 'A request']);
    }

    /** Fakes DeviantArt (oEmbed, image hosts, club gallery) for the given deviation ids */
    private function resetHttp(): void
    {
        app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
    }

    private function fakeDeviations(array $authors, array $club = []): void
    {
        $this->resetHttp();
        Http::fake(function ($request) use ($authors, $club) {
            $url = $request->url();
            if (str_contains($url, 'cloudflare.com')) {
                return Http::response('');
            }
            if (preg_match('~^http://fav\.me/(\w+)$~', $url, $match)) {
                return Http::response('', 301, ['Location' => "https://www.deviantart.com/someone/art/$match[1]"]);
            }
            if (str_contains($url, 'backend.deviantart.com/oembed')) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $id = basename($query['url']);

                return isset($authors[$id])
                    ? Http::response(['title' => "Deviation $id", 'type' => 'photo', 'url' => "https://img.example/$id-full.png", 'thumbnail_url' => "https://img.example/$id-thumb.png", 'author_name' => $authors[$id], 'imagetype' => 'png'])
                    : Http::response([], 404);
            }
            if (str_contains($url, 'deviantart.com/global/difi')) {
                $in_club = false;
                foreach ($club as $id) {
                    $in_club = $in_club || str_contains(urldecode($url), '"'.intval(substr($id, 1), 36).'"');
                }

                return Http::response(['DiFi' => ['status' => 'SUCCESS', 'response' => ['calls' => [['response' => ['status' => 'SUCCESS', 'content' => ['html' => $in_club ? '<span gmi-groupname="MLP-VectorClub">' : '<span>']]]]]]]);
            }

            return Http::response('', 200, ['Content-Type' => 'image/png']);
        });
    }

    private function member(string $name): User
    {
        $user = User::factory()->create(['role' => Role::Member, 'name' => $name]);
        (new DeviantartUser(['name' => $name, 'avatar_url' => 'https://img.example/a.png']))->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id])->save();

        return $user;
    }

    public function testEveryWriteRequiresAuthenticationAndMembershipWhereNeeded(): void
    {
        $post = $this->request(User::factory()->create(['role' => Role::User]));
        $endpoints = [['POST', '/posts'], ['PUT', "/posts/{$post->id}"], ['GET', "/posts/{$post->id}"], ['POST', "/posts/{$post->id}/reservation"], ['DELETE', "/posts/{$post->id}/reservation"],
            ['POST', "/posts/{$post->id}/approval"], ['DELETE', "/posts/{$post->id}/approval"], ['PUT', "/posts/{$post->id}/finish"], ['DELETE', "/posts/{$post->id}/finish"],
            ['DELETE', "/posts/requests/{$post->id}"], ['PUT', "/posts/{$post->id}/image"], ['POST', '/posts/check-image'], ['POST', '/posts/reservations']];
        foreach ($endpoints as [$method, $path]) {
            $this->json($method, $path)->assertUnauthorized();
        }

        $this->as(Role::User);
        foreach ([['POST', "/posts/{$post->id}/reservation"], ['POST', "/posts/{$post->id}/approval"], ['PUT', "/posts/{$post->id}/finish"], ['POST', '/posts/reservations'], ['POST', "/posts/{$post->id}/unbreak"]] as [$method, $path]) {
            $this->json($method, $path)->assertForbidden();
        }
        $this->getJson('/posts/987654')->assertNotFound();
    }

    public function testEditingARequest(): void
    {
        $user = $this->as(Role::User);
        $post = $this->request($user, ['label' => 'Seeded Test Request']);

        $this->getJson("/posts/{$post->id}")->assertOk()->assertExactJson(['label' => 'Seeded Test Request', 'type' => 'chr']);
        $this->putJson("/posts/{$post->id}", ['label' => 'Edited', 'type' => 'obj'])->assertNoContent();
        $this->getJson("/posts/{$post->id}")->assertJsonPath('label', 'Edited')->assertJsonPath('type', 'obj');
        $this->putJson("/posts/{$post->id}", ['label' => 'Edited', 'type' => 'obj'])->assertNoContent();
        $this->putJson("/posts/{$post->id}", ['label' => 'ab'])->assertJsonValidationErrors('label');
        $this->putJson("/posts/{$post->id}", ['label' => 'Fine label', 'type' => 'nonsense'])->assertJsonValidationErrors('type');

        $post->update(['reserved_by' => User::factory()->create(['role' => Role::Member])->id, 'reserved_at' => now()]);
        $this->putJson("/posts/{$post->id}", ['label' => 'Hijacked'])->assertForbidden();
    }

    public function testReservingAndUnreserving(): void
    {
        $post = $this->request(User::factory()->create(['role' => Role::User]));
        $member = $this->as(Role::Member);

        $this->postJson("/posts/{$post->id}/reservation")->assertOk()->assertJsonPath('post.reservedBy.id', $member->id);
        $this->postJson("/posts/{$post->id}/reservation")->assertStatus(409)->assertJsonPath('post.id', $post->id);
        $this->postJson("/posts/{$post->id}/approval")->assertStatus(409);
        $this->deleteJson("/posts/{$post->id}/reservation")->assertOk()->assertJsonPath('post.reservedBy', null);
        $this->deleteJson("/posts/{$post->id}/reservation")->assertOk();

        $other = $this->as(Role::Member);
        $this->postJson("/posts/{$post->id}/reservation")->assertOk();
        $this->actingAs($member, 'sanctum');
        $this->postJson("/posts/{$post->id}/reservation")->assertStatus(409)->assertJsonPath('reservedBy.id', $other->id);
        $this->deleteJson("/posts/{$post->id}/reservation")->assertForbidden();
    }

    public function testOverdueRequestsCanBeTakenOverAndAreLogged(): void
    {
        $old = User::factory()->create(['role' => Role::Member]);
        $post = $this->request(User::factory()->create(['role' => Role::User]), ['reserved_by' => $old->id, 'reserved_at' => now()->subWeeks(4)]);
        $member = $this->as(Role::Member);

        $this->postJson("/posts/{$post->id}/reservation")->assertOk()->assertJsonPath('post.reservedBy.id', $member->id);
        $this->assertDatabaseHas('logs', ['entry_type' => 'res_overtake']);
    }

    public function testDevelopersCanReserveForAnotherUser(): void
    {
        $member = User::factory()->create(['role' => Role::Member]);
        (new DeviantartUser(['name' => 'TargetUser', 'avatar_url' => 'https://example.com/a.png']))->forceFill(['id' => 'c9a2b1d0-0000-4000-8000-0000000000aa', 'user_id' => $member->id])->save();
        $plain = User::factory()->create(['role' => Role::User]);
        (new DeviantartUser(['name' => 'PlainUser', 'avatar_url' => 'https://example.com/b.png']))->forceFill(['id' => 'c9a2b1d0-0000-4000-8000-0000000000ab', 'user_id' => $plain->id])->save();
        $post = $this->request(User::factory()->create(['role' => Role::User]));

        // Only developers get to use it, for everybody else it is ignored
        $this->as(Role::Staff);
        $this->postJson("/posts/{$post->id}/reservation", ['as' => 'TargetUser'])->assertOk()->assertJsonPath('post.reservedBy.id', fn ($id) => $id !== $member->id);
        Post::whereKey($post->id)->update(['reserved_by' => null, 'reserved_at' => null]);

        $this->as(Role::Developer);
        $this->postJson("/posts/{$post->id}/reservation", ['as' => 'Nobody At All'])->assertJsonValidationErrors('as');
        $this->postJson("/posts/{$post->id}/reservation", ['as' => 'PlainUser'])->assertStatus(409)->assertJsonPath('retry', true);
        $this->assertNull($post->fresh()->reserved_by);
        $this->postJson("/posts/{$post->id}/reservation", ['as' => 'PlainUser', 'screwit' => 1])->assertOk()->assertJsonPath('post.reservedBy.id', $plain->id);
        Post::whereKey($post->id)->update(['reserved_by' => null, 'reserved_at' => null]);
        $this->postJson("/posts/{$post->id}/reservation", ['as' => 'TargetUser'])->assertOk()->assertJsonPath('post.reservedBy.id', $member->id);
    }

    public function testReservationLimitAndPermissionPreference(): void
    {
        $member = $this->as(Role::Member);
        for ($i = 0; $i < 4; $i++) {
            $this->request(User::factory()->create(['role' => Role::User]), ['reserved_by' => $member->id, 'reserved_at' => now()]);
        }
        $free = $this->request(User::factory()->create(['role' => Role::User]));

        $this->postJson("/posts/{$free->id}/reservation")->assertStatus(409);

        Post::where('reserved_by', $member->id)->delete();
        UserPrefHelper::set($member, UserPrefKey::Admin_CanReservePosts, false);
        $this->postJson("/posts/{$free->id}/reservation")->assertForbidden();
    }

    public function testBrokenPostsCannotBeReserved(): void
    {
        $post = $this->request(User::factory()->create(['role' => Role::User]), ['broken' => true]);
        $this->as(Role::Member);
        $this->postJson("/posts/{$post->id}/reservation")->assertStatus(409);
    }

    public function testReservationsAreDeletedNotFreed(): void
    {
        $member = $this->as(Role::Member);
        $reservation = Post::create(['reserved_by' => $member->id, 'reserved_at' => now(), 'show_id' => $this->show->id, 'preview' => 'https://img.example/p.png', 'fullsize' => 'https://img.example/f.png']);

        $this->deleteJson("/posts/{$reservation->id}/reservation")->assertNoContent();
        $this->assertDatabaseMissing('posts', ['id' => $reservation->id]);
    }

    public function testFinishingApprovingAndUnfinishing(): void
    {
        $this->fakeDeviations(['dfin001' => 'Finisher', 'dfin002' => 'Finisher', 'dfin004' => 'Somebody'], club: []);
        $requester = User::factory()->create(['role' => Role::User, 'name' => 'Requester']);
        $finisher = $this->member('Finisher');
        $this->member('Somebody');
        $post = $this->request($requester, ['reserved_by' => $finisher->id, 'reserved_at' => now()]);
        $used = $this->request($requester, ['reserved_by' => $finisher->id, 'reserved_at' => now(), 'deviation_id' => 'dfin001', 'finished_at' => now()]);
        $this->actingAs($finisher, 'sanctum');

        // Already used for another post
        $this->putJson("/posts/{$post->id}/finish", ['deviation' => 'http://fav.me/dfin001'])->assertStatus(409)->assertJsonPath('existingPost.id', $used->id);
        // A deviation by somebody else needs confirmation
        $this->putJson("/posts/{$post->id}/finish", ['deviation' => 'http://fav.me/dfin004'])->assertStatus(409)->assertJsonPath('retry', true);
        $this->putJson("/posts/{$post->id}/finish", ['deviation' => 'https://imgur.com/abcdefg'])->assertJsonValidationErrors('deviation');
        $this->putJson("/posts/{$post->id}/finish")->assertJsonValidationErrors('deviation');

        $this->putJson("/posts/{$post->id}/finish", ['deviation' => 'http://fav.me/dfin002'])->assertOk()
            ->assertJsonPath('approved', false)
            ->assertJsonPath('notified.name', 'Requester');
        $this->assertSame('dfin002', $post->fresh()->deviation_id);
        $this->assertDatabaseHas('notifications', ['recipient_id' => $requester->id, 'type' => 'post-finished']);

        // Not in the club gallery yet
        $this->postJson("/posts/{$post->id}/approval")->assertStatus(409);

        $this->fakeDeviations(['dfin002' => 'Finisher'], club: ['dfin002']);
        $this->postJson("/posts/{$post->id}/approval")->assertOk()->assertJsonPath('post.approved', true);
        $this->assertDatabaseHas('locked_posts', ['post_id' => $post->id]);
        $this->assertSame('post_approved', PcgSlotHistory::where('user_id', $finisher->id)->value('change_type'));

        // Approved posts are locked, and cannot be unlocked while they are in the gallery
        $this->deleteJson("/posts/{$post->id}/finish")->assertStatus(409);
        $staff = $this->as(Role::Staff);
        $this->deleteJson("/posts/{$post->id}/approval")->assertStatus(409);

        $this->fakeDeviations(['dfin002' => 'Finisher'], club: []);
        $this->deleteJson("/posts/{$post->id}/approval")->assertNoContent();
        $this->assertSame('post_unapproved', PcgSlotHistory::where('user_id', $finisher->id)->orderByDesc('id')->value('change_type'));
        $this->deleteJson("/posts/{$post->id}/finish")->assertNoContent();
        $this->assertNull($post->fresh()->deviation_id);
    }

    public function testFinishingWithAGalleryDeviationApprovesAutomatically(): void
    {
        $this->fakeDeviations(['dfin002' => 'Finisher'], club: ['dfin002']);
        $finisher = $this->member('Finisher');
        $post = $this->request($finisher, ['reserved_by' => $finisher->id, 'reserved_at' => now()]);
        $this->actingAs($finisher, 'sanctum');

        $this->putJson("/posts/{$post->id}/finish", ['deviation' => 'http://fav.me/dfin002'])->assertOk()->assertJsonPath('approved', true)->assertJsonPath('notified', null);
        $this->assertTrue($post->fresh()->lock);
    }

    public function testUnfinishingWithUnbind(): void
    {
        $member = $this->as(Role::Member);
        $reservation = Post::create(['reserved_by' => $member->id, 'reserved_at' => now(), 'show_id' => $this->show->id, 'preview' => 'https://img.example/p.png', 'fullsize' => 'https://img.example/f.png', 'deviation_id' => 'dfin009', 'finished_at' => now()]);
        $request = $this->request(User::factory()->create(['role' => Role::User]), ['reserved_by' => $member->id, 'reserved_at' => now(), 'deviation_id' => 'dfin008', 'finished_at' => now()]);

        $this->deleteJson("/posts/{$request->id}/finish?unbind=1")->assertNoContent();
        $this->assertNull($request->fresh()->reserved_by);
        $this->deleteJson("/posts/{$reservation->id}/finish?unbind=1")->assertOk()->assertJsonPath('remove', true);
        $this->assertDatabaseMissing('posts', ['id' => $reservation->id]);
    }

    public function testCreatingPostsFromImageLinks(): void
    {
        $this->fakeDeviations(['dfin005' => 'Artist']);
        $member = $this->as(Role::Member);
        $base = ['showId' => $this->show->id, 'imageUrl' => 'http://fav.me/dfin005'];

        $request = $this->postJson('/posts', $base + ['kind' => 'request', 'label' => 'A new request', 'type' => 'chr'])->assertCreated()->assertJsonPath('kind', 'request')->json('id');
        $this->assertSame($member->id, Post::find($request)->requested_by);
        $this->assertSame('https://img.example/dfin005-thumb.png', Post::find($request)->preview);

        $reservation = $this->postJson('/posts', $base + ['kind' => 'reservation', 'label' => 'A new reservation'])->assertCreated()->json('id');
        $this->assertSame($member->id, Post::find($reservation)->reserved_by);

        $this->postJson('/posts', ['kind' => 'nonsense'])->assertJsonValidationErrors('kind');
        $this->postJson('/posts', ['showId' => 987654, 'kind' => 'request', 'label' => 'A new request', 'type' => 'chr', 'imageUrl' => 'http://fav.me/dfin005'])->assertJsonValidationErrors('showId');
        $this->postJson('/posts', $base + ['kind' => 'request', 'type' => 'chr'])->assertJsonValidationErrors('label');
        $this->postJson('/posts', $base + ['kind' => 'request', 'label' => 'A new request'])->assertJsonValidationErrors('type');
        $this->postJson('/posts', ['kind' => 'request', 'showId' => $this->show->id, 'label' => 'A new request', 'type' => 'chr'])->assertJsonValidationErrors('imageUrl');
        $this->postJson('/posts', ['kind' => 'request', 'showId' => $this->show->id, 'label' => 'A new request', 'type' => 'chr', 'imageUrl' => 'http://example.com/x.png'])->assertJsonValidationErrors('imageUrl');
    }

    public function testRegularUsersMakeRequestsButNotReservations(): void
    {
        $this->fakeDeviations(['dfin005' => 'Artist']);
        $this->as(Role::User);
        $base = ['showId' => $this->show->id, 'imageUrl' => 'http://fav.me/dfin005', 'label' => 'Something'];

        $this->postJson('/posts', $base + ['kind' => 'request', 'type' => 'obj'])->assertCreated();
        $this->postJson('/posts', $base + ['kind' => 'reservation'])->assertForbidden();
    }

    public function testCheckingImages(): void
    {
        $this->fakeDeviations(['dfin005' => 'Artist']);
        $this->as(Role::User);

        $this->postJson('/posts/check-image', ['imageUrl' => 'http://fav.me/dfin005'])->assertOk()->assertJsonPath('preview', 'https://img.example/dfin005-thumb.png')->assertJsonPath('title', 'Deviation dfin005');
        $this->postJson('/posts/check-image')->assertJsonValidationErrors('imageUrl');
        $this->postJson('/posts/check-image', ['imageUrl' => 'http://fav.me/dnotthere'])->assertJsonValidationErrors('imageUrl');
    }

    public function testChangingTheImageOfAPost(): void
    {
        $this->fakeDeviations(['dfin006' => 'Artist']);
        $user = $this->as(Role::User);
        $post = $this->request($user);
        $reserved = $this->request($user, ['reserved_by' => User::factory()->create(['role' => Role::Member])->id, 'reserved_at' => now()]);

        $this->putJson("/posts/{$post->id}/image", ['imageUrl' => 'http://fav.me/dfin006'])->assertOk()->assertJsonPath('preview', 'https://img.example/dfin006-thumb.png');
        $this->assertDatabaseHas('logs', ['entry_type' => 'img_update']);
        $this->putJson("/posts/{$reserved->id}/image", ['imageUrl' => 'http://fav.me/dfin006'])->assertStatus(409);
        // The same image cannot be used twice
        $this->putJson("/posts/{$reserved->id}/image", ['imageUrl' => 'http://fav.me/dfin006'])->assertStatus(409);
    }

    public function testUnbreakingRestoresTheReserver(): void
    {
        $this->fakeDeviations([]);
        $reserver = User::factory()->create(['role' => Role::Member]);
        $post = $this->request(User::factory()->create(['role' => Role::User]), ['broken' => true]);
        BrokenPost::create(['post_id' => $post->id, 'reserved_by' => $reserver->id, 'response_code' => 404, 'failing_url' => 'https://img.example/f.png']);
        $this->as(Role::Staff);

        $this->postJson("/posts/{$post->id}/unbreak")->assertOk()->assertJsonPath('post.broken', false)->assertJsonPath('post.reservedBy.id', $reserver->id);
        $this->assertDatabaseHas('logs', ['entry_type' => 'post_fix']);
    }

    public function testUnbreakingFailsWhileAnImageIsStillUnavailable(): void
    {
        $this->resetHttp();
        Http::fake(fn($request) => Http::response(str_contains($request->url(), 'cloudflare.com') ? '' : '', str_contains($request->url(), 'cloudflare.com') ? 200 : 404));
        $post = $this->request(User::factory()->create(['role' => Role::User]), ['broken' => true]);
        $this->as(Role::Staff);

        $this->postJson("/posts/{$post->id}/unbreak")->assertStatus(409);
        $this->assertTrue($post->fresh()->broken);
    }

    public function testDeletingRequests(): void
    {
        $user = $this->as(Role::User);
        $own = $this->request($user);
        $reserved = $this->request($user, ['reserved_by' => User::factory()->create(['role' => Role::Member])->id, 'reserved_at' => now()]);
        $others = $this->request(User::factory()->create(['role' => Role::User]));

        $this->deleteJson("/posts/requests/{$reserved->id}")->assertStatus(409);
        $this->deleteJson("/posts/requests/{$others->id}")->assertForbidden();
        $this->deleteJson("/posts/requests/{$own->id}")->assertNoContent();
        $this->deleteJson("/posts/requests/{$own->id}")->assertNotFound();
        $this->assertDatabaseHas('logs', ['entry_type' => 'req_delete']);

        $this->as(Role::Staff);
        $this->deleteJson("/posts/requests/{$others->id}")->assertNoContent();
    }

    public function testStaffCanAddFinishedReservations(): void
    {
        $this->fakeDeviations(['dfin007' => 'Artist'], club: ['dfin007']);
        $artist = $this->member('Artist');
        $this->as(Role::Staff);

        $id = $this->postJson('/posts/reservations', ['showId' => $this->show->id, 'deviation' => 'http://fav.me/dfin007'])->assertCreated()->json('id');
        $post = Post::find($id);
        $this->assertSame($artist->id, $post->reserved_by);
        $this->assertTrue($post->lock);
        $this->assertDatabaseHas('locked_posts', ['post_id' => $id]);
        $this->postJson('/posts/reservations', ['showId' => 987654, 'deviation' => 'http://fav.me/dfin007'])->assertStatus(409);
    }

    public function testLocation(): void
    {
        $post = $this->request(User::factory()->create(['role' => Role::User]));
        $other = Show::create(['type' => 'movie', 'title' => 'The Movie', 'posted_by' => $this->show->posted_by, 'airs' => '2017-10-06 15:00']);

        $this->getJson("/posts/{$post->id}/location?showId={$this->show->id}")->assertOk()->assertExactJson(['refresh' => 'request']);
        $this->getJson("/posts/{$post->id}/location?showId={$other->id}")->assertOk()->assertJsonPath('castle.name', 'Friendship is Magic');
        $this->getJson('/posts/987654/location')->assertNotFound();
        $post->update(['broken' => true]);
        $this->getJson("/posts/{$post->id}/location")->assertNotFound();
    }
}
