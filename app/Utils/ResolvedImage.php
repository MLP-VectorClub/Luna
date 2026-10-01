<?php

namespace App\Utils;

class ResolvedImage
{
    public function __construct(
        public string $provider,
        public string $id,
        public ?string $preview = null,
        public ?string $fullsize = null,
        public string $title = '',
        public ?string $author = null,
        public ?string $type = null,
    ) {
    }
}
