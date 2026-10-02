<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

enum SpriteSize: int
{
    use ValuableEnum;

    case Default = 300;
    case Double = 600;
}
