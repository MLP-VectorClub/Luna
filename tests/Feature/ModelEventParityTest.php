<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Models\Appearance;
use App\Models\BlockedEmail;
use App\Models\EmailVerification;
use App\Models\Show;
use App\Models\UsefulLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Winterchilla's model callbacks that keep the shared tables consistent (render_notes, assign_order, deleteVerifications), as Luna has to repeat them
 * while both applications write to the same database
 */
class ModelEventParityTest extends TestCase
{
    use RefreshDatabase;

    private function show(array $attributes): Show
    {
        return Show::create($attributes + ['type' => 'episode', 'posted_by' => User::factory()->create(['role' => Role::Staff])->id, 'airs' => '2019-10-13 15:00', 'parts' => 1]);
    }

    public function testNotesLinkEpisodesMoviesAndAppearances(): void
    {
        $this->show(['season' => 9, 'episode' => 25, 'parts' => 2, 'no' => 222, 'title' => 'The Last Problem']);
        $this->show(['season' => 1, 'episode' => 1, 'no' => 1, 'title' => 'Friendship is Magic: Part 1']);
        $movie = $this->show(['type' => 'movie', 'season' => 0, 'episode' => 1, 'no' => null, 'title' => 'Equestria Girls: Rainbow Rocks']);
        $other = Appearance::create(['label' => 'Rarity', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => null]);

        $source = "See S9E25-26 and S9E26 and s01e01 and S3E3 plus Movie#{$movie->id} and >>123 and #{$other->id}'s dress, #99999 and \\#{$other->id}";
        $appearance = Appearance::create(['label' => 'Twilight', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => ' '.$source]);
        $html = $appearance->fresh()->notes_rend;

        // The two-part episode is found by its first number and by the second part's
        $this->assertStringContainsString("<a href='/episode/S9E25-26-The-Last-Problem'>The Last Problem</a> and <a href='/episode/S9E25-26-The-Last-Problem'>The Last Problem</a>", $html);
        $this->assertStringContainsString("<a href='/episode/S1E1-Friendship-is-Magic-Part-1'>Friendship is Magic: Part 1</a>", $html);
        $this->assertStringContainsString('<strong>S3E3</strong>', $html);
        $this->assertStringContainsString("<a href='/movie/{$movie->id}-Equestria-Girls-Rainbow-Rocks'>EQG: Rainbow Rocks</a>", $html);
        $this->assertStringContainsString("<a href='https://derpibooru.org/123'>&gt;&gt;123</a>", $html);
        $this->assertStringContainsString("<a href='/cg/v/{$other->id}'>Rarity</a>'s dress", $html);
        $this->assertStringContainsString('#99999', $html);
        $this->assertStringContainsString("#{$other->id}", str_replace("<a href='/cg/v/{$other->id}'>Rarity</a>", '', $html));
    }

    public function testEpisodeIdsOnlyLinkInTheFriendshipIsMagicGuide(): void
    {
        $this->show(['season' => 1, 'episode' => 2, 'no' => 2, 'title' => 'The Ticket Master']);

        $official = Appearance::create(['label' => 'A', 'guide' => GuideName::EquestriaGirls, 'notes_src' => 'S1E2 here']);
        $this->assertSame('S1E2 here', $official->fresh()->notes_rend);
        $fim = Appearance::create(['label' => 'B', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => 'S1E2 here']);
        $this->assertStringContainsString("<a href='/episode/S1E2-The-Ticket-Master'>", $fim->fresh()->notes_rend);
    }

    public function testNotesAreRenderedAgainOnEverySaveAndClearedWithTheSource(): void
    {
        $appearance = Appearance::create(['label' => 'C', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => '<script>x</script> #1']);
        $this->assertStringNotContainsString('<script>', $appearance->fresh()->notes_rend);

        $other = Appearance::create(['label' => 'Later', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => null]);
        $appearance->notes_src = "see #{$other->id}";
        $appearance->save();
        $this->assertStringContainsString('>Later</a>', $appearance->fresh()->notes_rend);

        $appearance->notes_src = null;
        $appearance->save();
        $this->assertNull($appearance->fresh()->notes_rend);
    }

    public function testNewUsefulLinksGoLast(): void
    {
        $first = UsefulLink::create(['label' => 'One', 'url' => '/one', 'title' => 'One', 'minrole' => 'user']);
        $second = UsefulLink::create(['label' => 'Two', 'url' => '/two', 'title' => 'Two', 'minrole' => 'user']);
        $this->assertNotNull($first->fresh()->order);
        $this->assertSame($first->fresh()->order + 1, $second->fresh()->order);
    }

    public function testBlockingAnAddressDeletesItsPendingVerifications(): void
    {
        $user = User::factory()->create(['role' => Role::Staff]);
        EmailVerification::create(['user_id' => $user->id, 'email' => 'blocked@example.com', 'hash' => bin2hex(random_bytes(64))]);
        EmailVerification::create(['user_id' => $user->id, 'email' => 'other@example.com', 'hash' => bin2hex(random_bytes(64))]);

        BlockedEmail::record('Blocked@Example.com');

        $this->assertTrue(BlockedEmail::isBlocked('blocked@example.com'));
        $this->assertSame(['other@example.com'], EmailVerification::pluck('email')->all());
    }
}
