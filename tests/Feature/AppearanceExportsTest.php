<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Models\Appearance;
use App\Models\Color;
use App\Models\ColorGroup;
use App\Models\CutieMark;
use App\Models\DeviantartUser;
use App\Models\Tag;
use App\Models\TagChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppearanceExportsTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role = Role::User, array $extra = []): User
    {
        return User::factory()->create(['role' => $role] + $extra);
    }

    private function appearance(array $extra = []): Appearance
    {
        $appearance = Appearance::create($extra + ['label' => 'Export Pony', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => null, 'order' => 1]);
        $group = ColorGroup::create(['appearance_id' => $appearance->id, 'label' => 'Coat', 'order' => 0]);
        Color::create(['group_id' => $group->id, 'label' => 'Outline', 'order' => 0, 'hex' => '#112233']);
        Color::create(['group_id' => $group->id, 'label' => 'Fill', 'order' => 1, 'hex' => '#445566']);
        Color::create(['group_id' => $group->id, 'label' => 'No color', 'order' => 2, 'hex' => null]);

        return $appearance;
    }

    public function testPalette(): void
    {
        $appearance = $this->appearance();

        $json = $this->get("/appearances/{$appearance->id}/palette?format=json")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="Export Pony.json"; filename*=UTF-8\'\'Export%20Pony.json');
        $decoded = json_decode($json->getContent(), true);
        $this->assertSame('1.4', $decoded['Version']);
        $this->assertSame(['Coat' => ['Outline' => '#112233', 'Fill' => '#445566']], $decoded['Export Pony']);

        $gpl = $this->get("/appearances/{$appearance->id}/palette?format=gpl")->assertOk()->getContent();
        $this->assertStringStartsWith("GIMP Palette\nName: Export Pony\nColumns: 6\n#\n# Exported at: ", $gpl);
        $this->assertStringContainsString("\n 17  34  51 Coat | Outline\n 68  85 102 Coat | Fill\n", $gpl);

        $this->getJson("/appearances/{$appearance->id}/palette?format=png")->assertStatus(422);
        $this->getJson("/appearances/{$appearance->id}/palette")->assertStatus(422);
        $this->getJson('/appearances/987654/palette?format=gpl')->assertNotFound();
    }

    public function testPrivateAppearancesStayHidden(): void
    {
        $owner = $this->user();
        $appearance = $this->appearance(['owner_id' => $owner->id, 'private' => true]);

        foreach (['palette?format=json', 'image?type=preview&format=svg'] as $path) {
            $this->getJson("/appearances/{$appearance->id}/$path")->assertForbidden();
        }
        $this->actingAs($this->user(), 'sanctum');
        $this->getJson("/appearances/{$appearance->id}/palette?format=json")->assertForbidden();
        $this->actingAs($owner, 'sanctum');
        $this->getJson("/appearances/{$appearance->id}/palette?format=json")->assertOk();
        $this->actingAs($this->user(Role::Staff), 'sanctum');
        $this->getJson("/appearances/{$appearance->id}/palette?format=json")->assertOk();
    }

    public function testImages(): void
    {
        $appearance = $this->appearance();

        $preview = $this->get("/appearances/{$appearance->id}/image?type=preview&format=svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString("fill='#112233'", $preview->getContent());

        foreach (['left', 'right'] as $facing) {
            $this->get("/appearances/{$appearance->id}/image?type=facing&format=svg&facing=$facing")->assertOk()->assertSee('<svg', false);
        }
        $this->getJson("/appearances/{$appearance->id}/image?type=facing&format=svg&facing=up")->assertStatus(422);

        $png = $this->get("/appearances/{$appearance->id}/image?type=palette&format=png")->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
        $this->assertSame('PNG', substr($png, 1, 3));

        foreach (['', 'type=nonsense&format=png', 'type=palette&format=svg', 'type=preview&format=png', 'type=sprite&format=jpg'] as $query) {
            $this->getJson("/appearances/{$appearance->id}/image?$query")->assertStatus(422);
        }
        $this->getJson('/appearances/987654/image?type=preview&format=svg')->assertNotFound();
        $this->getJson("/appearances/{$appearance->id}/image?type=sprite&format=svg")->assertNotFound();
        $this->getJson("/appearances/{$appearance->id}/image?type=sprite&format=png")->assertNotFound();
    }

    public function testSpriteImages(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user(Role::Staff), 'sanctum');
        $appearance = $this->appearance();
        $this->postJson("/appearances/{$appearance->id}/sprite", ['sprite' => UploadedFile::fake()->image('sprite.png', 300, 300)])->assertOk();

        $this->get("/appearances/{$appearance->id}/image?type=sprite&format=png")->assertRedirect();
        $this->get("/appearances/{$appearance->id}/image?type=sprite&format=svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee("viewBox='0 0 300 300'", false);
        $this->get("/appearances/{$appearance->id}/image?type=palette&format=png")->assertOk();
    }

    public function testCutieMarkDownload(): void
    {
        Storage::fake('public');
        $staff = $this->user(Role::Staff);
        $appearance = $this->appearance();
        $other = $this->appearance(['label' => 'Other Pony']);
        $this->actingAs($staff, 'sanctum')->putJson("/appearances/{$appearance->id}/cutie-marks", ['cutieMarks' => json_encode([['svgdata' => file_get_contents(__DIR__.'/../fixtures/cutiemark.svg'), 'attribution' => 'none', 'rotation' => 0]])])->assertOk();
        $cm = CutieMark::first();
        $path = "/appearances/{$appearance->id}/cutie-marks/{$cm->id}/download";
        $this->app['auth']->forgetGuards();

        $this->get($path)->assertOk()->assertSee('<svg', false)->assertHeader('Content-Disposition');
        $this->getJson("$path?source=1")->assertUnauthorized();
        $this->actingAs($this->user(), 'sanctum')->getJson("$path?source=1")->assertForbidden();
        $this->actingAs($staff, 'sanctum')->get("$path?source=1")->assertOk()->assertSee('<svg', false);
        $this->getJson("/appearances/{$appearance->id}/cutie-marks/987654/download")->assertNotFound();
        $this->getJson("/appearances/{$other->id}/cutie-marks/{$cm->id}/download")->assertNotFound();
    }

    public function testStaffListOfPersonalAppearances(): void
    {
        $this->getJson('/admin/pcg-appearances')->assertUnauthorized();
        $this->actingAs($this->user(), 'sanctum')->getJson('/admin/pcg-appearances')->assertForbidden();

        $owner = $this->user();
        $first = $this->appearance(['label' => 'Mine 1', 'owner_id' => $owner->id, 'guide' => null]);
        $second = $this->appearance(['label' => 'Mine 2', 'owner_id' => $owner->id, 'guide' => null, 'private' => true]);
        $this->appearance(['label' => 'Official']);

        $this->actingAs($this->user(Role::Staff), 'sanctum');
        $response = $this->getJson('/admin/pcg-appearances?size=50')->assertOk();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_column($response->json('appearances'), 'id'));
        $this->assertSame(2, $response->json('pagination.totalItems'));
        $item = collect($response->json('appearances'))->firstWhere('id', $second->id);
        $this->assertTrue($item['private']);
        $this->assertSame($owner->id, $item['ownerId']);
        $this->assertArrayHasKey('createdAt', $item);
        $this->assertArrayHasKey('previewData', $item);

        $this->getJson('/admin/pcg-appearances?size=1&page=2')->assertOk()->assertJsonPath('pagination.currentPage', 2)->assertJsonCount(1, 'appearances');
        $this->getJson('/admin/pcg-appearances?page=0')->assertStatus(422);
    }

    public function testLookupByDeviantArtUuid(): void
    {
        $target = $this->user(Role::Member, ['name' => 'Target']);
        $da = new DeviantartUser(['name' => 'Target', 'avatar_url' => 'https://example.com/a.png']);
        $da->forceFill(['id' => '0f0e0d0c-0b0a-4000-8000-000000000042', 'user_id' => $target->id])->save();
        $path = '/users/da-uuid/0f0e0d0c-0b0a-4000-8000-000000000042';

        $this->getJson($path)->assertUnauthorized();
        $this->actingAs($this->user(Role::Admin), 'sanctum')->getJson($path)->assertForbidden();
        $this->actingAs($this->user(Role::Developer), 'sanctum');
        $this->getJson($path)->assertOk()->assertJsonPath('id', $target->id)->assertJsonPath('name', 'Target');
        $this->getJson('/users/da-uuid/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function testTagChanges(): void
    {
        $appearance = $this->appearance();
        $staff = $this->user(Role::Staff, ['name' => 'Tagger']);
        $tags = [Tag::create(['name' => 'first', 'type' => 'app']), Tag::create(['name' => 'second', 'type' => 'app'])];
        TagChange::create(['tag_id' => $tags[0]->id, 'appearance_id' => $appearance->id, 'user_id' => $staff->id, 'added' => true, 'tag_name' => 'first']);
        TagChange::create(['tag_id' => $tags[1]->id, 'appearance_id' => $appearance->id, 'user_id' => $staff->id, 'added' => false, 'tag_name' => null]);
        $personal = $this->appearance(['label' => 'Personal', 'owner_id' => $this->user()->id, 'guide' => null]);

        $this->getJson("/appearances/{$appearance->id}/tag-changes")->assertUnauthorized();
        $this->actingAs($this->user(), 'sanctum')->getJson("/appearances/{$appearance->id}/tag-changes")->assertForbidden();

        $this->actingAs($staff, 'sanctum');
        $response = $this->getJson("/appearances/{$appearance->id}/tag-changes")->assertOk();
        $this->assertSame([$tags[1]->id, $tags[0]->id], array_column($response->json('changes'), 'tagId'));
        $this->assertSame($staff->id, $response->json('changes.0.user.id'));
        $this->assertNull($response->json('changes.0.tagName'));
        $this->assertFalse($response->json('changes.0.added'));
        $this->assertSame(['id' => $staff->id, 'name' => 'Tagger'], $response->json('changes.1.user'));
        $this->assertSame('first', $response->json('changes.1.tagName'));
        $this->assertSame(2, $response->json('pagination.totalItems'));

        $this->getJson("/appearances/{$appearance->id}/tag-changes?size=0")->assertStatus(422);
        $this->getJson('/appearances/987654/tag-changes')->assertNotFound();
        $this->getJson("/appearances/{$personal->id}/tag-changes")->assertNotFound();
    }
}
