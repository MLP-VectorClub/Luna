<?php

namespace App\Listeners;

use App\Models\Appearance;
use App\Utils\PixelUpscale;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;

/**
 * Medialibrary enlarges the sprite for the `2x` conversion with smooth interpolation; this replaces the result by an exact pixel doubling
 */
class PixelateDoubleSizeSprite
{
    public function handle(ConversionHasBeenCompletedEvent $event): void
    {
        $media = $event->media;
        if ($event->conversion->getName() !== Appearance::DOUBLE_SIZE_CONVERSION || $media->collection_name !== Appearance::SPRITES_COLLECTION) {
            return;
        }

        $target = $media->getPath(Appearance::DOUBLE_SIZE_CONVERSION);
        if (is_file($target) && ($reason = PixelUpscale::tryDouble($media->getPath(), $target)) !== null) {
            // The smooth version stays, which is a usable sprite: say why it was not replaced
            Log::warning("The double size sprite of media #$media->id was not made pixel exact: $reason");
        }
    }
}
