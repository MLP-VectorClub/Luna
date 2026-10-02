<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Appearance;
use App\Models\CutieMark;
use App\Utils\Permission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use OpenApi\Annotations as OA;

/**
 * Downloads that Winterchilla served as files under the appearance URL: swatch files, rendered images and cutie mark SVGs
 */
class AppearanceExportsController extends Controller
{
    /**
     * @OA\Get(
     *   path="/appearances/{id}/palette",
     *   operationId="GetAppearancesIdPalette",
     *   description="Download the appearance's colors as a palette file: `json` is a nested swatch map (appearance, color group, color label to hex; the Illustrator swatch import format) and `gpl` is a GIMP/Inkscape palette. Both are served as attachments. Front ends may also build them from `GET /appearances/{id}/color-groups`.",
     *   tags={"appearances"},
     *   security={},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="query", name="format", required=true, @OA\Schema(type="string", enum={"json", "gpl"})),
     *   @OA\Response(response="200", description="The palette file, as an attachment named after the appearance", @OA\MediaType(mediaType="application/octet-stream", @OA\Schema(type="string", format="binary"))),
     *   @OA\Response(response="403", description="The appearance is private", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Appearance not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Invalid format", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function palette(Request $request, int $id)
    {
        $appearance = $this->visible($id);
        $valid = Validator::make($request->query(), ['format' => ['required', 'string', Rule::in(['json', 'gpl'])]])->validate();

        $groups = $appearance->colorGroups()->with(['colors' => fn($query) => $query->whereNotNull('hex')->orderBy('order')])->get();
        if ($valid['format'] === 'json') {
            $swatches = ['Exported at' => gmdate('Y-m-d H:i:s \G\M\T'), 'Version' => '1.4', $appearance->label => []];
            foreach ($groups as $group) {
                if ($group->colors->isEmpty()) {
                    continue;
                }
                foreach ($group->colors as $color) {
                    $swatches[$appearance->label][$group->label][$color->label] = $color->hex;
                }
            }

            return $this->attachment(json_encode($swatches, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "{$appearance->label}.json", 'application/json');
        }

        $lines = [];
        foreach ($groups as $group) {
            foreach ($group->colors as $color) {
                [$red, $green, $blue] = sscanf(ltrim($color->hex, '#'), '%02x%02x%02x');
                $lines[] = sprintf('%3d %3d %3d %s', $red, $green, $blue, htmlspecialchars("{$group->label} | {$color->label}"));
            }
        }
        $gpl = "GIMP Palette\nName: {$appearance->label}\nColumns: 6\n#\n# Exported at: ".gmdate('Y-m-d H:i:s T')."\n#\n".implode("\n", $lines)."\n";

        return $this->attachment($gpl, "{$appearance->label}.gpl", 'application/octet-stream');
    }

    /**
     * @OA\Get(
     *   path="/appearances/{id}/cutie-marks/{cutieMarkId}/download",
     *   operationId="GetAppearancesIdCutieMarksCutieMarkIdDownload",
     *   description="Download a cutie mark as an SVG attachment: the sanitized, rendered file by default, or the original upload with `source` (staff only).",
     *   tags={"appearances"},
     *   security={},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="path", name="cutieMarkId", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="query", name="source", required=false, @OA\Schema(type="boolean", default=false)),
     *   @OA\Response(response="200", description="The cutie mark file", @OA\MediaType(mediaType="application/octet-stream", @OA\Schema(type="string", format="binary"))),
     *   @OA\Response(response="401", description="`source` was requested but nobody is signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="`source` was requested without the staff role, or the appearance is private", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Appearance or cutie mark not found, or the cutie mark belongs to another appearance", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function cutieMark(Request $request, int $id, int $cutieMarkId)
    {
        $appearance = Appearance::findOrFail($id);
        $cutie_mark = CutieMark::where('appearance_id', $appearance->id)->findOrFail($cutieMarkId);

        if ($request->boolean('source')) {
            $user = $request->user('sanctum');
            if ($user === null) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            if (!$user->isStaff()) {
                throw new AuthorizationException();
            }
        } else {
            $this->assertVisible($appearance);
        }

        $file = $cutie_mark->vectorFile();
        abort_if($file === null, 404, 'The cutie mark has no file');

        // The stored file is the sanitized upload, the original is not kept separately
        return $this->attachment(file_get_contents($file->getPath()), "{$appearance->label} - cutie mark {$cutie_mark->id}.svg", 'image/svg+xml');
    }

    private function visible(int $id): Appearance
    {
        $appearance = Appearance::findOrFail($id);
        $this->assertVisible($appearance);

        return $appearance;
    }

    private function assertVisible(Appearance $appearance): void
    {
        if (!$appearance->private) {
            return;
        }
        $user = Auth::guard('sanctum')->user();
        if ($user !== null && ($user->isStaff() || $appearance->owner_id === $user->id)) {
            return;
        }

        throw new AuthorizationException(trans('errors.color_guide.appearance_private'));
    }

    private function attachment(string $content, string $filename, string $type): Response
    {
        $safe = preg_replace('/[^A-Za-z0-9 ._()\-]/', '_', $filename);

        return response($content, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$safe.'"; filename*=UTF-8\'\''.rawurlencode($filename)]);
    }
}
