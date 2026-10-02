<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Event;
use App\Models\EventEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EventEntriesTest extends TestCase
{
    use RefreshDatabase;

    private function entry(User $owner, bool $ended = false): EventEntry
    {
        $event = Event::create(['name' => 'Contest', 'starts_at' => now()->subWeek(), 'ends_at' => $ended ? now()->subDay() : now()->addWeek(), 'entry_role' => 'user', 'desc_src' => 'Rules', 'desc_rend' => '<p>Rules</p>', 'added_by' => $owner->id]);

        return EventEntry::create(['event_id' => $event->id, 'title' => 'Seeded Entry', 'sub_prov' => 'fav.me', 'sub_id' => 'd1b2c3d', 'submitted_by' => $owner->id, 'prev_thumb' => 'https://example.com/t.png']);
    }

    public function testAuthenticationAndOwnership(): void
    {
        $owner = User::factory()->create(['role' => Role::User]);
        $entry = $this->entry($owner);
        foreach (['get', 'put', 'delete'] as $method) {
            $this->json($method, "/event-entries/{$entry->id}")->assertUnauthorized();
            $this->json($method, "/events/{$entry->event_id}/entries")->assertUnauthorized();
        }

        $this->actingAs(User::factory()->create(['role' => Role::User]), 'sanctum');
        foreach (['get', 'put', 'delete'] as $method) {
            $this->json($method, "/event-entries/{$entry->id}")->assertForbidden();
        }
        $this->getJson('/event-entries/987654')->assertNotFound();
        $this->getJson("/events/{$entry->event_id}/entries")->assertNotFound()->assertJsonPath('message', 'Entry ID is missing or invalid');
    }

    private function fakeDeviations(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'backend.deviantart.com/oembed')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $id = basename($query['url']);

                return Http::response(['title' => "Deviation $id", 'type' => 'photo', 'url' => "https://img.example/$id-full.png", 'thumbnail_url' => "https://img.example/$id-thumb.png", 'author_name' => 'Someone', 'imagetype' => 'png']);
            }

            return Http::response('', 200, ['Content-Type' => 'image/png']);
        });
    }

    public function testShowAndUpdate(): void
    {
        $this->fakeDeviations();
        $owner = User::factory()->create(['role' => Role::User]);
        $entry = $this->entry($owner);
        $this->actingAs($owner, 'sanctum');

        $this->getJson("/event-entries/{$entry->id}")->assertOk()->assertExactJson(['link' => 'http://fav.me/d1b2c3d', 'title' => 'Seeded Entry', 'prevSrc' => null]);
        $this->putJson("/event-entries/{$entry->id}", [])->assertJsonValidationErrors('link');
        $this->putJson("/event-entries/{$entry->id}", ['link' => 'http://example.com/x', 'title' => 'Fine'])->assertJsonValidationErrors('link');
        $this->putJson("/event-entries/{$entry->id}", ['link' => 'http://fav.me/d1b2c3d', 'title' => 'x'])->assertJsonValidationErrors('title');

        $this->putJson("/event-entries/{$entry->id}", ['link' => 'http://sta.sh/0abcdefghij', 'title' => 'Renamed Entry'])->assertOk()->assertExactJson([]);
        $this->getJson("/event-entries/{$entry->id}")->assertJsonPath('title', 'Renamed Entry')->assertJsonPath('link', 'http://sta.sh/0abcdefghij');
        $this->assertDatabaseHas('event_entries', ['id' => $entry->id, 'sub_prov' => 'sta.sh', 'title' => 'Renamed Entry']);
    }

    public function testStaffAndEndedEvents(): void
    {
        $owner = User::factory()->create(['role' => Role::User]);
        $entry = $this->entry($owner, true);

        $this->actingAs($owner, 'sanctum');
        $this->getJson("/event-entries/{$entry->id}")->assertForbidden();
        $this->deleteJson("/event-entries/{$entry->id}")->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => Role::Staff]), 'sanctum');
        $this->getJson("/event-entries/{$entry->id}")->assertOk();
        $this->deleteJson("/event-entries/{$entry->id}")->assertNoContent();
        $this->assertSame(0, EventEntry::count());
    }

    public function testDeleteByOwner(): void
    {
        $owner = User::factory()->create(['role' => Role::User]);
        $entry = $this->entry($owner);
        $this->actingAs($owner, 'sanctum');
        $this->deleteJson("/event-entries/{$entry->id}")->assertNoContent();
        $this->getJson("/event-entries/{$entry->id}")->assertNotFound();
    }
}
