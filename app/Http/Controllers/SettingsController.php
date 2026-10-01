<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Utils\HtmlSanitizer;
use App\Utils\SettingsHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;

class SettingsController extends Controller
{
    /**
     * @OA\Schema(
     *   schema="SettingValue",
     *   type="object",
     *   required={"value"},
     *   additionalProperties=false,
     *   @OA\Property(property="value", type="string")
     * )
     * @OA\Get(
     *   path="/settings/{key}",
     *   operationId="GetSettingsKey",
     *   description="Get the value of a global site setting. Requires staff role",
     *   tags={"settings"},
     *   @OA\Parameter(name="key", in="path", required=true, @OA\Schema(type="string", enum={"reservation_rules","about_reservations","dev_role_label"})),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/SettingValue")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Unknown setting key", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(string $key): JsonResponse
    {
        $this->assertKnown($key);

        return response()->json(['value' => SettingsHelper::get($key)]);
    }

    /**
     * @OA\Put(
     *   path="/settings/{key}",
     *   operationId="PutSettingsKey",
     *   description="Update the value of a global site setting, an empty value resets it to the default. Requires staff role",
     *   tags={"settings"},
     *   @OA\Parameter(name="key", in="path", required=true, @OA\Schema(type="string", enum={"reservation_rules","about_reservations","dev_role_label"})),
     *   @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/SettingValue")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/SettingValue")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Insufficient permissions", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Unknown setting key", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $this->assertKnown($key);

        // A missing value is invalid, an empty one resets the setting
        $valid = Validator::make($request->all(), ['value' => ['present', 'nullable', 'string']])->validate();
        $value = trim($valid['value'] ?? '');

        if ($value !== '') {
            switch ($key) {
                case 'reservation_rules':
                case 'about_reservations':
                    $value = HtmlSanitizer::sanitize($value, $key === 'reservation_rules' ? ['li', 'ol'] : ['p']);
                    break;
                case 'dev_role_label':
                    if (!perm(Role::Developer, $request->user()->role)) {
                        throw new AuthorizationException("You cannot change the $key setting");
                    }
                    $labels = array_keys(ConfigController::ROLE_LABELS);
                    if (!in_array($value, $labels, true)) {
                        throw ValidationException::withMessages(['value' => 'The specified role is invalid']);
                    }
                    break;
            }
        }

        SettingsHelper::set($key, $value === '' ? SettingsHelper::DEFAULT_SETTINGS[$key] : $value);

        return response()->json(['value' => SettingsHelper::get($key)]);
    }

    private function assertKnown(string $key): void
    {
        abort_unless(array_key_exists($key, SettingsHelper::DEFAULT_SETTINGS), 404, "Unknown setting $key");
    }
}
