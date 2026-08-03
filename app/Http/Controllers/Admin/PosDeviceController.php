<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class PosDeviceController extends Controller
{
    public function index(): View
    {
        return view('admin.pos-devices.index', [
            'filters' => request()->only(['search', 'status']),
        ]);
    }
}
