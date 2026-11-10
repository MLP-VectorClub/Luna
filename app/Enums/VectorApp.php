<?php

namespace App\Enums;

use OpenApi\Attributes as OA;

/**
 * @method static self Illustrator()
 * @method static self Inkscape()
 * @method static self Ponyscape()
 * @method static self AffinityDesigner()
 */
#[OA\Schema(schema: 'VectorApp', type: 'string', description: 'List of available vector apps', example: 'illustrator')]
enum VectorApp: string
{
    use ValuableEnum;

    case Illustrator = 'illustrator';
    case Inkscape = 'inkscape';
    case Ponyscape = 'ponyscape';
    case AffinityDesigner = 'affinity-designer';

    public function label(): string
    {
        return match ($this) {
            self::Illustrator => 'Adobe Illustrator',
            self::Inkscape => 'Inkscape',
            self::Ponyscape => 'Ponyscape',
            self::AffinityDesigner => 'Affinity',
        };
    }

    /**
     * Choices for the `p_vectorapp` preference, key to label; the empty key means none.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = ['' => "(don't show)"];
        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }
}
