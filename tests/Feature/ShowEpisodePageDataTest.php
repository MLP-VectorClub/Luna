<?php

namespace Tests\Feature;

use App\Models\Show;
use App\Models\User;
use App\Utils\SettingsHelper;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowEpisodePageDataTest extends TestCase
{
    use RefreshDatabase;

    private function show(array $attributes): Show
    {
        $poster = User::query()->first() ?? User::factory()->create(['role' => Role::User]);

        return Show::create($attributes + ['posted_by' => $poster->id, 'airs' => '2010-10-10 15:00', 'title' => 'T']);
    }

    public function testEpisodesAreAdjacentByOverallNumber(): void
    {
        $first = $this->show(['type' => 'episode', 'season' => 1, 'episode' => 1, 'no' => 1, 'title' => 'First']);
        $second = $this->show(['type' => 'episode', 'season' => 1, 'episode' => 2, 'no' => 2, 'title' => 'Second']);
        $third = $this->show(['type' => 'episode', 'season' => 1, 'episode' => 3, 'no' => 3, 'title' => 'Third']);
        $this->show(['type' => 'movie', 'season' => null, 'episode' => 1, 'no' => null, 'title' => 'A Movie']);

        $this->getJson("/show/{$second->id}/adjacent")->assertOk()
            ->assertJsonPath('previous.title', 'First')->assertJsonPath('next.title', 'Third');
        $this->getJson("/show/{$first->id}/adjacent")->assertOk()->assertJsonPath('previous', null)->assertJsonPath('next.title', 'Second');
        $this->getJson("/show/{$third->id}/adjacent")->assertOk()->assertJsonPath('next', null);
        $this->getJson('/show/987654/adjacent')->assertNotFound();
    }

    public function testMoviesAreAdjacentAmongTheOtherEntries(): void
    {
        $this->show(['type' => 'episode', 'season' => 1, 'episode' => 1, 'no' => 1, 'title' => 'Episode']);
        $one = $this->show(['type' => 'movie', 'season' => null, 'episode' => 1, 'no' => null, 'title' => 'Movie One']);
        $two = $this->show(['type' => 'movie', 'season' => null, 'episode' => 2, 'no' => null, 'title' => 'Movie Two']);

        $this->getJson("/show/{$one->id}/adjacent")->assertOk()->assertJsonPath('previous', null)->assertJsonPath('next.title', 'Movie Two');
        $this->getJson("/show/{$two->id}/adjacent")->assertOk()->assertJsonPath('previous.title', 'Movie One')->assertJsonPath('next', null);
    }

    public function testTheReservationTextsArePublic(): void
    {
        SettingsHelper::set('about_reservations', '<p>About</p>');
        SettingsHelper::set('reservation_rules', '<ol><li>Rule</li></ol>');

        $this->getJson('/show/reservation-info')->assertOk()->assertExactJson(['aboutReservations' => '<p>About</p>', 'reservationRules' => '<ol><li>Rule</li></ol>']);
    }
}
