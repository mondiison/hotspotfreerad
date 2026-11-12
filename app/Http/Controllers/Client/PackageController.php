<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Phase 2 self-service package management -- a thin wrapper around
 * App\Livewire\Client\PackagesIndex, mirroring Admin\PackageController's
 * index()-only shape (every create/edit/save/delete action lives on the
 * Livewire component, not a resource route).
 */
class PackageController extends Controller
{
    public function index(): View
    {
        return view('client.packages.index');
    }
}
