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
    protected $description = 'Generate the attribute files for the UserPrefs and GuideEntryCounts schemas';

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
        # Generated OpenAPI attributes for the darkaonline/l5-swagger package
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
            $args = ["property: '{$key->value}'"];
            if (isset($type_data['type'])) {
                $args[] = "type: '{$type_data['type']}'";
            }
            if (isset($type_data['nullable']) && $type_data['nullable'] === true) {
                $args[] = 'nullable: true';
                if (isset($type_data['ref'])) {
                    $args[] = "allOf: [new OA\\Schema(ref: '{$type_data['ref']}')]";
                    unset($type_data['ref']);
                }
            }
            if (isset($type_data['ref'])) {
                $args[] = "ref: '{$type_data['ref']}'";
            }

            return $this->property($args);
        })->join("\n");

        $docblock = <<<PHP
<?php

namespace App\\Helpers\\types;

use OpenApi\\Attributes as OA;

{$this->getGenerationComment()}

#[OA\\Schema(
    schema: 'UserPrefs',
    type: 'object',
    description: 'A list of preferences for the current user (or defaults if not signed in)',
    required: \\USER_PREF_KEYS,
    additionalProperties: false,
    properties: [
$properties
    ],
)]
class UserPrefs {}

PHP;

        $result = File::replace($file_loc, $docblock);
        if ($result === false) {
            $this->error("Could not write attributes to $file_loc");

            return 1;
        }

        $this->info("Written attributes to $file_loc");
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
            $example = random_int(0, 300);

            return $this->property(["property: '".Str::camel($key->value)."'", "type: 'number'", "example: $example", 'minimum: 0']);
        })->join("\n");

        $docblock = <<<PHP
<?php

namespace App\\Helpers\\types;

use OpenApi\\Attributes as OA;

{$this->getGenerationComment()}

#[OA\\Schema(
    schema: 'GuideEntryCounts',
    type: 'object',
    description: 'An object containing the number of entries in each color guide',
    required: \\GUIDE_NAMES,
    additionalProperties: false,
    properties: [
$properties
    ],
)]
class GuideEntryCounts {}

PHP;

        $result = File::replace($file_loc, $docblock);
        if ($result === false) {
            $this->error("Could not write attributes to $file_loc");

            return 1;
        }

        $this->info("Written attributes to $file_loc");
        return 0;
    }

    /**
     * One property of the generated schema, as the `new OA\Property(...)` line of its `properties` list.
     *
     * @param  string[]  $args
     */
    protected function property(array $args): string
    {
        return '        new OA\\Property('.implode(', ', $args).'),';
    }
}
