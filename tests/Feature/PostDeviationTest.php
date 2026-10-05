<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Post;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostDeviationTest extends TestCase
{
    use RefreshDatabase;

    private Show $show;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->show = Show::create(['type' => 'episode', 'season' => 1, 'episode' => 1, 'title' => 'Friendship is Magic', 'posted_by' => User::factory()->create(['role' => Role::User])->id, 'airs' => '2010-10-10 15:00', 'no' => 1]);
    }

    private function makePost(array $attributes = []): Post
    {
        $user = User::factory()->create(['role' => Role::Member]);

        return Post::create($attributes + ['requested_by' => $user->id, 'requested_at' => now(), 'type' => 'chr', 'show_id' => $this->show->id, 'preview' => 'https://img.example/p.png', 'fullsize' => 'https://img.example/f.png', 'label' => 'A request']);
    }

    public function testAFinishedPostAnswersWithItsSubmission(): void
    {
        Cache::put('deviation:fav.me:dfin001', [
            'provider' => 'fav.me', 'id' => 'dfin001', 'preview' => 'https://img.example/thumb.png', 'fullsize' => 'https://img.example/full.png',
            'title' => 'Finished', 'author' => 'Vectorist', 'type' => 'png',
        ], 60);
        $post = $this->makePost(['deviation_id' => 'dfin001', 'finished_at' => now()]);

        $this->getJson("/posts/{$post->id}/deviation")->assertOk()->assertExactJson([
            'id' => 'dfin001', 'title' => 'Finished', 'author' => 'Vectorist',
            'previewUrl' => 'https://img.example/thumb.png', 'fullsizeUrl' => 'https://img.example/full.png',
        ]);
    }

    public function testUnfinishedMissingAndUnknownSubmissionsAre404(): void
    {
        $this->getJson('/posts/987654/deviation')->assertNotFound();
        $unfinished = $this->makePost();
        $this->getJson("/posts/{$unfinished->id}/deviation")->assertNotFound();

        Http::fake(fn ($request) => Http::response('', str_contains($request->url(), 'cloudflare.com') ? 200 : 404));
        $unknown = $this->makePost(['deviation_id' => 'dnone01', 'finished_at' => now()]);
        $this->getJson("/posts/{$unknown->id}/deviation")->assertNotFound();
    }

    public function testAnUnreachableDeviantArtIsA502(): void
    {
        Http::fake(fn ($request) => Http::response('', str_contains($request->url(), 'cloudflare.com') ? 200 : 500));
        $post = $this->makePost(['deviation_id' => 'derr001', 'finished_at' => now()]);

        $this->getJson("/posts/{$post->id}/deviation")->assertStatus(502);
    }

    public function testBrokenPostsAreOnlyForStaff(): void
    {
        Cache::put('deviation:fav.me:dbrk001', ['provider' => 'fav.me', 'id' => 'dbrk001', 'preview' => 'https://img.example/t.png', 'fullsize' => null, 'title' => 'T', 'author' => null, 'type' => null], 60);
        $post = $this->makePost(['deviation_id' => 'dbrk001', 'finished_at' => now(), 'broken' => true]);

        $this->getJson("/posts/{$post->id}/deviation")->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => Role::Staff]), 'sanctum');
        $this->getJson("/posts/{$post->id}/deviation")->assertOk();
    }
}
