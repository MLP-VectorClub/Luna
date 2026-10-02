<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Models\Appearance;
use App\Models\Event;
use App\Models\Notice;
use App\Models\Post;
use App\Models\Show;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Winterchilla renders HTML pages that Celestia has to replace, so every one of its GET page routes (config/routes/pages.php in the
 * Winterchilla repo) is accounted for here: either the Luna endpoint(s) that feed the page are smoke tested, or the route is listed as
 * Celestia-only, a legacy redirect, or a known gap. Adding a page to Winterchilla without listing it here fails the inventory test.
 *
 * Smoke format: "GET /path => status @role" (status 2xx accepts any success, e.g. 204 for empty content), with {placeholders} filled from the seeded data.
 */
class WinterchillaPagesTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'api';
    private const CELESTIA = 'celestia';
    private const REDIRECT = 'redirect';
    private const GAP = 'gap';
    private const TEST_ONLY = 'test-only';
    private const EXTERNAL = 'external';

    /**
     * @return array<string, array{0: string, 1: string, 2?: string[]}> route pattern => [kind, note, smokes]
     */
    private static function pages(): array
    {
        $about = [self::API, 'Member list and server info', ['GET /about/members => 200']];
        $guides = [self::API, 'Guide listing', ['GET /appearances?guide=pony => 200', 'GET /color-guide => 200']];
        $appearance = [self::API, 'Appearance page data', ['GET /appearances/{appearance} => 200', 'GET /appearances/{appearance}/color-groups => 200']];
        $appearance_file = [self::GAP, 'Palette and image exports (.png .svg .json .gpl) of an appearance have no Luna endpoint, only the JSON appearance and the sprite/cutie mark file URLs exist'];
        $legacy_user = [self::REDIRECT, 'Old @name URL, Celestia redirects after resolving the name', ['GET /users/da/{username} => 200']];
        $show = [self::API, 'Show page data', ['GET /show/{show} => 200', 'GET /posts?showId={show}&kind=request => 200']];
        $personal = [self::API, 'Personal guide', ['GET /users/{user}/personal-guide/appearances => 200', 'GET /users/{user}/personal-guide/slots => 2xx @user']];
        $point_history = [self::API, 'Point history, owner or staff', ['GET /users/{user}/personal-guide/point-history => 200 @user']];
        $profile = [self::API, 'Profile data', ['GET /users/{user}/profile => 200']];
        $oauth = [self::EXTERNAL, 'OAuth sign in is Luna-side (Socialite redirect and token exchange), not smoke tested: it needs real provider tokens'];
        $celestia = fn(string $note) => [self::CELESTIA, $note];
        $test_only = [self::TEST_ONLY, 'Registered only in Winterchilla TEST_MODE'];

        return [
            '/about' => $about,
            '/about/browser/[i:session]?' => $celestia('Browser/session details are shown client side, the session list is GET /users/tokens'),
            '/browser/[i:session]?' => $celestia('Alias of /about/browser'),
            '/about/privacy' => $celestia('Static page'),
            '/admin' => $celestia('Staff dashboard, links to the pages below'),
            '/logs/[i]?' => [self::API, 'Log list (alias)', ['GET /admin/logs => 200 @staff']],
            '/logs/[i]' => [self::API, 'Log entry (alias)', ['GET /admin/logs/{log} => 200 @staff']],
            '/admin/logs/[i]?' => [self::API, 'Log list and entry', ['GET /admin/logs => 200 @staff', 'GET /admin/logs/{log} => 200 @staff']],
            '/admin/usefullinks' => [self::API, 'Useful links management', ['GET /useful-links => 200 @staff']],
            '/admin/wsdiag' => $celestia('Websocket diagnostics, Luna has no websocket server'),
            '/admin/pcg-appearances/[i]?' => [self::GAP, 'Staff list of all personal guide appearances has no Luna endpoint (only per user GET /users/{id}/personal-guide/appearances)'],
            '/admin/notices' => [self::API, 'Notice management', ['GET /notices => 200 @staff', 'GET /notices/{notice} => 200 @staff']],
            '/blending' => $celestia('Client side tool'),
            '/[cg]/blending' => $celestia('Client side tool'),
            '/[cg]/blending-reverse' => $celestia('Client side tool'),
            '/[cg]/picker' => $celestia('Client side tool'),
            '/[cg]/picker/frame' => $celestia('Client side tool'),
            '/[cg]' => $guides,
            '/[cg]/preferred' => [self::API, 'Redirects to the preferred guide', ['GET /user-prefs/me => 200 @user']],
            '/[cg]/[guide:guide]?/[i]?' => $guides,
            '/[cg]/[guide:guide]?/full' => [self::API, 'Full list', ['GET /appearances/full?guide=pony => 200']],
            '/[cg]/[guide:guide]?/changes/[i]?' => [self::API, 'Major changes list', ['GET /color-guide/major-changes?guide=pony => 200']],
            '/[cg]/[guide:guide]?/[v]' => $guides,
            '/[cg]/[guide:guide]?/[v]/[i:id]-?' => $appearance,
            '/[cg]/[guide:guide]?/[v]/[i:id]-[adi]' => $appearance,
            '/[cg]/[guide:guide]?/[v]/[adi]-[i:id]' => $appearance,
            '/[cg]/[guide:guide]?/[v]/[i:id][cgimg:type]?.[cgext:ext]' => $appearance_file,
            '/[cg]/[guide:guide]?/tag-changes/[i:id][adi]?' => [self::GAP, 'Tag change history of an appearance has no Luna endpoint (changes are only written to the shared tag_changes table)'],
            '/users/[i:user_id]/[cg]/[guide:guide]?/[v]/[i:id](-[adi]?)' => $appearance,
            '/users/[i:user_id]/[cg]/[guide:guide]?/[v]/[adi]-[i:id]' => $appearance,
            '/users/[i:user_id]/[cg]/[guide:guide]?/[v]/[i:id][cgimg:type]?.[cgext:ext]' => $appearance_file,
            '/components' => $celestia('Style guide of the old UI'),
            '/docs' => [self::API, 'API docs', ['GET /generated/api-docs.json => 200']],
            '/[cg]/[guide:guide]?/tags/[i]?' => [self::API, 'Tag list', ['GET /tags => 200 @staff', 'GET /tags/{tag} => 200 @staff']],
            '/[cg]/cutiemark/[i:id].svg' => [self::API, 'Cutie mark file, Luna exposes the file URL (viewUrl) on the appearance and `rendered` on GET /appearances/{id}/cutie-marks', ['GET /appearances/{appearance}/cutie-marks => 200 @staff']],
            '/[cg]/cutiemark/download/[i:id][adi]?' => [self::GAP, 'Download of the source or tokenized cutie mark file has no Luna endpoint, only the sanitized file URL'],
            '/da-auth' => $oauth,
            '/da-auth/begin' => $oauth,
            '/da-auth/end' => $oauth,
            '/discord-connect/begin' => $oauth,
            '/discord-connect/end' => $oauth,
            '/episode/[gen:gen]?/[epid:id]' => $show,
            '/episode/[gen:gen]?/[epid:id]-?' => $show,
            '/episode/[gen:gen]?/[epid:id]-[adi]?' => $show,
            '/episode/latest' => [self::API, 'Latest episode', ['GET /show/latest => 200']],
            '/episodes/[i]?' => [self::API, 'Episode list', ['GET /show?types[]=episode&order=series => 200']],
            '/[st]/[i:id][adi]?' => $show,
            '/movies/[i]?' => [self::API, 'Movie list', ['GET /show?types[]=movie&order=series => 200']],
            '/show' => [self::API, 'Show list', ['GET /show?types[]=episode&order=series => 200']],
            '/eqg/[i:id]' => [self::REDIRECT, 'Old EQG URL, resolved with the show list', ['GET /show?types[]=episode&order=series => 200']],
            '/eqg/[adi:id]' => [self::REDIRECT, 'Old EQG URL, resolved with the show list', ['GET /show?types[]=episode&order=series => 200']],
            '/events/[i]?' => [self::API, 'Event list', ['GET /events => 200']],
            '/event/[i:id][adi]?' => [self::API, 'Event page', ['GET /events/{event} => 200']],
            '/muffin-rating' => $celestia('Decorative SVG computed from the requested width, can be drawn client side'),
            '/s/[rr:thing]?/[ai:id]' => [self::API, 'Post share link, redirects to the post location', ['GET /posts/{post}/location => 200']],
            '/' => [self::API, 'Homepage: latest show and sidebar', ['GET /show/latest => 200', 'GET /useful-links/sidebar => 200', 'GET /notices/current => 200']],
            '/users' => [self::API, 'Member list', ['GET /users => 200 @staff', 'GET /about/members => 200']],
            '/users/[i:user_id](-[uc]?)?' => $profile,
            '/[sett]' => [self::API, 'Own profile shortcut', ['GET /users/me => 200 @user']],
            '/u/[uuid:uuid]' => [self::GAP, 'Profile lookup by DeviantArt UUID has no Luna endpoint (only by id and by DeviantArt username)'],
            '/users/[i:user_id]/contrib/[ad:type]/[i]?' => [self::API, 'Contributions list', ['GET /users/{user}/contributions/finished-posts => 200']],
            '/users/[i:id]?/account' => [self::API, 'Account settings', ['GET /users/me => 200 @user', 'GET /users/{user}/preferences/cg_itemsperpage => 200 @user', 'GET /users/tokens => 200 @user']],
            '/users/verify' => [self::API, 'E-mail verification link', ['GET /users/email/verify/{user}/invalid => 403 @user']],
            '/@[un:name]' => $legacy_user,
            '/u/[un:name]?' => $legacy_user,
            '/@[un:name]/contrib/[ad:type]/[i]?' => $legacy_user,
            '/@[un:name]/[cg]/[guide:guide]?/[v]' => $legacy_user,
            '/@[un:name]/[cg]/[i]?' => $legacy_user,
            '/@[un:name]/[cg]/slot-history/[i]?' => $legacy_user,
            '/@[un:name]/[cg]/point-history/[i]?' => $legacy_user,
            '/@[un:name]/[cg]/[guide:guide]?/[v]/[i:id](-[adi]?)?' => $legacy_user,
            '/@[un:name]/[cg]/[guide:guide]?/[v]/[adi]-[i:id]' => $legacy_user,
            '/@[un:name]/[cg]/[guide:guide]?/[v]/[i:id][cgimg:type]?.[cgext:ext]' => $legacy_user,
            '/@[un:name]/[cg]/[guide:guide]?/sprite(-colors)?/[i:id][adi]?' => [self::REDIRECT, 'Old sprite URL, resolved to the sprite of the appearance', ['GET /appearances/{appearance}/sprite => 404']],
            '/users/[i:user_id]/[cg]/[guide:guide]?/[v]' => $personal,
            '/users/[i:user_id]/[cg]/[i]?' => $personal,
            '/users/[i:user_id]/[cg]/slot-history/[i]?' => $point_history,
            '/users/[i:user_id]/[cg]/point-history/[i]?' => $point_history,
            '/manifest' => $celestia('Web app manifest belongs to the front end'),
            '/diagnose/ex/[a:type]' => $celestia('Developer diagnostics of the old app'),
            '/diagnose/lt/[i:time]' => $celestia('Developer diagnostics of the old app'),
            '/test-login/[i:user_id]' => $test_only,
            '/test-dialog' => $test_only,
            '/test-oauth/[deviantart|discord:provider]/oauth2/authorize' => $test_only,
            '/test-oauth/[deviantart|discord:provider]/decide' => $test_only,
            '/test-oauth/deviantart/api/v1/oauth2/user/whoami' => $test_only,
            '/test-oauth/discord/api/users/@me' => $test_only,
            '/test-oauth/discord/api/guilds/[i:guild_id]/members/[i:user_id]' => $test_only,
        ];
    }

    private function winterchillaRoutes(): ?array
    {
        $file = dirname(__DIR__, 3).'/Winterchilla/config/routes/pages.php';
        if (!is_file($file)) {
            return null;
        }
        preg_match_all('/\$page_route\(\'([^\']+)\'/', file_get_contents($file), $matches);

        return array_values(array_unique($matches[1]));
    }

    public function testEveryWinterchillaPageIsAccountedFor(): void
    {
        $routes = $this->winterchillaRoutes();
        if ($routes === null) {
            $this->markTestSkipped('The Winterchilla repository is not next to Luna');
        }

        $pages = self::pages();
        $this->assertSame([], array_values(array_diff($routes, array_keys($pages))), 'Winterchilla pages missing from WinterchillaPagesTest::pages()');
        $this->assertSame([], array_values(array_diff(array_keys($pages), $routes)), 'WinterchillaPagesTest::pages() lists routes that Winterchilla no longer has');
    }

    public function testPagesBackedByLunaHaveSmokeTests(): void
    {
        foreach (self::pages() as $route => $page) {
            $kind = $page[0];
            $smokes = $page[2] ?? [];
            if (in_array($kind, [self::API, self::REDIRECT], true)) {
                $this->assertNotEmpty($smokes, "$route is marked $kind but has no smoke test");
            } else {
                $this->assertSame([], $smokes, "$route is marked $kind and must not have smoke tests");
            }
        }
    }

    public function testSmokeTheLunaEndpointsBehindThePages(): void
    {
        $staff = User::factory()->create(['role' => Role::Staff]);
        $user = User::factory()->create(['role' => Role::User, 'name' => 'Pageuser']);
        $da = new \App\Models\DeviantartUser(['name' => 'Pageuser', 'avatar_url' => 'https://example.com/a.png']);
        $da->forceFill(['id' => '0f0e0d0c-0b0a-4000-8000-000000000042', 'user_id' => $user->id])->save();
        $show = Show::create(['type' => 'episode', 'season' => 1, 'episode' => 1, 'title' => 'Friendship is Magic', 'posted_by' => $staff->id, 'airs' => '2010-10-10 15:00', 'no' => 1]);
        $appearance = Appearance::create(['label' => 'Smoke Pony', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => null, 'order' => 1]);
        $post = Post::create(['show_id' => $show->id, 'type' => 'chr', 'requested_by' => $user->id, 'requested_at' => now(), 'preview' => 'https://example.com/p.png', 'fullsize' => 'https://example.com/f.png', 'label' => 'A post']);
        $event = Event::create(['name' => 'Smoke Event', 'entry_role' => 'user', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'desc_src' => 'Rules', 'desc_rend' => '<p>Rules</p>', 'added_by' => $staff->id]);
        $notice = Notice::create(['type' => 'info', 'message_html' => 'Hello', 'hide_after' => now()->addDay(), 'posted_by' => $staff->id]);
        $tag = Tag::create(['name' => 'smoke tag', 'type' => 'app']);
        $log = \App\Utils\LogWriter::record('appearances', ['action' => 'add', 'id' => $appearance->id, 'label' => 'Smoke Pony', 'order' => 1, 'notes' => null, 'guide' => 'pony']);
        $replacements = [
            '{appearance}' => $appearance->id, '{show}' => $show->id, '{post}' => $post->id, '{event}' => $event->id,
            '{notice}' => $notice->id, '{tag}' => $tag->id, '{log}' => $log->id, '{user}' => $user->id, '{username}' => 'Pageuser',
        ];

        $smoked = 0;
        $seen = [];
        foreach (self::pages() as $route => $page) {
            foreach ($page[2] ?? [] as $smoke) {
                if (isset($seen[$smoke])) {
                    continue;
                }
                $seen[$smoke] = true;
                $this->assertSame(1, preg_match('~^(GET) (\S+) => (\d{3}|2xx)(?: @(staff|user))?$~', $smoke, $m), "Bad smoke definition $smoke");
                [, , $path, $status] = $m;
                $this->app['auth']->forgetGuards();
                if (isset($m[4])) {
                    $this->actingAs($m[4] === 'staff' ? $staff : $user, 'sanctum');
                }
                $response = $this->getJson(strtr($path, $replacements));
                if ($status === '2xx') {
                    $this->assertTrue($response->isSuccessful(), "$route: $smoke answered {$response->getStatusCode()}");
                } else {
                    $this->assertSame((int) $status, $response->getStatusCode(), "$route: $smoke answered {$response->getStatusCode()}");
                }
                $smoked++;
            }
        }
        $this->assertGreaterThan(30, $smoked);
    }

    public function testKnownGaps(): void
    {
        $gaps = [];
        foreach (self::pages() as $route => [$kind, $note]) {
            if ($kind === self::GAP) {
                $gaps[] = "$route: $note";
            }
        }
        $this->assertNotEmpty($gaps);
        $this->markTestSkipped("Winterchilla pages without a Luna equivalent (".count($gaps)."):\n  ".implode("\n  ", $gaps));
    }
}
