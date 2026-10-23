<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('mlp-vectorclub')]
#[Version('0.1.0')]
#[Instructions('Read and edit the MLP Vector Club color guide. Search appearances first, then fetch one by its ID. Which tools you see depends on the scopes of your API key, and edits only succeed where the key owner is allowed to make them.')]
class VectorClubServer extends Server
{
    protected array $tools = [
        Tools\SearchAppearances::class,
        Tools\GetAppearance::class,
        Tools\ListTags::class,
        Tools\ListShows::class,
        Tools\GetShow::class,
        Tools\GetUser::class,
        Tools\GetConfig::class,
        Tools\UpdateAppearance::class,
        Tools\SetAppearanceTags::class,
        Tools\ReservePost::class,
        Tools\FinishPost::class,
    ];
}
