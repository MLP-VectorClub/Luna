<?php

namespace App\Http\Controllers\Testing;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Only routed when APP_ENV=testing. Lets the Winterchilla contract tests (CONTRACT_AUTH=bearer,
 * CONTRACT_LOGIN_URL=/test/login/{id}) sign in as a seeded user without a password.
 */
class TestLoginController extends Controller
{
    public function login(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        return response()->json(['token' => $user->createToken('contract-tests')->plainTextToken]);
    }
}
