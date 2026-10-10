<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Models\Appearance;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): static
    {
        return $this->actingAs(User::factory()->create(['role' => Role::Staff]), 'sanctum');
    }

    private function tag(string $name, array $extra = []): Tag
    {
        return Tag::create(['name' => $name, 'type' => 'app'] + $extra);
    }

    public function testWritesRequireStaff(): void
    {
        foreach ([['POST', '/tags/recount-uses'], ['GET', '/tags/1'], ['POST', '/tags'], ['PUT', '/tags/1'], ['DELETE', '/tags/1'], ['PUT', '/tags/1/synonym'], ['DELETE', '/tags/1/synonym']] as [$method, $path]) {
            $this->json($method, $path)->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create(['role' => Role::Member]), 'sanctum');
        foreach ([['POST', '/tags'], ['PUT', '/tags/1'], ['DELETE', '/tags/1']] as [$method, $path]) {
            $this->json($method, $path)->assertForbidden();
        }
    }

    public function testCrud(): void
    {
        $this->staff();

        $created = $this->postJson('/tags', ['name' => 'Contract Tag', 'type' => 'app', 'title' => 'A tag'])->assertCreated()
            ->assertJsonPath('name', 'contract tag')
            ->assertJsonPath('synonymOf', null)
            ->assertJsonPath('uses', 0)
            ->json();
        $this->postJson('/tags', ['name' => 'contract tag', 'type' => 'app'])->assertJsonValidationErrors('name');

        $this->getJson("/tags/{$created['id']}")->assertOk()->assertJsonPath('title', 'A tag');
        $this->putJson("/tags/{$created['id']}", ['name' => 'renamed', 'type' => 'cat'])->assertOk()->assertJsonPath('type', 'cat');
        $this->deleteJson("/tags/{$created['id']}")->assertNoContent();
        $this->getJson("/tags/{$created['id']}")->assertNotFound();
    }

    public function testValidation(): void
    {
        $this->staff();

        $this->postJson('/tags')->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/tags', ['name' => 'x'])->assertJsonValidationErrors('name');
        $this->postJson('/tags', ['name' => 'a,b'])->assertJsonValidationErrors('name');
        $this->postJson('/tags', ['name' => '-dash'])->assertJsonValidationErrors('name');
        $this->postJson('/tags', ['name' => 'bad<b>tag'])->assertJsonPath('errors.name.0', fn($m) => str_contains($m, '<'));
        $this->postJson('/tags', ['name' => 'fine', 'type' => 'nonsense'])->assertJsonValidationErrors('type');
    }

    public function testAppearanceTagsSayWhichOnesAreSynonyms(): void
    {
        $base = $this->tag('base');
        $alias = $this->tag('alias', ['synonym_of' => $base->id]);
        $appearance = Appearance::create(['label' => 'Tagged', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => null, 'order' => 1]);
        $appearance->tags()->attach($base->id);

        // Staff who have not hidden synonyms get them next to the tags, marked with the tag they stand for
        $staff = User::factory()->create(['role' => Role::Staff]);
        $this->actingAs($staff, 'sanctum')->putJson("/users/{$staff->id}/preferences/cg_hidesynon", ['value' => 0])->assertOk();
        $tags = collect($this->getJson("/appearances/{$appearance->id}")->assertOk()->json('tags'))->keyBy('name');
        $this->assertSame(null, $tags['base']['synonymOf']);
        $this->assertSame($base->id, $tags['alias']['synonymOf']);

        // Everybody else only sees the regular tag
        $this->app['auth']->forgetGuards();
        $this->getJson("/appearances/{$appearance->id}")->assertOk()->assertJsonCount(1, 'tags')->assertJsonPath('tags.0.synonymOf', null);
    }

    public function testListIsPublicAndPaginated(): void
    {
        $this->tag('listed');
        $this->tag('syn', ['synonym_of' => Tag::first()->id]);

        $this->getJson('/tags?size=100')->assertOk()
            ->assertJsonPath('canEdit', false)
            ->assertJsonPath('pagination.itemsPerPage', 100)
            ->assertJsonPath('tags.1.synonymOf.name', 'listed');
        $this->getJson('/tags?size=500')->assertJsonValidationErrors('size');
        $this->getJson('/tags?page=0')->assertJsonValidationErrors('page');
        $this->staff()->getJson('/tags')->assertJsonPath('canEdit', true);
    }

    public function testDeletingTagInUseNeedsConfirmation(): void
    {
        $appearance = Appearance::create(['label' => 'Pony', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => '']);
        $tag = $this->tag('inuse');
        $tag->appearances()->attach($appearance);
        $this->staff();

        $this->deleteJson("/tags/{$tag->id}")->assertStatus(409)->assertJsonPath('uses', 1);
        $this->deleteJson("/tags/{$tag->id}?sanityCheck=1")->assertNoContent();
        $this->assertDatabaseMissing('tagged', ['appearance_id' => $appearance->id]);
    }

    public function testAddingToAppearanceWarnsWhenItIsMissing(): void
    {
        $this->staff();

        $this->postJson('/tags', ['name' => 'warned', 'addTo' => 987654])->assertCreated()->assertJsonStructure(['warning']);
        $appearance = Appearance::create(['label' => 'Pony', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => '']);
        $this->postJson('/tags', ['name' => 'added', 'addTo' => $appearance->id])->assertCreated()->assertJsonPath('uses', 1);
        $this->assertDatabaseHas('tag_changes', ['appearance_id' => $appearance->id, 'tag_name' => 'added', 'added' => true]);
    }

    public function testRecountUses(): void
    {
        $this->staff();
        $tag = $this->tag('recount');
        $tag->update(['uses' => 5]);

        $this->postJson('/tags/recount-uses', ['tagIds' => (string) $tag->id])->assertOk()->assertJsonPath("counts.{$tag->id}", 0);
        $this->postJson('/tags/recount-uses')->assertJsonValidationErrors('tagIds');
    }

    public function testSynonyms(): void
    {
        $appearance = Appearance::create(['label' => 'Pony', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => '']);
        $source = $this->tag('source');
        $target = $this->tag('target');
        $source->appearances()->attach($appearance);
        $this->staff();

        $this->putJson("/tags/{$source->id}/synonym")->assertJsonValidationErrors('targetId');
        $this->putJson("/tags/{$source->id}/synonym", ['targetId' => 987654])->assertUnprocessable();
        $this->putJson("/tags/{$source->id}/synonym", ['targetId' => $target->id])->assertOk()->assertJsonPath('target.uses', 1);
        $this->putJson("/tags/{$source->id}/synonym", ['targetId' => $target->id])->assertStatus(409);
        $this->assertDatabaseHas('tagged', ['tag_id' => $target->id, 'appearance_id' => $appearance->id]);
        $this->assertDatabaseMissing('tagged', ['tag_id' => $source->id]);

        $this->deleteJson("/tags/{$source->id}/synonym?keepTagged=1")->assertOk()->assertJsonPath('keepTagged', true);
        $this->assertDatabaseHas('tagged', ['tag_id' => $source->id, 'appearance_id' => $appearance->id]);
        $this->deleteJson("/tags/{$source->id}/synonym")->assertNoContent();
        $this->putJson('/tags/987654/synonym', ['targetId' => $target->id])->assertNotFound();
    }
}
