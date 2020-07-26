<?php

namespace App\Enums\Errors;

use App\Utils\EnumWrapper;

/**
 * @method static self ElasticDown()
 * @method static self AppearancePrivate()
 */
final class ColorGuideErrors extends EnumWrapper
{
    protected static function values(): array
    {
        return [
            'ElasticDown' => 'colorGuide.elasticDown',
            'AppearancePrivate' => 'colorGuide.appearancePrivate',
        ];
    }
}
