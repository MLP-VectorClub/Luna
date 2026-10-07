<?php

namespace App\Console\Commands;

use App\Models\Appearance;
use App\Utils\PixelUpscale;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PixelateDoubleSprites extends Command
{
    protected $signature = 'sprites:pixelate-2x {--dry-run : Only count what would be rewritten}';

    protected $description = 'Replaces the smooth double size version of every sprite by an exact pixel doubling (new uploads get it automatically)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $done = $missing = 0;
        $problems = [];
        Media::where('collection_name', Appearance::SPRITES_COLLECTION)->orderBy('id')->each(function (Media $media) use ($dry, &$done, &$missing, &$problems) {
            $target = $media->getPath(Appearance::DOUBLE_SIZE_CONVERSION);
            if (!is_file($media->getPath()) || !is_file($target)) {
                $missing++;

                return;
            }
            // Plain file writes, checked before and after: the medialibrary's own regeneration also changes the permissions of the file, which only its
            // owner may do, and these files belong to whoever uploaded or deployed them
            if ($dry) {
                $writable = is_writable($target) && is_writable(dirname($target));
                $writable ? $done++ : $problems[] = "media #$media->id: $target is not writable (or its folder) for this user";

                return;
            }
            $reason = PixelUpscale::tryDouble($media->getPath(), $target);
            $reason === null ? $done++ : $problems[] = "media #$media->id: $reason";
        });

        foreach ($problems as $problem) {
            $this->warn($problem);
        }
        $this->info(($dry ? 'Can rewrite' : 'Rewrote')." $done double size sprites, $missing without a file to work on, ".count($problems).' with problems.');
        if ($problems !== []) {
            $this->line('Run it as a user that can write to storage/app (the web server\'s user, www-data).');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
