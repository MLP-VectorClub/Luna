<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

enum AvatarProvider: string
{
    use ValuableEnum;

    case DeviantArt = 'deviantart';
    case Discord = 'discord';
    case Gravatar = 'gravatar';
}
