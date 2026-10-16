<?php

namespace App\Enums;


enum SpriteSize: int
{
    use ValuableEnum;

    case Default = 300;
    case Double = 600;
}
