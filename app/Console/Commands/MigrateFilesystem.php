<?php

namespace App\Console\Commands;

use App\Models\Appearance;
use App\Models\Cutiemark;
use App\Models\User;
use App\Utils\Core;
use App\Utils\ImageHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;
use function count;

class MigrateFilesystem extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fs:migrate '.
    '{folder : Path to the fs folder of the previous application version} '.
    '{uid : ID of user to upload files as} '.
    '{--w|wipe : Skip all prompts and just wipe / overwrite everything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate (copy) the folder layout from the previous application version to this instance';

    private const CATEGORY_CUTIEMARKS = 'cutiemarks';
    private const CATEGORY_SPRITES = 'sprites';

    private int $uploader_id;
    private bool $wipe;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->wipe = (bool) $this->option('wipe');
        if ($this->wipe) {
            $this->warn('Wipe option enabled, data will be wiped / overwritten as necessary');
        }

        $this->uploader_id = $this->hasArgument('uid') ? (int) $this->argument('uid') : 1;
        $user = User::find($this->uploader_id);
        if (!$user) {
            $this->error("Could not find uploader user (id {$this->uploader_id}) in database");
            return 1;
        }

        $this->info("Imported files will be uploaded as {$user->name} (id $user->id)");

        $folder = $this->argument('folder');
        if (empty($folder)) {
            $this->error("The folder argument is required!");
            return 2;
        }
        if (!is_dir($folder)) {
            $this->error("$folder must be a folder!");
            return 3;
        }

        $segments = explode(DIRECTORY_SEPARATOR, trim($folder, DIRECTORY_SEPARATOR));
        $last_segment = $segments[count($segments) - 1];
        if ($last_segment !== 'fs') {
            $this->error("$folder must point to the 'fs' directory of the old application!");
            return 4;
        }

        $this->line('Scouting for importable data…');

        $iter = Finder::create()->files()->in($folder);
        $files = [];
        foreach ($iter as $value) {
            if ($value->isDir()) {
                continue;
            }
            $path = $value->getRealPath();
            $category = $this->categorize($path);
            if ($category !== null) {
                $files[$category][] = $value;
            }
        }

        if (!$this->wipe) {
            $options = array_map(function (string $key) use ($files) {
                $file_count = count($files[$key]);
                return sprintf("%s (%s %s)", $key, $file_count, Str::plural('file', $file_count));
            }, array_keys($files));
            $selection = $this->choice(
                'The following set of data was found, choose what you would like to import.',
                $options,
                implode(',', array_keys($options)),
                null,
                true
            );
        } else {
            $selection = array_keys($files);
        }

        foreach ($selection as $item) {
            [$actual_item] = explode(' ', $item);
            $this->line("Processing import of {$actual_item}…");
            $this->import($actual_item, $files[$actual_item]);
        }
    }

    private function categorize(string $path): ?string
    {
        if (strpos($path, "cm_source") !== false) {
            return self::CATEGORY_CUTIEMARKS;
        }
        if (strpos($path, "sprites") !== false) {
            return self::CATEGORY_SPRITES;
        }

        return null;
    }

    /**
     * @param  string  $key
     * @param  SplFileInfo[]  $fileinfo
     * @return void
     * @throws Throwable
     */
    private function import(string $key, array $fileinfo): void
    {
        switch ($key) {
            case self::CATEGORY_CUTIEMARKS:
                $this->importCutiemarks($fileinfo);
                break;
            case self::CATEGORY_SPRITES:
                $this->importSprites($fileinfo);
                break;
            default:
                throw new RuntimeException("No importer for key $key");
        }
    }

    /**
     * @param  SplFileInfo[]  $fileinfo
     * @return void
     * @throws Throwable
     */
    private function importCutiemarks(array $fileinfo): void
    {
        $cm_file_count = count($fileinfo);
        $this->line("Importing $cm_file_count cutie mark ".Str::plural('file', $cm_file_count)."…");
        $this->output->progressStart($cm_file_count);

        $cm_ids = Collection::make($fileinfo)->map(function (SplFileInfo $info) {
            return (int) preg_replace('~^(\d+).*$~', '$1', $info->getFilename());
        })->sort();
        $cms = Cutiemark::findMany($cm_ids)->keyBy('id');
        $diff = array_diff($cm_ids->toArray(), $cms->keys()->toArray());
        if (count($diff) > 0) {
            $this->output->newLine();
            $this->info('IDs present in filesystem, but not the database: '.implode(', ', $diff));
            throw new RuntimeException('Database result count does not match file count, be sure to import database records first or delete files that belong to non-existent records.');
        }
        /** @var Cutiemark[] $records_mapped */
        $records_mapped = $cm_ids->map(function (int $id) use ($cms) {
            if (!isset($cms[$id])) {
                $this->error("Could not find CM by id $id in database results array");
            }
            return $cms[$id];
        });

        foreach ($fileinfo as $k => $info) {
            DB::transaction(function () use ($k, $info, $records_mapped) {
                $cutiemark = $records_mapped[$k];
                $file_path = $info->getRealPath();
                $cutiemark
                    ->addMedia($file_path)
                    ->usingFileName(Core::generateHashFilename($file_path))
                    ->preservingOriginal()
                    ->withCustomProperties(['user_id' => $this->uploader_id])
                    ->toMediaCollection(Cutiemark::CUTIEMARKS_COLLECTION);
            });

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->info("$cm_file_count cutiemark ".Str::plural('vectors', $cm_file_count)." imported successfully");
    }

    /**
     * @param  SplFileInfo[]  $fileinfo
     * @return void
     * @throws Throwable
     */
    private function importSprites(array $fileinfo): void
    {
        $sprite_file_count = count($fileinfo);
        $this->line("Importing $sprite_file_count sprite ".Str::plural('file', $sprite_file_count)."…");
        $this->output->progressStart($sprite_file_count);

        $appearance_ids = Collection::make($fileinfo)->map(function (SplFileInfo $info) {
            return (int) preg_replace('~^(\d+).*$~', '$1', $info->getFilename());
        })->sort();
        $appearances = Appearance::findMany($appearance_ids)->keyBy('id');
        $diff = array_diff($appearance_ids->toArray(), $appearances->keys()->toArray());
        if (count($diff) > 0) {
            $this->output->newLine();
            $this->info('IDs present in filesystem, but not the database: '.implode(', ', $diff));
            throw new RuntimeException('Database result count does not match file count, be sure to import database records first or delete files that belong to non-existent records.');
        }
        /** @var Appearance[] $records_mapped */
        $records_mapped = $appearance_ids->map(function (int $id) use ($appearances) {
            if (!isset($appearances[$id])) {
                $this->error("Could not find CM by id $id in database results array");
            }
            return $appearances[$id];
        });

        foreach ($fileinfo as $k => $info) {
            DB::transaction(function () use ($k, $info, $records_mapped) {
                $appearance = $records_mapped[$k];
                $file_path = $info->getRealPath();
                $appearance
                    ->addMedia($file_path)
                    ->usingFileName(Core::generateHashFilename($file_path))
                    ->preservingOriginal()
                    ->withCustomProperties([
                        'user_id' => $this->uploader_id,
                        'aspect_ratio' => ImageHelper::getAspectRatio($file_path),
                    ])
                    ->toMediaCollection(Appearance::SPRITES_COLLECTION);
            });

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->info("$sprite_file_count sprite ".Str::plural('file', $sprite_file_count)." imported successfully");
    }
}
