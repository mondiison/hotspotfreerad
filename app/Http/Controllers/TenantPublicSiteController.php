<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\View\View;

class TenantPublicSiteController extends Controller
{
    public function __invoke(Tenant $tenant): View
    {
        abort_unless($tenant->is_active && $tenant->public_site_enabled, 404);

        $tenant->load([
            'shops' => fn ($query) => $query
                ->where('is_active', true)
                ->with(['packages' => fn ($query) => $query->where('is_active', true)->orderBy('price')])
                ->orderBy('name'),
        ]);

        return view('tenants.public-site', compact('tenant'));
    }
}
