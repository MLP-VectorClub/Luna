<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Support\Facades\Storage;

class DocsController extends Controller
{
    public function index()
    {
        /** @var Cloud $disk */
        $disk = Storage::disk('local');
        $file_path = 'api.json';
        $file_disk_path = "public/$file_path";
        if ($disk->exists($file_disk_path)) {
            $file_url = $disk->url($file_path).'?t='.$disk->lastModified($file_disk_path);
        }
        return view('docs', ['file_url' => $file_url ?? '']);
    }

    public function rtfm()
    {
        return redirect('/');
    }
}
