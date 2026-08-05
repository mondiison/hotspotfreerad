<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class TrustedWifiDeviceController extends Controller
{
    public function index(): View
    {
        return view('admin.trusted-wifi-devices.index', [
            'filters' => request()->only(['search', 'network']),
        ]);
    }
}
