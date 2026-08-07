<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StandaloneScriptGeneratorController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return view('admin.tools.script-generator');
    }
}
