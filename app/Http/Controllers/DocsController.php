<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Support\Facades\Storage;

class DocsController extends Controller
{
    const FILE_PATH = 'api.json';
    const FILE_DISK_PATH = 'public/'.self::FILE_PATH;

    public function index()
    {
        /** @var Cloud $disk */
        $disk = Storage::disk('local');
        if ($disk->exists(self::FILE_DISK_PATH)) {
            $file_url = $disk->url(self::FILE_PATH).'?t='.$disk->lastModified(self::FILE_DISK_PATH);
        }
        return view('docs', ['file_url' => $file_url ?? '']);
    }

    public function rtfm()
    {
        return redirect('/');
    }
}
