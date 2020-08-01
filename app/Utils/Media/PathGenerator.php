<?php


namespace App\Utils\Media;

use App\Utils\Math;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

class PathGenerator extends DefaultPathGenerator
{
    const STARTING_VALUE = 36;

    /*
     * Get a unique base path for the given media.
     */
    protected function getBasePath(Media $media): string
    {
        return 'media'.DIRECTORY_SEPARATOR.Math::rebase(self::STARTING_VALUE + $media->getKey());
    }
}
