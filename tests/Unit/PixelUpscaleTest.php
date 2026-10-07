<?php

namespace Tests\Unit;

use App\Utils\PixelUpscale;
use PHPUnit\Framework\TestCase;

class PixelUpscaleTest extends TestCase
{
    public function testEveryPixelBecomesAFlatTwoByTwoBlock(): void
    {
        $from = tempnam(sys_get_temp_dir(), 'px').'.png';
        $to = tempnam(sys_get_temp_dir(), 'px').'.png';
        $image = imagecreatetruecolor(2, 2);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $colors = [[255, 0, 0, 0], [0, 255, 0, 0], [0, 0, 255, 0], [10, 20, 30, 127]];
        foreach ($colors as $i => [$r, $g, $b, $a]) {
            imagesetpixel($image, $i % 2, intdiv($i, 2), imagecolorallocatealpha($image, $r, $g, $b, $a));
        }
        imagepng($image, $from);

        $this->assertTrue(PixelUpscale::double($from, $to));

        $out = imagecreatefrompng($to);
        $this->assertSame([4, 4], [imagesx($out), imagesy($out)]);
        foreach ($colors as $i => [$r, $g, $b, $a]) {
            foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$dx, $dy]) {
                $px = imagecolorsforindex($out, imagecolorat($out, ($i % 2) * 2 + $dx, intdiv($i, 2) * 2 + $dy));
                $this->assertSame([$r, $g, $b, $a], [$px['red'], $px['green'], $px['blue'], $px['alpha']], "pixel $i block ($dx,$dy)");
            }
        }
        unlink($from);
        unlink($to);
    }

    public function testFilesThatAreNotImagesAreRefused(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'px');
        file_put_contents($file, 'not an image');
        $this->assertFalse(PixelUpscale::double($file, $file.'.out'));
        unlink($file);
    }

    public function testAReadOnlyFolderIsReportedAndNothingIsChanged(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write anywhere');
        }
        $dir = sys_get_temp_dir().'/px-'.uniqid();
        mkdir($dir);
        $from = "$dir/in.png";
        imagepng(imagecreatetruecolor(2, 2), $from);
        $to = "$dir/out/out.png";
        mkdir(dirname($to));
        file_put_contents($to, 'old');
        chmod(dirname($to), 0555);

        $reason = PixelUpscale::tryDouble($from, $to);

        $this->assertStringContainsString('not writable', (string) $reason);
        $this->assertSame('old', file_get_contents($to));
        $this->assertSame([], glob(dirname($to).'/.*.tmp'));
        chmod(dirname($to), 0755);
    }

    public function testTheReplacedFileKeepsItsMode(): void
    {
        $dir = sys_get_temp_dir().'/px-'.uniqid();
        mkdir($dir);
        $from = "$dir/in.png";
        $to = "$dir/out.png";
        imagepng(imagecreatetruecolor(3, 3), $from);
        file_put_contents($to, 'old');
        chmod($to, 0664);

        $this->assertNull(PixelUpscale::tryDouble($from, $to));

        $this->assertSame(0664, fileperms($to) & 0777);
        $this->assertSame([6, 6], array_slice(getimagesize($to), 0, 2));
    }
}

