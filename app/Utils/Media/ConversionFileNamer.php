<?php


namespace App\Utils\Media;


use App\Utils\Core;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ConversionFileNamer extends \Spatie\MediaLibrary\Conversions\ConversionFileNamer
{
    public function getFileName(Conversion $conversion, Media $media): string
    {
        $file_name = pathinfo($media->file_name, PATHINFO_FILENAME);
        return "{$file_name}-{$conversion->getName()}";
    }
}
