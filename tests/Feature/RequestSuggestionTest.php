<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Post;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(Show $show, array $attributes): Post
    {
        return Post::create($attributes + ['show_id' => $show->id, 'type' => 'chr', 'requested_by' => User::factory()->create()->id, 'requested_at' => now(), 'preview' => 'https://example.com/p.png', 'fullsize' => 'https://example.com/f.png', 'label' => 'Open request']);
    }

    public function testSuggestsOpenRequestsOnceEach(): void
    {
        $show = Show::create(['type' => 'episode', 'season' => 1, 'episode' => 1, 'title' => 'First', 'posted_by' => User::factory()->create()->id, 'airs' => '2010-10-10 15:00', 'no' => 1]);
        $open = $this->makePost($show, []);
        $stale = $this->makePost($show, ['reserved_by' => User::factory()->create()->id, 'reserved_at' => now()->subWeeks(4)]);
        $this->makePost($show, ['reserved_by' => User::factory()->create()->id, 'reserved_at' => now()]);
        $this->makePost($show, ['deviation_id' => 'dfin001', 'finished_at' => now()]);
        $this->makePost($show, ['broken' => true]);

        $this->getJson('/posts/requests/suggestion')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => Role::Member]), 'sanctum');

        $first = $this->getJson('/posts/requests/suggestion')->assertOk()->assertJsonPath('post.show.id', $show->id)->json('post.id');
        $this->assertContains($first, [$open->id, $stale->id]);
        $second = $this->getJson("/posts/requests/suggestion?alreadyLoaded=$first")->assertOk()->json('post.id');
        $this->assertNotSame($first, $second);
        $this->getJson("/posts/requests/suggestion?alreadyLoaded=$first,$second")->assertNotFound();
    }
}
