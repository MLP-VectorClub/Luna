<?php

namespace App\Enums;


enum GuideName: string
{
    use ValuableEnum;

    case FriendshipIsMagic = 'pony';
    case EquestriaGirls = 'eqg';
}
