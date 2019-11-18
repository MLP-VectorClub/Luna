<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use function OpenApi\scan;

class GenerateApiSchema extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:schema:generate';

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
        $output_path = 'public/api.json';
        $openapi = scan(app_path());
        if (!$openapi->validate()) {
            $this->error("Invalid OpenAPI schema, could not generate $output_path");
            exit(1);
        }
        $json = $openapi->toJson();
        Storage::disk('local')->put($output_path, $json);
        $this->info('Written API schema to file');
    }
}
