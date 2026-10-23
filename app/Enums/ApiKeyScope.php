<?php

namespace App\Enums;

/** Abilities a user-created API key can carry. A key never exceeds what its owner's role allows, these only narrow it further */
enum ApiKeyScope: string
{
    case Read = 'read';
    case ReadOwn = 'read:own';
    case AppearancesWrite = 'appearances:write';
    case PostsWrite = 'posts:write';
    case UserWrite = 'user:write';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn(self $s) => $s->value, self::cases());
    }
}
