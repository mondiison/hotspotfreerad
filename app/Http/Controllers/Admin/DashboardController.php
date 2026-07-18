<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use App\Support\TenantAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('admin.dashboard', [
            'tenantCount' => $user->isSuperAdmin() ? Tenant::count() : 1,
            'shopCount' => TenantAccess::scopeShops(Shop::query(), $user)->count(),
            'routerCount' => TenantAccess::scopeRouters(Router::query(), $user)->count(),
            'packageCount' => TenantAccess::scopePackages(Package::query(), $user)->count(),
            'activeSessionCount' => DB::table('radacct')->whereNull('acctstoptime')->count(),
        ]);
    }
}
