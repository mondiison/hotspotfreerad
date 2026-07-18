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

        $packages = $tenant->shops
            ->flatMap(fn ($shop) => $shop->packages->map(fn ($package) => $package->setRelation('shop', $shop)))
            ->sortBy('price')
            ->values();

        return view('tenants.public-site', [
            'tenant' => $tenant,
            'featuredPackages' => $packages->take(3),
            'publicStats' => [
                'locations' => $tenant->shops->count(),
                'plans' => $packages->count(),
                'starting_price' => $packages->first()?->price,
                'currency' => $packages->first()?->currency ?? 'NGN',
            ],
        ]);
    }
}
