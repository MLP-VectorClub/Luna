<?php

namespace App\Http\Controllers;

class DocsController extends Controller {
  function index() {
    return view('docs');
  }

  function rtfm() {
    return redirect('/docs');
  }
}
