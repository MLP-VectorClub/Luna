<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Show;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Endpoints the old front end used that Luna did not have yet */
class OldFrontEndGapsTest extends TestCase
{
    use RefreshDatabase;

    public function testTagAutocompleteIsForStaff(): void
    {
        $this->getJson('/tags/autocomplete?s=a')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => Role::Member]), 'sanctum');
        $this->getJson('/tags/autocomplete?s=a')->assertForbidden();
    }

    public function testTagAutocompleteFindsByPartOfTheNameMostUsedFirst(): void
    {
        $target = Tag::create(['name' => 'twilight sparkle', 'type' => 'char', 'uses' => 50]);
        Tag::create(['name' => 'twilight', 'type' => 'char', 'uses' => 3, 'synonym_of' => $target->id]);
        Tag::create(['name' => 'twinkle', 'type' => 'app', 'uses' => 9]);
        Tag::create(['name' => 'unrelated', 'type' => 'app', 'uses' => 99]);
        $this->actingAs(User::factory()->create(['role' => Role::Staff]), 'sanctum');

        $names = fn (array $tags) => array_column($tags, 'name');
        $found = $this->getJson('/tags/autocomplete?s=TWI')->assertOk()->json('tags');
        $this->assertSame(['twilight sparkle', 'twinkle', 'twilight'], $names($found));
        $this->assertSame('twilight sparkle', $found[2]['synonymTarget']);
        $this->assertNull($found[0]['synonymOf']);
        $this->assertSame(50, $found[0]['uses']);

        $this->assertSame(['twinkle', 'twilight'], $names($this->getJson("/tags/autocomplete?s=twi&not={$target->id}")->json('tags')));
        $this->assertSame([], $this->getJson('/tags/autocomplete?s=zzz')->json('tags'));
        $this->getJson('/tags/autocomplete')->assertJsonValidationErrors('s');
    }

    public function testUpcomingShowsAreThoseAiringWithinSixMonths(): void
    {
        $poster = User::factory()->create(['role' => Role::Staff])->id;
        $make = fn (array $attributes) => Show::create($attributes + ['type' => 'episode', 'posted_by' => $poster, 'parts' => 1]);
        $make(['season' => 1, 'episode' => 1, 'no' => 1, 'title' => 'Aired', 'airs' => now()->subDay()]);
        $later = $make(['season' => 1, 'episode' => 3, 'no' => 3, 'title' => 'Later', 'airs' => now()->addMonths(2)]);
        $soon = $make(['season' => 1, 'episode' => 2, 'no' => 2, 'title' => 'Soon', 'airs' => now()->addDays(3)]);
        $make(['season' => 1, 'episode' => 4, 'no' => 4, 'title' => 'Far away', 'airs' => now()->addMonths(7)]);

        $this->getJson('/show/upcoming')->assertOk()->assertJsonCount(2, 'show')
            ->assertJsonPath('show.0.id', $soon->id)
            ->assertJsonPath('show.1.id', $later->id);
    }
}
