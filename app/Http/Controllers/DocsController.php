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
        if ($disk->exists($file_path)) {
            $file_url = $disk->url($file_path).'?t='.$disk->lastModified("public/$file_path");
        }
        return view('docs', ['file_url' => $file_url ?? '']);
    }

    public function rtfm()
    {
        return redirect('/');
    }
}
