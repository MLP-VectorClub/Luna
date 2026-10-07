<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Enums\ShowType;
use App\Enums\TagType;
use Illuminate\Http\JsonResponse;
use OpenApi\Annotations as OA;

class ConfigController extends Controller
{
    public const ROLE_LABELS = [
        'guest' => 'Guest',
        'user' => 'DeviantArt User',
        'member' => 'Club Member',
        'assistant' => 'Assistant',
        'staff' => 'Staff',
        'admin' => 'Administrator',
        'developer' => 'Site Developer',
    ];

    public const TAG_TYPE_LABELS = [
        TagType::Clothing->value => 'Clothing',
        TagType::Category->value => 'Category',
        TagType::Gender->value => 'Gender',
        TagType::Species->value => 'Species',
        TagType::Character->value => 'Character',
        TagType::Warning->value => 'Warning',
    ];

    public const VECTOR_APP_LABELS = [
        '' => "(don't show)",
        'illustrator' => 'Adobe Illustrator',
        'inkscape' => 'Inkscape',
        'ponyscape' => 'Ponyscape',
    ];

    public const SHOW_TYPE_LABELS = [
        ShowType::Episode->value => 'Episode',
        ShowType::Movie->value => 'Movie',
        ShowType::Short->value => 'Short',
        ShowType::Special->value => 'Special',
    ];

    /**
     * @OA\Get(
     *   path="/config",
     *   description="Constants, validation patterns and client settings that the front end needs before it can render forms. The same for every visitor.",
     *   operationId="GetConfig",
     *   security={},
     *   tags={"configuration"},
     *   @OA\Response(
     *     response="200",
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       required={"tagTypes", "roles", "showTypes", "vectorApps", "maxUploadSize", "patterns", "wsServerHost", "discordInviteLink"},
     *       additionalProperties=false,
     *       @OA\Property(property="tagTypes", type="object", additionalProperties=@OA\AdditionalProperties(type="string"), description="Tag type key to label"),
     *       @OA\Property(property="roles", type="object", additionalProperties=@OA\AdditionalProperties(type="string"), description="Role key to label"),
     *       @OA\Property(property="showTypes", type="object", additionalProperties=@OA\AdditionalProperties(type="string"), description="Show type key to label"),
     *       @OA\Property(property="vectorApps", type="object", additionalProperties=@OA\AdditionalProperties(type="string"), description="Choices for the `p_vectorapp` preference, key to label; the empty key means none"),
     *       @OA\Property(property="maxUploadSize", type="string", example="2 MB"),
     *       @OA\Property(
     *         property="patterns",
     *         type="object",
     *         required={"printableAscii", "hexColor", "username", "episodeTitle"},
     *         additionalProperties=false,
     *         @OA\Property(property="printableAscii", ref="#/components/schemas/RegexPattern"),
     *         @OA\Property(property="hexColor", ref="#/components/schemas/RegexPattern"),
     *         @OA\Property(property="username", ref="#/components/schemas/RegexPattern"),
     *         @OA\Property(property="episodeTitle", ref="#/components/schemas/RegexPattern")
     *       ),
     *       @OA\Property(property="wsServerHost", type="string", nullable=true, description="Address of the websocket server that pushes notifications, null when there is none"),
     *       @OA\Property(property="discordInviteLink", type="string")
     *     )
     *   )
     * )
     */
    public function get(): JsonResponse
    {
        return response()->camelJson([
            'tag_types' => self::TAG_TYPE_LABELS,
            'roles' => self::ROLE_LABELS,
            'show_types' => self::SHOW_TYPE_LABELS,
            'vector_apps' => self::VECTOR_APP_LABELS,
            'max_upload_size' => $this->maxUploadSize(),
            'patterns' => [
                'printable_ascii' => ['source' => '^[ -~\n]+$', 'flags' => ''],
                'hex_color' => ['source' => '^#?([\dA-Fa-f]{6})$', 'flags' => 'u'],
                'username' => ['source' => '^([A-Za-z\-\d]{1,20})$', 'flags' => ''],
                'episode_title' => ['source' => '^([A-Za-z\s]+: )?[ -~]{5,100}$', 'flags' => 'u'],
            ],
            'ws_server_host' => config('services.websocket.host'),
            'discord_invite_link' => 'https://discord.mlpvector.club',
        ])->header('Cache-Control', 'public, max-age=300');
    }

    private function maxUploadSize(): string
    {
        $to_bytes = static function (string $size): int {
            $value = (int) $size;
            return match (strtoupper(substr($size, -1))) {
                'G' => $value * 1024 ** 3,
                'M' => $value * 1024 ** 2,
                'K' => $value * 1024,
                default => $value,
            };
        };
        $sizes = [ini_get('post_max_size'), ini_get('upload_max_filesize')];
        $smallest = $to_bytes($sizes[1]) < $to_bytes($sizes[0]) ? $sizes[1] : $sizes[0];

        return preg_replace('/^(\d+)([GMK])$/i', '$1 $2B', strtoupper($smallest));
    }
}
