<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UserController extends Controller {
  function index(Request $request) {
    return $request->user();
  }
}
