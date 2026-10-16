<?php

namespace Tests\Unit;

use App\Utils\Media\ProjectSvgo;
use Tests\TestCase;

class ProjectSvgoTest extends TestCase
{
    public function testRunsTheSvgoInstalledInTheProject(): void
    {
        $optimizer = new ProjectSvgo(['--config=/path/to/svgo.config.mjs']);
        $optimizer->setImagePath('/tmp/image.svg');

        $this->assertSame(
            '"'.base_path('node_modules/.bin').'/svgo" --config=/path/to/svgo.config.mjs --input=\'/tmp/image.svg\' --output=\'/tmp/image.svg\'',
            $optimizer->getCommand()
        );
    }

    public function testMediaLibraryUsesItWithTheProjectConfig(): void
    {
        $options = config('media-library.image_optimizers')[ProjectSvgo::class] ?? null;

        $this->assertNotNull($options);
        $this->assertFileExists(substr($options[0], strlen('--config=')));
    }
}
