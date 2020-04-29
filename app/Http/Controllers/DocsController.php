<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Support\Facades\Storage;

class DocsController extends Controller
{
    public function rtfm()
    {
        return redirect('https://app.swaggerhub.com/apis-docs/SeinopSys/MLPVectorClub/0.1');
    }
}
