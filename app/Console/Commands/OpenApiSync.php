<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Keeps the committed copy of the generated OpenAPI document (docs/openapi/api-docs.json) in step with the annotations. Celestia pins a commit of
 * this file to build its API types from, so its tests do not depend on what is deployed. `--check` only reports whether the copy is current.
 */
class OpenApiSync extends Command
{
    public const COMMITTED_PATH = 'docs/openapi/api-docs.json';

    protected $signature = 'openapi:sync {--check : Do not write, fail when the committed copy differs from the annotations}';

    protected $description = 'Generate the OpenAPI document and update the copy kept in source control';

    public function handle(): int
    {
        $this->callSilent('l5-swagger:generate');
        $generated = storage_path('api-docs/api-docs.json');
        $committed = base_path(self::COMMITTED_PATH);

        if (!File::exists($generated)) {
            $this->error('The OpenAPI document was not generated');

            return self::FAILURE;
        }
        if (File::exists($committed) && File::get($committed) === File::get($generated)) {
            $this->info('The committed OpenAPI document is current');

            return self::SUCCESS;
        }
        if ($this->option('check')) {
            $this->error(self::COMMITTED_PATH.' is out of date, run `php artisan openapi:sync` and commit the result');

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($committed));
        File::copy($generated, $committed);
        $this->info('Updated '.self::COMMITTED_PATH.', commit it with the change');

        return self::SUCCESS;
    }
}
