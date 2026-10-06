<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Models\Appearance;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowManagementTest extends TestCase
{
    use RefreshDatabase;

    private function as(Role $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function episode(array $overrides = []): Show
    {
        return Show::create($overrides + ['type' => 'episode', 'season' => 1, 'episode' => 1, 'title' => 'Friendship is Magic', 'posted_by' => User::factory()->create()->id, 'airs' => '2010-10-10 15:00', 'no' => 1, 'parts' => 2]);
    }

    public function testReadIncludesStateAndPermissions(): void
    {
        $show = $this->episode();
        $appearance = Appearance::create(['label' => 'Twilight', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => null]);
        $show->appearances()->attach($appearance);

        $this->getJson("/show/{$show->id}")->assertOk()
            ->assertJsonPath('show.id', $show->id)
            ->assertJsonPath('show.aired', true)
            ->assertJsonPath('show.canEdit', false)
            ->assertJsonPath('show.relatedAppearances.0.label', 'Twilight')
            ->assertJsonPath('show.postedByUser.id', $show->posted_by)
            ->assertJsonStructure(['show' => ['postedByUser' => ['id', 'name']]])
            ->assertJsonMissingPath('show.posted_by');
        $this->as(Role::Staff);
        $this->getJson("/show/{$show->id}")->assertJsonPath('show.canEdit', true);
        $this->getJson('/show/987654')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function testWritesRequireStaff(): void
    {
        $show = $this->episode();
        $this->postJson('/show', ['type' => 'movie'])->assertUnauthorized();
        $this->as(Role::Member);
        $this->postJson('/show', ['type' => 'movie'])->assertForbidden();
        $this->putJson("/show/{$show->id}", ['title' => 'x'])->assertForbidden();
        $this->deleteJson("/show/{$show->id}")->assertForbidden();
        $this->getJson('/show/prefill')->assertForbidden();
    }

    public function testValidation(): void
    {
        $this->as(Role::Staff);
        $movie = ['type' => 'movie', 'title' => 'Contract Test Movie', 'airs' => '2011-02-03 04:05'];

        $this->postJson('/show')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/show', ['title' => 'no'] + $movie)->assertJsonValidationErrors('title');
        $this->postJson('/show', ['airs' => '2001-01-01'] + $movie)->assertJsonValidationErrors('airs');
        $this->postJson('/show', ['airs' => 'not a date'] + $movie)->assertJsonValidationErrors('airs');
        $this->postJson('/show', ['type' => 'episode', 'title' => 'An Episode', 'airs' => '2011-02-03 04:05'])->assertJsonValidationErrors('season');
    }

    public function testLifecycle(): void
    {
        $this->as(Role::Staff);

        $created = $this->postJson('/show', ['type' => 'movie', 'title' => 'Contract Test Movie', 'airs' => '2011-02-03 04:05'])->assertCreated();
        $id = $created->json('id');
        $this->assertSame("/movie/$id-Contract-Test-Movie", $created->json('url'));
        $this->putJson("/show/$id", ['title' => 'Renamed Contract Movie', 'airs' => '2012-03-04 05:06'])->assertNoContent();
        $this->getJson("/show/$id")->assertJsonPath('show.title', 'Renamed Contract Movie');
        $this->putJson("/show/$id", ['type' => 'episode', 'title' => 'Renamed Contract Movie', 'airs' => '2012-03-04 05:06'])->assertJsonValidationErrors('type');
        $this->deleteJson("/show/$id")->assertOk();
        $this->getJson("/show/$id")->assertNotFound();
    }

    public function testEpisodeNumbersAreUnique(): void
    {
        $this->episode(['season' => 1, 'episode' => 1]);
        $this->as(Role::Staff);

        $other = $this->postJson('/show', ['type' => 'episode', 'season' => 1, 'episode' => 3, 'title' => 'Another Episode', 'airs' => '2011-02-03 04:05'])->assertCreated()->json('id');
        $this->putJson("/show/$other", ['season' => 1, 'episode' => 1, 'title' => 'Another Episode', 'airs' => '2011-02-03 04:05'])->assertStatus(409);
        $this->postJson('/show', ['type' => 'episode', 'season' => 1, 'episode' => 1, 'title' => 'Yet Another One', 'airs' => '2011-02-03 04:05'])->assertStatus(409);
        $this->postJson('/show', ['type' => 'episode', 'season' => 1, 'episode' => 2, 'twoparter' => true, 'title' => 'Two Parter Ep', 'airs' => '2011-02-03 04:05'])->assertStatus(409);
    }

    public function testVoting(): void
    {
        $show = $this->episode();
        $this->postJson("/show/{$show->id}/vote", ['vote' => 5])->assertUnauthorized();
        $this->getJson("/show/{$show->id}/vote")->assertOk()->assertExactJson(['data' => []]);
        $this->getJson("/show/{$show->id}")->assertOk()->assertJsonPath('show.userVote', null);

        $voter = $this->as(Role::User);
        $this->getJson("/show/{$show->id}")->assertJsonPath('show.userVote', null);
        $this->postJson("/show/{$show->id}/vote", ['vote' => 9])->assertJsonValidationErrors('vote');
        $this->postJson("/show/{$show->id}/vote", ['vote' => 5])->assertOk()->assertJsonPath('data.5', 1)->assertJsonPath('userVote', 5);
        $this->postJson("/show/{$show->id}/vote", ['vote' => 4])->assertStatus(409);
        $this->getJson("/show/{$show->id}")->assertJsonPath('show.userVote', 5);

        // Somebody else, and a guest, do not see the vote
        $this->as(Role::Member);
        $this->getJson("/show/{$show->id}")->assertJsonPath('show.userVote', null);
        $this->app['auth']->forgetGuards();
        $this->getJson("/show/{$show->id}")->assertJsonPath('show.userVote', null);
        $this->assertEquals(5, $show->fresh()->score);

        $future = $this->episode(['season' => 2, 'episode' => 1, 'airs' => now()->addYear()]);
        $this->actingAs($voter, 'sanctum');
        $this->postJson("/show/{$future->id}/vote", ['vote' => 5])->assertStatus(409);
    }

    public function testNextLatestAndPrefill(): void
    {
        $this->getJson('/show/next')->assertNotFound()->assertJsonPath('hiatus', true);
        $this->getJson('/show/latest')->assertNotFound();

        $past = $this->episode(['season' => 1, 'episode' => 26, 'no' => 26, 'parts' => 2, 'airs' => '2011-05-13 10:00']);
        $upcoming = $this->episode(['season' => 2, 'episode' => 1, 'no' => 27, 'airs' => now()->addMonth()]);

        $this->getJson('/show/next')->assertOk()->assertJsonPath('title', $upcoming->title)->assertJsonPath('season', 2);
        $this->getJson('/show/latest')->assertOk()->assertJsonPath('id', $past->id);

        $this->as(Role::Staff);
        $this->getJson('/show/prefill')->assertOk()->assertJsonPath('season', 2)->assertJsonPath('episode', 2)->assertJsonPath('no', 29);
    }

    public function testAppearanceLinks(): void
    {
        $show = $this->episode();
        $appearance = Appearance::create(['label' => 'Twilight', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => null]);
        $this->getJson("/show/{$show->id}/appearances")->assertUnauthorized();
        $this->as(Role::User);
        $this->getJson("/show/{$show->id}/appearances")->assertForbidden();

        $this->as(Role::Staff);
        $this->getJson("/show/{$show->id}/appearances")->assertOk()->assertJsonPath('linkedIds', [])->assertJsonPath('entries.0.id', $appearance->id);
        $this->putJson("/show/{$show->id}/appearances", ['ids' => (string) $appearance->id])->assertOk();
        $this->getJson("/show/{$show->id}/appearances")->assertJsonPath('linkedIds', [$appearance->id]);
        $this->putJson("/show/{$show->id}/appearances", ['ids' => ''])->assertOk();
        $this->getJson("/show/{$show->id}/appearances")->assertJsonPath('linkedIds', []);
        $this->getJson('/show/987654/appearances')->assertNotFound();
    }
}
