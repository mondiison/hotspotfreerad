<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('admin.profile.edit');
    }

    public function update(Request $request, ProfileService $profiles): RedirectResponse
    {
        $profiles->update($request->user(), $request->validate($profiles->rules()));

        return back()->with('status', 'Profile updated.');
    }
}
