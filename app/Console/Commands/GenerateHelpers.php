<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateHelpers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate-helpers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Conditionally generate ide-helper files';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $class_name = 'Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider';
        if (!class_exists($class_name)) {
            $this->warn("The $class_name class is missing, skipping IDE helper commands");
        }

        $this->call("ide-helper:generate");
        $this->call("ide-helper:meta");
    }
}
