<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PppoeSubscriberController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.pppoe-subscribers.index', [
            'filters' => $request->only(['search', 'status']),
        ]);
    }
}
