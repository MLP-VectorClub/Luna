<?php

namespace App\Enums;

use OpenApi\Annotations as OA;

enum GuideName: string
{
    use ValuableEnum;

    case FriendshipIsMagic = 'pony';
    case EquestriaGirls = 'eqg';
}
