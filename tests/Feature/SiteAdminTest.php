<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Notice;
use App\Models\UsefulLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notices, settings and useful links, mirroring Winterchilla's AdminApiTest / NoticesApiTest / SettingApiTest
 */
class SiteAdminTest extends TestCase
{
    use RefreshDatabase;

    private function as(Role $role): static
    {
        return $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
    }

    private function notice(array $overrides = []): array
    {
        return $overrides + ['messageHtml' => 'Maintenance tonight', 'hideAfter' => now()->addDays(2)->toIso8601String(), 'type' => 'info'];
    }

    public function testManagementEndpointsRequireStaff(): void
    {
        $endpoints = [['GET', '/notices'], ['POST', '/notices'], ['GET', '/notices/1'], ['PUT', '/notices/1'], ['DELETE', '/notices/1'],
            ['GET', '/useful-links'], ['POST', '/useful-links'], ['GET', '/useful-links/1'], ['PUT', '/useful-links/order'],
            ['GET', '/settings/dev_role_label'], ['PUT', '/settings/dev_role_label']];

        foreach ($endpoints as [$method, $path]) {
            $this->json($method, $path)->assertUnauthorized();
        }
        $this->as(Role::Member);
        foreach ($endpoints as [$method, $path]) {
            $this->json($method, $path)->assertForbidden()->assertJsonStructure(['message']);
        }
    }

    public function testNoticeLifecycle(): void
    {
        $this->as(Role::Staff);

        $created = $this->postJson('/notices', $this->notice())->assertCreated()
            ->assertJsonStructure(['id', 'type', 'messageHtml', 'hideAfter', 'postedBy', 'createdAt']);
        $id = $created->json('id');

        $this->getJson("/notices/$id")->assertOk()->assertJsonPath('messageHtml', 'Maintenance tonight');
        $this->putJson("/notices/$id", $this->notice(['messageHtml' => 'It is over', 'type' => 'success']))
            ->assertOk()->assertJsonPath('type', 'success');
        $this->getJson('/notices?size=100')->assertOk()
            ->assertJsonPath('pagination.itemsPerPage', 100)
            ->assertJsonPath('notices.0.id', $id);
        $this->app['auth']->forgetGuards();
        $this->getJson('/notices/current')->assertOk()->assertJsonPath('0.id', $id);

        $this->as(Role::Staff)->deleteJson("/notices/$id")->assertNoContent();
        $this->getJson("/notices/$id")->assertNotFound();
        $this->deleteJson("/notices/$id")->assertNotFound();
    }

    public function testExpiredNoticesAreNotCurrent(): void
    {
        $user = User::factory()->create();
        Notice::create(['message_html' => 'Old', 'type' => 'info', 'hide_after' => now()->subHour(), 'posted_by' => $user->id]);

        $this->getJson('/notices/current')->assertOk()->assertExactJson([]);
    }

    public function testNoticeValidation(): void
    {
        $this->as(Role::Staff);

        $this->postJson('/notices')->assertUnprocessable()->assertJsonValidationErrors('messageHtml');
        $this->postJson('/notices', $this->notice(['hideAfter' => now()->subDay()->toIso8601String()]))->assertJsonValidationErrors('hideAfter');
        $this->postJson('/notices', $this->notice(['type' => 'nope']))->assertJsonValidationErrors('type');
        $this->postJson('/notices', $this->notice(['messageHtml' => str_repeat('a', 501)]))->assertJsonValidationErrors('messageHtml');
        $this->getJson('/notices?size=500')->assertUnprocessable()->assertJsonValidationErrors('size');
    }

    public function testNoticeHtmlIsSanitized(): void
    {
        $this->as(Role::Staff);

        $this->postJson('/notices', $this->notice(['messageHtml' => '<b>Bold</b> <script>alert(1)</script>']))
            ->assertCreated()
            ->assertJsonPath('messageHtml', fn($html) => str_contains($html, '<strong>Bold</strong>') && !str_contains($html, '<script>'));
    }

