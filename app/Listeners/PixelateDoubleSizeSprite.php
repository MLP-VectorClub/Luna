<?php

namespace App\Listeners;

use App\Models\Appearance;
use App\Utils\PixelUpscale;
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
        if (is_file($target)) {
            PixelUpscale::double($media->getPath(), $target);
        }
    }
}
