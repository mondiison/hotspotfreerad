<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Router;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'tenantCount' => Tenant::count(),
            'shopCount' => Shop::count(),
            'routerCount' => Router::count(),
            'packageCount' => Package::count(),
            'activeSessionCount' => DB::table('radacct')->whereNull('acctstoptime')->count(),
        ]);
    }
}
