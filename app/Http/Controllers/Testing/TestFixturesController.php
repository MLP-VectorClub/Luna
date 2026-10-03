<?php

namespace App\Http\Controllers\Testing;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Utils\DeviantArt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Only routed when APP_ENV=testing. What the browser UI tests of Winterchilla (run against Celestia) set up through files and Redis there:
 * a cookie session for a seeded user, DeviantArt deviations with local image URLs and club gallery acceptance
 */
class TestFixturesController extends Controller
{
    /**
     * Signs the seeded user in with a cookie session, like the sign-in of the front end does. The browser has to call this on the API
     * (or through the front end's /api proxy) so that it receives the session cookie. Redirects to `to` when given
     */
    public function session(Request $request, int $id)
    {
        Auth::guard('web')->login(User::findOrFail($id));
        $request->session()->regenerate();

        $to = $request->query('to');

        return is_string($to) && $to !== '' ? redirect($to) : response()->noContent();
    }

    /**
     * Makes a deviation (or Sta.sh submission) known without asking DeviantArt, like the deviation cache entries Winterchilla's tests seed in Redis
     */
    public function deviation(Request $request, string $id): JsonResponse
    {
        $valid = $request->validate([
            'preview' => ['required', 'string'],
            'fullsize' => ['sometimes', 'nullable', 'string'],
            'title' => ['sometimes', 'string'],
            'author' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', 'nullable', 'string'],
            'provider' => ['sometimes', 'in:fav.me,sta.sh'],
        ]);
        $provider = $valid['provider'] ?? 'fav.me';
        Cache::forever("deviation:$provider:$id", [
            'provider' => $provider,
            'id' => $id,
            'preview' => $valid['preview'],
            'fullsize' => $valid['fullsize'] ?? $valid['preview'],
            'title' => $valid['title'] ?? "Deviation $id",
            'author' => $valid['author'] ?? null,
            'type' => $valid['type'] ?? null,
        ]);

        return response()->json(['id' => $id, 'provider' => $provider], 201);
    }

    public function forgetDeviation(string $id): Response
    {
        Cache::forget("deviation:fav.me:$id");
        Cache::forget("deviation:sta.sh:$id");

        return response()->noContent();
    }

    public function acceptIntoClub(string $id): Response
    {
        Cache::forever(DeviantArt::CLUB_GALLERY_CACHE_PREFIX.$id, true);

        return response()->noContent();
    }

    public function rejectFromClub(string $id): Response
    {
        Cache::forget(DeviantArt::CLUB_GALLERY_CACHE_PREFIX.$id);

        return response()->noContent();
    }
}
