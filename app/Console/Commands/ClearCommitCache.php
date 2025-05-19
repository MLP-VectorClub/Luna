<?php

namespace App\Console\Commands;

use App\Utils\GitHelper;
use Illuminate\Console\Command;

class ClearCommitCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'commit:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clears cached commit information so API requests can show up to date values immediately after a deploy';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!GitHelper::clearCommitDataCache()) {
            $this->error('Clearing commit hash failed');
            // Not the end of the world though
            return 0;
        }

        $this->line('Cleared cached commit hash successfully');
    }
}
