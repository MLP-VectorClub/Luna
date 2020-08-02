<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class AboutController extends Controller
{
    /**
     * An undocumented endpoint for development use that just loads forever
     *
     * nginx will likely terminate the connection sooner though
     *
     * @param  Request  $request
     */
    public function sleep(Request $request)
    {
        if (App::isProduction()) {
            abort(404);
        }

        sleep(60 * 60);

        return redirect($request->path());
    }
}
