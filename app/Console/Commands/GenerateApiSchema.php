<?php

namespace App\Console\Commands;

use App\Http\Controllers\DocsController;
use cebe\openapi\Reader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenApi\Analysis;
use OpenApi\Annotations\Operation;
use function OpenApi\scan;

class GenerateApiSchema extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:schema';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the OpenAPI schema file';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $output_path = DocsController::FILE_DISK_PATH;
        // Generate reasonable looking operation IDs
        Analysis::registerProcessor(function (Analysis $analysis) {
            /** @var Operation[] $all_operations */
            $all_operations = $analysis->getAnnotationsOfType(Operation::class);

            foreach ($all_operations as $operation) {
                $operation->operationId = ucfirst(
                    Str::camel(
                        trim(
                            preg_replace(
                                '~_{2,}~',
                                '_',
                                preg_replace(
                                    '~[^a-z_]~i',
                                    '_',
                                    implode('_', [$operation->method, $operation->path])
                                )
                            ),
                            '_'
                        )
                    )
                );
            }
        });
        $openapi = scan(app_path());
        if (!$openapi->validate()) {
            $this->error("Invalid OpenAPI schema, could not generate $output_path");
            exit(1);
        }
        $json = $openapi->toJson();
        $validator = Reader::readFromJson($json);
        if (!$validator->validate()) {
            $this->error("Generated OpenAPI JSON did not match schema, see errors below:\n");

            foreach ($validator->getErrors() as $error) {
                $this->error("\t$error");
            }
            exit(1);
        }
        Storage::disk('local')->put($output_path, $json);
        $this->info('Written API schema to file');
    }
}
