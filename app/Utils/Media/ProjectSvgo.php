<?php

namespace App\Utils\Media;

use Spatie\ImageOptimizer\Optimizers\Svgo;

/**
 * Spatie's Svgo optimizer, but running the svgo that pnpm installs into this project (so its version is tracked in
 * package.json and the lockfile) instead of whichever binary happens to be on the server's PATH.
 */
class ProjectSvgo extends Svgo
{
    public function __construct($options = [])
    {
        parent::__construct($options);

        $this->setBinaryPath(base_path('node_modules/.bin'));
    }
}
