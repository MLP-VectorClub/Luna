<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BrokenPost;
use App\Models\Log;
use App\Models\Post;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The check the old front end made when an image of a post failed to load in the browser (`GET /posts/{id}/reload`) */
class PostReloadTest extends TestCase
{
    use RefreshDatabase;

    private Show $show;

    protected function setUp(): void
    {
        parent::setUp();
        $this->show = Show::create(['type' => 'episode', 'season' => 1, 'episode' => 1, 'title' => 'First', 'posted_by' => User::factory()->create(['role' => Role::User])->id, 'airs' => '2010-10-10 15:00', 'no' => 1]);
    }

    private function request(array $attributes = []): Post
    {
        $requester = User::factory()->create(['role' => Role::User]);

        return Post::create($attributes + ['type' => 'chr', 'requested_by' => $requester->id, 'requested_at' => now(), 'show_id' => $this->show->id, 'preview' => 'https://img.example/p.png', 'fullsize' => 'https://img.example/f.png', 'label' => 'A request']);
    }

    private function fakeImages(array $statuses): void
    {
        app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        Http::fake(function ($request) use ($statuses) {
            foreach ($statuses as $url => $response) {
                if (str_contains($request->url(), $url)) {
                    return is_int($response) ? Http::response('', $response) : $response;
                }
            }

            return Http::response('', 200);
        });
    }

    public function testPostsWithWorkingImagesStayAsTheyAre(): void
    {
        $post = $this->request();
        $this->fakeImages([]);

        $this->getJson("/posts/{$post->id}/reload")->assertOk()->assertJsonPath('post.id', $post->id)->assertJsonMissingPath('broken');
        $this->assertFalse($post->fresh()->broken);
        $this->getJson('/posts/987654/reload')->assertNotFound();
    }

    public function testADeviantArtImageWithADeadTokenBreaksThePostButOtherHostsDoNot(): void
    {
        $dead = $this->request(['preview' => 'https://images-wixmp-abc.wixmp.com/f/a.jpg?token=dead', 'fullsize' => 'https://images-wixmp-abc.wixmp.com/f/a.jpg?token=dead']);
        $this->fakeImages(['wixmp.com' => 401]);
        $this->getJson("/posts/{$dead->id}/reload")->assertOk()->assertExactJson(['broken' => true]);
        $this->assertSame(401, BrokenPost::where('post_id', $dead->id)->first()->response_code);

        // Other hosts answering 401/403 may just dislike being asked from a server, only a 404 counts there
        $other = $this->request();
        $this->fakeImages(['img.example' => 401]);
        $this->getJson("/posts/{$other->id}/reload")->assertOk()->assertJsonMissingPath('broken');
        $this->assertFalse($other->fresh()->broken);
    }

    public function testAMissingImageBreaksThePostAndFreesItsReserver(): void
    {
        $reserver = User::factory()->create(['role' => Role::Member]);
        $post = $this->request(['reserved_by' => $reserver->id, 'reserved_at' => now()]);
        $this->fakeImages(['p.png' => 404]);

        $this->getJson("/posts/{$post->id}/reload")->assertOk()->assertExactJson(['broken' => true]);

        $post = $post->fresh();
        $this->assertTrue($post->broken);
        $this->assertNull($post->reserved_by);
        $record = BrokenPost::where('post_id', $post->id)->first();
        $this->assertSame($reserver->id, $record->reserved_by);
        $this->assertSame(404, $record->response_code);
        $this->assertSame('https://img.example/p.png', $record->failing_url);
        // Once broken the post is gone for everybody but staff, and is not checked again
        $this->getJson("/posts/{$post->id}/reload")->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => Role::Staff]), 'sanctum');
        $this->getJson("/posts/{$post->id}/reload")->assertOk()->assertJsonPath('post.broken', true);
        $this->assertSame(1, BrokenPost::count());
    }

    public function testStaffSeeTheBrokenPostRightAway(): void
    {
        $post = $this->request();
        $this->fakeImages(['f.png' => 404]);
        $this->actingAs(User::factory()->create(['role' => Role::Staff]), 'sanctum');

        $this->getJson("/posts/{$post->id}/reload")->assertOk()->assertJsonPath('post.broken', true);
        $this->assertTrue($post->fresh()->broken);
    }

    public function testOnlyA404CountsAsMissing(): void
    {
        $post = $this->request();
        $this->fakeImages(['f.png' => 500]);

        $this->getJson("/posts/{$post->id}/reload")->assertOk()->assertJsonPath('post.id', $post->id);
        $this->assertFalse($post->fresh()->broken);
    }

    public function testFinishedPostsAreNotChecked(): void
    {
        $post = $this->request(['deviation_id' => 'dfin001', 'finished_at' => now()]);
        $this->fakeImages(['p.png' => 404]);

        $this->getJson("/posts/{$post->id}/reload")->assertOk()->assertJsonPath('post.id', $post->id);
        $this->assertFalse($post->fresh()->broken);
    }

    public function testAMergedDerpibooruImageIsFoundAgain(): void
    {
        $post = $this->request(['fullsize' => 'https://derpicdn.net/img/2020/1/1/100/full.png', 'preview' => 'https://derpicdn.net/img/2020/1/1/100/small.png']);
        $this->fakeImages([
            'derpicdn.net/img/2020/1/1/100/small.png' => 404,
            'derpicdn.net/img/2020/1/1/100/full.png' => 404,
            'api/v1/json/images/100' => Http::response(['duplicate_of' => 200]),
            'api/v1/json/images/200' => Http::response(['processed' => true, 'mime_type' => 'image/png', 'representations' => ['small' => 'https://derpicdn.net/img/2020/1/1/200/small.png', 'full' => 'https://derpicdn.net/img/2020/1/1/200/full.png']]),
        ]);

        $this->getJson("/posts/{$post->id}/reload")->assertOk()->assertJsonPath('post.fullsizeUrl', 'https://derpicdn.net/img/2020/1/1/200/full.png');

        $post = $post->fresh();
        $this->assertFalse($post->broken);
        $this->assertSame('https://derpicdn.net/img/2020/1/1/200/small.png', $post->preview);
        $entry = Log::where('entry_type', 'derpimerge')->first();
        $this->assertSame('https://derpicdn.net/img/2020/1/1/100/full.png', $entry->data['original_fullsize']);
    }
}
