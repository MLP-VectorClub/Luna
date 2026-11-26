<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Models\Appearance;
use App\Models\PinnedAppearance;
use App\Models\Tag;
use App\Models\User;
use App\Utils\AppearanceIndex;
use App\Utils\ColorGuideHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuideSearchTest extends TestCase
{
    use RefreshDatabase;

    private function as(Role $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function appearance(string $label, int $order, array $tags = [], GuideName $guide = GuideName::FriendshipIsMagic, ?int $ownerId = null): Appearance
    {
        $appearance = Appearance::create(['label' => $label, 'guide' => $guide, 'owner_id' => $ownerId, 'notes_src' => null]);
        // Creating assigns the next free order (sortable trait), so set the wanted one afterwards
        DB::table('appearances')->where('id', $appearance->id)->update(['order' => $order]);
        foreach ($tags as $name) {
            $tag = Tag::firstOrCreate(['name' => $name], ['type' => null, 'uses' => 1]);
            $appearance->tags()->attach($tag->id);
        }
        AppearanceIndex::update($appearance->fresh());

        return $appearance;
    }

    private function search(?string $query, int $page = 1, int $perPage = 7, GuideName $guide = GuideName::FriendshipIsMagic): array
    {
        return ColorGuideHelper::searchGuide($page, $perPage, $guide, $query)->pluck('label')->all();
    }

    public function testEveryWordOfTheSearchHasToStartAWordOfTheLabelOrATag(): void
    {
        $this->appearance('Twilight Sparkle', 1, ['unicorn', 'main cast']);
        $this->appearance('Rainbow Dash', 2, ['pegasus', 'main cast']);
        $this->appearance('Rarity', 3, ['unicorn']);

        $this->assertSame(['Twilight Sparkle', 'Rainbow Dash', 'Rarity'], $this->search(null));
        $this->assertSame(['Rainbow Dash'], $this->search('rain'));
        $this->assertSame(['Twilight Sparkle', 'Rarity'], $this->search('UNI'));
        $this->assertSame(['Twilight Sparkle'], $this->search('uni twi'));
        $this->assertSame(['Twilight Sparkle', 'Rainbow Dash'], $this->search('main cast'));
        $this->assertSame([], $this->search('unicorn pegasus'));
        $this->assertSame([], $this->search('zzq'));
        // A match in the middle of a word is not a match
        $this->assertSame([], $this->search('bow'));
    }

    public function testResultsFollowTheGuideOrderAndStayInTheirGuide(): void
    {
        $this->appearance('Second', 2, ['pony']);
        $this->appearance('First', 1, ['pony']);
        $this->appearance('Human', 1, ['pony'], GuideName::EquestriaGirls);

        $this->assertSame(['First', 'Second'], $this->search('pony'));
        $this->assertSame(['Human'], $this->search('pony', guide: GuideName::EquestriaGirls));
    }

    public function testPunctuationSplitsWordsAndSearchesWithoutWordsAskForEverything(): void
    {
        $this->appearance('Rainbow-Dash (Equestria Girls)', 1);

        $this->assertSame(['Rainbow-Dash (Equestria Girls)'], $this->search("Dash's equ"));
        $this->assertSame(['Rainbow-Dash (Equestria Girls)'], $this->search('!!!'));
        // One-letter words were never searchable (the old index only knew words of two letters or more): they are skipped, and a search of nothing else finds nothing
        $this->assertSame([], $this->search('a'));
    }

    public function testPersonalGuideAppearancesAreNeverFound(): void
    {
        $owner = User::factory()->create();
        $this->appearance('Personal OC', 1, [], GuideName::FriendshipIsMagic, $owner->id);

        $this->assertSame([], $this->search(null));
        $this->assertSame(0, DB::table('appearance_search')->count());
    }

    public function testPagesAreCountedAndAPageBeyondTheEndShowsTheLastOne(): void
    {
        foreach (range(1, 9) as $n) {
            $this->appearance("Pony $n", $n, ['pony']);
        }

        $page = ColorGuideHelper::searchGuide(2, 7, GuideName::FriendshipIsMagic, 'pony');
        $this->assertSame(9, $page->total());
        $this->assertSame(['Pony 8', 'Pony 9'], $page->pluck('label')->all());

        $beyond = ColorGuideHelper::searchGuide(40, 7, GuideName::FriendshipIsMagic, 'pony');
        $this->assertSame(2, $beyond->currentPage());
        $this->assertSame(['Pony 8', 'Pony 9'], $beyond->pluck('label')->all());
    }

    public function testPagesNobodyCouldReachAreEmptyWithoutAskingTheDatabase(): void
    {
        $this->appearance('Twilight', 1);

        $this->assertCount(0, ColorGuideHelper::searchGuide(5985824810, 7, GuideName::FriendshipIsMagic)->items());
    }

    public function testRemovingAnAppearanceTakesItOutOfTheSearch(): void
    {
        $appearance = $this->appearance('Twilight', 1, ['unicorn']);
        $this->assertSame(['Twilight'], $this->search('unicorn'));

        AppearanceIndex::remove($appearance);

        $this->assertSame([], $this->search('unicorn'));
    }

    public function testUpdatingFollowsTagChanges(): void
    {
        $appearance = $this->appearance('Twilight', 1, ['unicorn']);
        $alicorn = Tag::create(['name' => 'alicorn', 'type' => null, 'uses' => 1]);
        $appearance->tags()->sync([$alicorn->id]);
        Tag::create(['name' => 'twily', 'synonym_of' => $alicorn->id, 'uses' => 0]);

        AppearanceIndex::update($appearance->fresh());

        $this->assertSame([], $this->search('unicorn'));
        $this->assertSame(['Twilight'], $this->search('alicorn'));
        $this->assertSame(['Twilight'], $this->search('twily'));
    }

    public function testRebuildSkipsPinnedAndPersonalAppearances(): void
    {
        $this->appearance('Twilight', 1);
        $pinned = $this->appearance('Pinned', 2);
        PinnedAppearance::create(['appearance_id' => $pinned->id, 'guide' => GuideName::FriendshipIsMagic]);
        $this->appearance('Personal', 3, [], GuideName::FriendshipIsMagic, User::factory()->create()->id);
        DB::table('appearance_search')->delete();

        $this->assertSame(1, AppearanceIndex::rebuild());
        $this->assertSame(['Twilight'], $this->search(null));
    }

    public function testReindexEndpointRebuilds(): void
    {
        $this->appearance('Twilight', 1);
        DB::table('appearance_search')->delete();
        $this->as(Role::Developer);

        $this->postJson('/color-guide/reindex')->assertOk()->assertJsonPath('message', 'Re-index successful, 1 appearance was indexed');
        $this->assertSame(['Twilight'], $this->search(null));
    }
}
