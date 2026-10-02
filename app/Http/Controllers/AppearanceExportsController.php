<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Appearance;
use App\Models\CutieMark;
use App\Utils\AppearanceImages;
use App\Utils\ColorGuideHelper;
use App\Utils\PaletteImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
            $swatches = [];
            foreach ($groups as $group) {
                foreach ($group->colors as $color) {
                    $swatches[$group->label][$color->label] = $color->hex;
                }
            }

            return $this->attachment(AppearanceImages::swatchJson($appearance->label, $swatches), "{$appearance->label}.json", 'application/json');
        }

        $colors = [];
        foreach ($groups as $group) {
            foreach ($group->colors as $color) {
                [$red, $green, $blue] = sscanf(ltrim($color->hex, '#'), '%02x%02x%02x');
                $colors[] = [$red, $green, $blue, "{$group->label} | {$color->label}"];
            }
        }

        return $this->attachment(AppearanceImages::gimpPalette($appearance->label, $colors), "{$appearance->label}.gpl", 'application/octet-stream');
    }

    /**
     * @OA\Get(
     *   path="/appearances/{id}/image",
     *   operationId="GetAppearancesIdImage",
     *   description="A rendered image of the appearance. Combinations: `palette` as `png` (the colors listed next to the sprite), `sprite` as `png` (the uploaded sprite) or `svg` (traced from it), `preview` as `svg` (the four-color preview) and `facing` as `svg` (the body-orientation graphic, colored with the appearance's colors; pick the side with `facing`). Everything else is a 422, a missing sprite a 404. The server may answer with a redirect to a cache-busted URL of the same image.",
     *   tags={"appearances"},
     *   security={},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="query", name="type", required=true, @OA\Schema(type="string", enum={"palette", "sprite", "preview", "facing"})),
     *   @OA\Parameter(in="query", name="format", required=true, @OA\Schema(type="string", enum={"png", "svg"})),
     *   @OA\Parameter(in="query", name="facing", required=false, @OA\Schema(type="string", default="left", enum={"left", "right"})),
     *   @OA\Response(response="200", description="The image", @OA\MediaType(mediaType="image/png", @OA\Schema(type="string", format="binary")), @OA\MediaType(mediaType="image/svg+xml", @OA\Schema(type="string"))),
     *   @OA\Response(response="403", description="The appearance is private", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Appearance not found, or it has no sprite", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Unsupported type and format combination", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function image(Request $request, int $id)
    {
        $appearance = $this->visible($id);
        $valid = Validator::make($request->query(), [
            'type' => ['required', 'string', Rule::in(['palette', 'sprite', 'preview', 'facing'])],
            'format' => ['required', 'string', Rule::in(['png', 'svg'])],
            'facing' => ['sometimes', 'string', Rule::in(['left', 'right'])],
        ])->validate();
        $combination = "{$valid['type']}.{$valid['format']}";
        if (!in_array($combination, ['palette.png', 'sprite.png', 'sprite.svg', 'preview.svg', 'facing.svg'], true)) {
            throw ValidationException::withMessages(['format' => "The image type {$valid['type']} is not available as {$valid['format']}."]);
        }
        $headers = ['Cache-Control' => 'public, max-age=300'];

        switch ($combination) {
            case 'preview.svg':
                return response(AppearanceImages::previewSvg($appearance->preview_data), 200, $headers + ['Content-Type' => 'image/svg+xml']);

            case 'facing.svg':
                $rows = DB::table('color_groups as cg')
                    ->leftJoin('colors as c', 'c.group_id', '=', 'cg.id')
                    ->where('cg.appearance_id', $appearance->id)
                    ->orderBy('cg.order')->orderBy('c.label')
                    ->get(['cg.label as cglabel', 'c.label as clabel', 'c.hex'])
                    ->map(fn($row) => (array) $row)->all();

                return response(AppearanceImages::facingSvg($valid['facing'] ?? 'left', $rows), 200, $headers + ['Content-Type' => 'image/svg+xml']);
        }

        $sprite = $appearance->spriteFile();
        if ($valid['type'] === 'sprite' && $sprite === null) {
            abort(404, "There's no sprite image for appearance #{$appearance->id}");
        }

        switch ($combination) {
            case 'sprite.png':
                return $appearance->private
                    ? response()->file($sprite->getPath(), ['Cache-Control' => 'private, must-revalidate'])
                    : redirect(ColorGuideHelper::mapSprite($appearance, false, $sprite)['path']);

            case 'sprite.svg':
                $svg = Cache::remember('sprite_svg:'.sha1_file($sprite->getPath()), now()->addDay(), fn() => AppearanceImages::spriteSvg(AppearanceImages::traceSprite($sprite->getPath())));

                return response($svg, 200, $headers + ['Content-Type' => 'image/svg+xml']);

            default: // palette.png
                $groups = $appearance->colorGroups()->with('colors')->get()->map(fn($group) => [
                    'label' => $group->label,
                    'colors' => $group->colors->sortBy('order')->map(fn($color) => ['label' => $color->label, 'hex' => $color->hex])->values()->all(),
                ])->all();
                $slug = trim(preg_replace('/-+/', '-', preg_replace('/[^A-Za-z\d\-]/', '-', $appearance->label)), '-');
                $owner = $appearance->owner_id !== null ? "/users/{$appearance->owner_id}" : '';
                $guide = $appearance->owner_id !== null ? '' : "{$appearance->guide?->value}/";
                $source = rtrim((string) config('app.frontend_url'), '/')."$owner/cg/{$guide}v/{$appearance->id}-$slug";
                // Winterchilla never draws the sprite into the palette image (it looks for it at a doubled path), which is kept until decided otherwise
                $draw_sprite = (bool) config('colorguide.palette_image_draws_sprite');
                $sprite_path = $draw_sprite ? $sprite?->getPath() : null;
                $key = 'palette_png:'.sha1(json_encode([$appearance->label, $groups, $sprite_path, $sprite_path !== null ? sha1_file($sprite_path) : null, $source]));
                $png = Cache::remember($key, now()->addDay(), fn() => base64_encode(PaletteImage::render(
                    $appearance->label,
                    $sprite_path,
                    $groups,
                    now()->format('l, jS F Y, H:i:s T'),
                    $source,
                )));

                return response(base64_decode($png), 200, $headers + ['Content-Type' => 'image/png']);
        }
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
