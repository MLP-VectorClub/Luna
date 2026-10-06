<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LockedPost;
use App\Models\Post;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostApprovalInfoTest extends TestCase
{
    use RefreshDatabase;

    private function approvedPost(): array
    {
        $poster = User::factory()->create(['role' => Role::User]);
        $reserver = User::factory()->create(['role' => Role::Member]);
        $approver = User::factory()->create(['role' => Role::Staff, 'name' => 'Approver']);
        $show = Show::create(['type' => 'episode', 'season' => 1, 'episode' => 1, 'title' => 'First', 'posted_by' => $poster->id, 'airs' => '2010-10-10 15:00', 'no' => 1]);
        $post = Post::create([
            'show_id' => $show->id, 'requested_by' => $poster->id, 'requested_at' => now()->subDays(3), 'reserved_by' => $reserver->id, 'reserved_at' => now()->subDays(2),
            'type' => 'chr', 'preview' => 'https://example.com/p.png', 'fullsize' => 'https://example.com/f.png', 'label' => 'A request',
            'deviation_id' => 'dapp001', 'finished_at' => now()->subDay(), 'lock' => true,
        ]);
        LockedPost::create(['post_id' => $post->id, 'user_id' => $approver->id]);

        return [$show, $post, $reserver, $approver];
    }

    public function testPostsCarryTheReserverAndApprovalDetails(): void
    {
        [$show, , $reserver, $approver] = $this->approvedPost();

        $guest = $this->getJson("/posts?showId={$show->id}&kind=request")->assertOk()->json('posts.0');
        $this->assertSame($reserver->id, $guest['reservedBy']['id']);
        $this->assertSame('deviantart', $guest['reservedBy']['avatarProvider'] instanceof \BackedEnum ? $guest['reservedBy']['avatarProvider']->value : $guest['reservedBy']['avatarProvider']);
        $this->assertArrayHasKey('avatarUrl', $guest['reservedBy']);
        $this->assertArrayHasKey('vectorApp', $guest['reservedBy']);
        $this->assertNotNull($guest['approvedAt']);
        $this->assertNull($guest['approvedBy'], 'Only staff are told who approved a post');

        $this->actingAs(User::factory()->create(['role' => Role::Staff]), 'sanctum');
        $this->getJson("/posts?showId={$show->id}&kind=request")->assertOk()
            ->assertJsonPath('posts.0.approvedBy.id', $approver->id)
            ->assertJsonPath('posts.0.approvedBy.name', 'Approver');
    }
}
