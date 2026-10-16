<?php

namespace App\Enums;


enum AvatarProvider: string
{
    use ValuableEnum;

    case DeviantArt = 'deviantart';
    case Discord = 'discord';
    case Gravatar = 'gravatar';
}