    public function testSettings(): void
    {
        $this->as(Role::Staff);

        $this->getJson('/settings/dev_role_label')->assertOk()->assertJsonPath('value', 'developer');
        $this->getJson('/settings/nope')->assertNotFound();
        $this->putJson('/settings/nope', ['value' => 'x'])->assertNotFound();
        $this->putJson('/settings/about_reservations')->assertUnprocessable()->assertJsonValidationErrors('value');

        $this->putJson('/settings/about_reservations', ['value' => '<p>Hello there</p>'])->assertOk()->assertJsonPath('value', '<p>Hello there</p>');
        $this->getJson('/settings/about_reservations')->assertJsonPath('value', '<p>Hello there</p>');
        // Empty resets to the default
        $this->putJson('/settings/about_reservations', ['value' => ''])->assertOk()->assertJsonPath('value', '');
        $this->assertDatabaseMissing('settings', ['name' => 'about_reservations']);
    }

    public function testOnlyDevelopersChangeTheDeveloperRoleLabel(): void
    {
        $this->as(Role::Admin)->putJson('/settings/dev_role_label', ['value' => 'admin'])->assertForbidden();
        $this->as(Role::Developer)->putJson('/settings/dev_role_label', ['value' => 'nonsense'])->assertUnprocessable();
        $this->putJson('/settings/dev_role_label', ['value' => 'staff'])->assertOk()->assertJsonPath('value', 'staff');
    }

    public function testUsefulLinkLifecycle(): void
    {
        $this->as(Role::Staff);

        $id = $this->postJson('/useful-links', ['label' => 'A contract link', 'url' => '/about', 'title' => 'Title', 'minRole' => 'guest'])
            ->assertCreated()->json('id');
        $this->getJson("/useful-links/$id")->assertOk()->assertExactJson(['label' => 'A contract link', 'url' => '/about', 'title' => 'Title', 'minRole' => 'guest']);
        $this->putJson("/useful-links/$id", ['label' => 'Renamed link', 'url' => '/about', 'minRole' => 'staff'])->assertNoContent();
        $this->getJson("/useful-links/$id")->assertJsonPath('label', 'Renamed link')->assertJsonPath('title', '');

        $second = $this->postJson('/useful-links', ['label' => 'Second link', 'url' => '/two', 'minRole' => 'guest'])->json('id');
        $this->putJson('/useful-links/order', ['list' => "$second,$id"])->assertNoContent();
        $this->getJson('/useful-links')->assertOk()->assertJsonPath('0.id', $second)->assertJsonPath('1.id', $id);

        $this->deleteJson("/useful-links/$id")->assertNoContent();
        $this->getJson("/useful-links/$id")->assertNotFound();
        $this->deleteJson("/useful-links/$id")->assertNotFound();
        $this->getJson('/useful-links/987654')->assertNotFound();
    }

    public function testUsefulLinkValidation(): void
    {
        $this->as(Role::Staff);

        $this->postJson('/useful-links')->assertUnprocessable()->assertJsonValidationErrors(['label', 'url', 'minRole']);
        $this->postJson('/useful-links', ['label' => 'Valid label', 'url' => '/about', 'minRole' => 'nonsense'])->assertJsonValidationErrors('minRole');
        $this->putJson('/useful-links/order')->assertUnprocessable()->assertJsonValidationErrors('list');
    }

    public function testSidebarFiltersByRole(): void
    {
        $make = fn(string $label, string $minrole) => UsefulLink::create(['label' => $label, 'url' => "/$label", 'title' => '', 'minrole' => $minrole]);
        $make('guestlink', 'guest');
        $make('memberlink', 'member');
        $make('stafflink', 'staff');

        $labels = fn() => array_column($this->getJson('/useful-links/sidebar')->assertOk()->json(), 'label');
        // Signed out visitors see the links meant for everybody
        $this->assertSame(['guestlink'], $labels());
        $this->as(Role::User);
        $this->assertSame(['guestlink'], $labels());
        $this->as(Role::Member);
        $this->assertSame(['guestlink', 'memberlink'], $labels());
        $this->as(Role::Staff);
        $this->assertSame(['guestlink', 'memberlink', 'stafflink'], $labels());
        $this->assertSame('staff', $this->getJson('/useful-links/sidebar')->json('2.minRole'));
    }
}
