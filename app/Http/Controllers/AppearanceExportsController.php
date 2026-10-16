<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Appearance;
use App\Models\CutieMark;
use App\Utils\AppearanceImages;
use App\Utils\SpriteColorCheck;
use App\Utils\SpriteImage;
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
     *   @OA\Parameter(in="query", name="token", required=false, @OA\Schema(ref="#/components/schemas/AppearanceToken")),
     *   @OA\Response(response="200", description="The palette file, as an attachment named after the appearance", @OA\MediaType(mediaType="application/octet-stream", @OA\Schema(type="string", format="binary"))),
     *   @OA\Response(response="403", description="The appearance is private", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Appearance not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Invalid format", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function palette(Request $request, int $id)
    {
        $appearance = $this->visible($request, $id);
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
     * @OA\Schema(
     *   schema="SpriteColorCheck",
     *   required={"palette", "colors", "summary"},
     *   @OA\Property(property="palette", description="Every color of the appearance and whether the sprite uses it", type="array", @OA\Items(
     *     required={"group", "label", "hex", "status", "optional", "pixels"},
     *     @OA\Property(property="group", type="string"),
     *     @OA\Property(property="label", type="string"),
     *     @OA\Property(property="hex", type="string", example="#D8D8D8"),
     *     @OA\Property(property="opacity", description="The opacity the color is listed with, in percent", type="integer", nullable=true),
     *     @OA\Property(property="blendMode", description="The blend mode the color is listed with, normal when only an opacity is set", nullable=true, allOf={@OA\Schema(ref="#/components/schemas/BlendMode")}),
     *     @OA\Property(property="status", description="`translucent` means the color only appears semi-transparent", type="string", enum={"present", "translucent", "missing"}),
     *     @OA\Property(property="optional", description="The color is for another form or use (its name has a qualifier in parentheses), so it is not expected in every sprite", type="boolean"),
     *     @OA\Property(property="pixels", type="integer")
     *   )),
     *   @OA\Property(property="colors", description="Every color in the sprite, most used first", type="array", @OA\Items(
     *     required={"hex", "pixels", "translucentPixels", "kind", "labels"},
     *     @OA\Property(property="hex", type="string", example="#D8D8D8"),
     *     @OA\Property(property="pixels", description="Fully opaque pixels", type="integer"),
     *     @OA\Property(property="translucentPixels", description="Semi-transparent pixels", type="integer"),
     *     @OA\Property(property="kind", description="`listed` is a color of the appearance, `blend` is explained as a mix of two listed colors, `near` is almost a listed color (probably a mistake), `unknown` is neither; the `Speck` variants cover fewer than 5 pixels; `translucent` colors are only drawn semi-transparent and are not judged", type="string", enum={"listed", "blend", "near", "unknown", "nearSpeck", "unknownSpeck", "translucent"}),
     *     @OA\Property(property="labels", description="Names of the appearance colors with this value", type="array", @OA\Items(type="string")),
     *     @OA\Property(property="nearest", description="The closest listed color, for `near` and `unknown` colors", type="object", nullable=true,
     *       @OA\Property(property="hex", type="string"), @OA\Property(property="label", type="string"), @OA\Property(property="distance", type="number")),
     *     @OA\Property(property="blendOf", description="The two listed colors a `blend` color is made from", type="array", nullable=true, @OA\Items(type="string")),
     *     @OA\Property(property="blendMode", description="How a `blend` color is made from its two colors: `mix` is any mix of the two. Colors listed with an opacity or blend mode are tried first, as that layer over a color of the sprite", type="string", nullable=true, enum={"mix", "normal", "multiply", "screen", "overlay", "darken", "lighten", "color-dodge", "color-burn", "hard-light", "soft-light", "difference", "exclusion"}),
     *     @OA\Property(property="blendOpacity", description="The opacity of the layer in a blend made from a listed color", type="integer", nullable=true)
     *   )),
     *   @OA\Property(property="summary", required={"missing", "translucentOnly", "near", "unknown", "specks", "ok"},
     *     @OA\Property(property="missing", description="Required colors (not optional) that the sprite does not use at all", type="integer"),
     *     @OA\Property(property="translucentOnly", type="integer"),
     *     @OA\Property(property="near", type="integer"),
     *     @OA\Property(property="unknown", type="integer"),
     *     @OA\Property(property="specks", type="integer"),
     *     @OA\Property(property="ok", description="Nothing missing, nearly matching or unknown", type="boolean"))
     * )
     * @OA\Get(
     *   path="/appearances/{id}/sprite/colors",
     *   operationId="GetAppearancesIdSpriteColors",
     *   description="Checks the colors of the appearance's sprite against the colors listed for the appearance. Soft edges and glows that are blends of listed colors (normal, multiply, screen) are recognised, and colors only drawn semi-transparent are not judged.",
     *   tags={"appearances"},
     *   security={},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="query", name="token", required=false, @OA\Schema(ref="#/components/schemas/AppearanceToken")),
     *   @OA\Response(response="200", description="The comparison", @OA\JsonContent(ref="#/components/schemas/SpriteColorCheck")),
     *   @OA\Response(response="403", description="The appearance is private", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Appearance not found, or it has no sprite", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function spriteColors(Request $request, int $id)
    {
        $appearance = $this->visible($request, $id);
        $sprite = $appearance->spriteFile();
        if ($sprite === null) {
            abort(404, "There's no sprite image for appearance #{$appearance->id}");
        }

        $palette = [];
        foreach ($appearance->colorGroups()->with(['colors' => fn($query) => $query->whereNotNull('hex')->orderBy('order')])->get() as $group) {
            foreach ($group->colors as $color) {
                $palette[] = ['group' => $group->label, 'label' => $color->label, 'hex' => $color->hex, 'opacity' => $color->opacity, 'blendMode' => $color->opacity !== null || $color->blend_mode !== null ? $color->blendMode()->value : null];
            }
        }
        $shared = DB::table('colors as c')->join('color_groups as cg', 'cg.id', '=', 'c.group_id')
            ->where('cg.appearance_id', 0)->whereNotNull('c.hex')->pluck('c.hex')->map(fn($hex) => strtoupper($hex))->all();
        $result = SpriteColorCheck::check(SpriteImage::fromFile($sprite->getPath())->colorCounts(), $palette, array_merge(SpriteColorCheck::SHARED_HEXES, $shared));

        return response()->camelJson($result, 200, $appearance->private ? ['Cache-Control' => 'private, no-cache'] : ['Cache-Control' => 'public, max-age=60']);
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
     *   @OA\Parameter(in="query", name="token", required=false, @OA\Schema(ref="#/components/schemas/AppearanceToken")),
     *   @OA\Response(response="200", description="The image", @OA\MediaType(mediaType="image/png", @OA\Schema(type="string", format="binary")), @OA\MediaType(mediaType="image/svg+xml", @OA\Schema(type="string"))),
     *   @OA\Response(response="403", description="The appearance is private", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Appearance not found, or it has no sprite", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Unsupported type and format combination", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function image(Request $request, int $id)
    {
        $appearance = $this->visible($request, $id);
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
                $svg = Cache::remember('sprite_svg:2:'.sha1_file($sprite->getPath()), now()->addDay(), fn() => SpriteImage::fromFile($sprite->getPath())->toSvg());

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
                $sprite_path = $sprite?->getPath();
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
     *   @OA\Parameter(in="query", name="token", required=false, @OA\Schema(ref="#/components/schemas/AppearanceToken")),
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
            $this->assertVisible($request, $appearance);
        }

        $file = $cutie_mark->vectorFile();
        abort_if($file === null, 404, 'The cutie mark has no file');

        // The stored file is the sanitized upload, the original is not kept separately
        return $this->attachment(file_get_contents($file->getPath()), "{$appearance->label} - cutie mark {$cutie_mark->id}.svg", 'image/svg+xml');
    }

    /**
     * @OA\Get(
     *   path="/cutie-marks/{cutieMarkId}/{disposition}",
     *   operationId="GetCutieMarksCutieMarkIdDisposition",
     *   description="A cutie mark file by its own ID, for the URLs of the previous site: `image` serves the SVG inline, `download` as an attachment.",
     *   tags={"appearances"},
     *   security={},
     *   @OA\Parameter(in="path", name="cutieMarkId", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="path", name="disposition", required=true, @OA\Schema(type="string", enum={"image", "download"})),
     *   @OA\Parameter(in="query", name="token", required=false, @OA\Schema(ref="#/components/schemas/AppearanceToken")),
     *   @OA\Response(response="200", description="The cutie mark file", @OA\MediaType(mediaType="image/svg+xml", @OA\Schema(type="string", format="binary"))),
     *   @OA\Response(response="403", description="The appearance is private", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Cutie mark not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function cutieMarkById(Request $request, int $cutieMarkId, string $disposition)
    {
        $cutie_mark = CutieMark::findOrFail($cutieMarkId);
        $appearance = Appearance::findOrFail($cutie_mark->appearance_id);
        $this->assertVisible($request, $appearance);

        $file = $cutie_mark->vectorFile();
        abort_if($file === null, 404, 'The cutie mark has no file');
        $svg = file_get_contents($file->getPath());
        if ($disposition === 'image') {
            return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
        }

        return $this->attachment($svg, "{$appearance->label} - cutie mark {$cutie_mark->id}.svg", 'image/svg+xml');
    }

    private function visible(Request $request, int $id): Appearance
    {
        $appearance = Appearance::findOrFail($id);
        $this->assertVisible($request, $appearance);

        return $appearance;
    }

    private function assertVisible(Request $request, Appearance $appearance): void
    {
        if (!$appearance->private || $appearance->tokenMatches($request->query('token'))) {
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
