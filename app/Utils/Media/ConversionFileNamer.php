<?php


namespace App\Utils\Media;

use Spatie\MediaLibrary\Conversions\Conversion;

class ConversionFileNamer extends \Spatie\MediaLibrary\Support\FileNamer\FileNamer
{
    public function originalFileName(string $fileName): string
    {
        return pathinfo($fileName, PATHINFO_FILENAME);
    }

    public function conversionFileName(string $fileName, Conversion $conversion): string
    {
        return "{$this->originalFileName($fileName)}-{$conversion->getName()}";
    }

    public function responsiveFileName(string $fileName): string
    {
        return $this->originalFileName($fileName);
    }
}
