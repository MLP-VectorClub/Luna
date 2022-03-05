<?php

namespace App\Console\Commands;

use App\Enums\GuideName;
use App\Enums\UserPrefKey;
use App\Utils\UserPrefHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GenerateEnumDocblocks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate-enum-docs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the docblock file for the UserPref schema';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->generateUserPrefs(app_path('Helpers/types/UserPrefs.php'));
        $this->generateGuideEntryCounts(app_path('Helpers/types/GuideEntryCounts.php'));
    }

    protected function getGenerationComment(): string
    {
        return <<<PHP
        # Generated OpenAPI annotations for the darkaonline/l5-swagger package
        # This file should not be edited directly, generate it using `php artisan {$this->signature}`"
        PHP;
    }

    /**
     * Execute the console command.
     *
     * @param  string  $file_loc
     * @return mixed
     */
    protected function generateUserPrefs(string $file_loc): int
    {
        $properties = Collection::make(UserPrefKey::cases())->map(function (UserPrefKey $key) {
            $type_data = UserPrefHelper::type($key);
            $property = sprintf(" *     property=\"%s\",\n", $key->value);
            $type = '';
            if (isset($type_data['type'])) {
                $type = " *     type=\"{$type_data['type']}\",\n";
            }
            $nullable = '';
            if (isset($type_data['nullable']) && $type_data['nullable'] === true) {
                $nullable = " *     nullable=true,\n";
                if (isset($type_data['ref'])) {
                    $nullable .= <<<STR
                     *     allOf={
                     *       @OA\Schema(ref="{$type_data['ref']}")
                     *     },

                    STR;
                    unset($type_data['ref']);
                }
            }
            $ref = '';
            if (isset($type_data['ref'])) {
                $type = " *     ref=\"{$type_data['ref']}\",\n";
            }
            $comment = <<<PHP
            use OpenAPI\Annotations as OA;
            /**
             *   @OA\Property(
            $property$type$nullable$ref
             *   ),
             */
            PHP;

            return implode("\n", array_filter(explode("\n", $comment), fn (string $s) => str_starts_with($s, ' * ')));
        })->join("\n");

        $docblock = <<<PHP
<?php

namespace App\\Helpers\\types;

use OpenApi\Annotations as OA;

{$this->getGenerationComment()}

/**
 * @OA\Schema(
 *   schema="UserPrefs",
 *   type="object",
 *   description="A list of preferences for the current user (or defaults if not signed in)",
 *   required=USER_PREF_KEYS,
 *   additionalProperties=false,
$properties
 * )
 */
class UserPrefs {}

PHP;

        $result = File::replace($file_loc, $docblock);
        if ($result === false) {
            $this->error("Could not write docblock to $file_loc");

            return 1;
        }

        $this->info("Written docblock to $file_loc");
        return 0;
    }

    /**
     * Execute the console command.
     *
     * @param  string  $file_loc
     * @return mixed
     */
    protected function generateGuideEntryCounts(string $file_loc): int
    {
        $properties = Collection::make(GuideName::cases())->map(function (GuideName $key) {
            $property = sprintf(" *     property=\"%s\",\n", Str::camel($key->value));
            $example = random_int(0, 300);
            $comment = <<<PHP
            use OpenAPI\Annotations as OA;
            /**
             *   @OA\Property(
            $property
             *     type="number",
             *     example=$example,
             *     minimum=0
             *   ),
             */
            PHP;

            return implode("\n", array_filter(explode("\n", $comment), fn (string $s) => str_starts_with($s, ' * ')));
        })->join("\n");

        $docblock = <<<PHP
<?php

namespace App\\Helpers\\types;

use OpenApi\Annotations as OA;

{$this->getGenerationComment()}

/**
 * @OA\Schema(
 *   schema="GuideEntryCounts",
 *   type="object",
 *   description="An object containing the number of entries in each color guide",
 *   required=GUIDE_NAMES,
 *   additionalProperties=false,
$properties
 * )
 */
class GuideEntryCounts {}

PHP;

        $result = File::replace($file_loc, $docblock);
        if ($result === false) {
            $this->error("Could not write docblock to $file_loc");

            return 1;
        }

        $this->info("Written docblock to $file_loc");
        return 0;
    }
}
