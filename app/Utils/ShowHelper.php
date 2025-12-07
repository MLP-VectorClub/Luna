<?php


namespace App\Utils;

class ShowHelper
{
    public const ALLOWED_PREFIXES = [
        'Equestria Girls' => 'EQG',
        'My Little Pony' => 'MLP',
    ];

    public const GEN_FIM = 'pony';
    public const GENERATIONS = [
        self::GEN_FIM => 'Friendship is Magic',
    ];
}
