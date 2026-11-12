<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ShopResource;
use App\Models\Shop;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Backs the voucher-creation shop/package picker -- the only reason the
 * app reads shops at all in this phase, which is also why api.shops is
 * gated under the VOUCHERS staff permission (see StaffPermissions).
 */
class ShopController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $shops = TenantAccess::scopeShops(Shop::query(), $request->user())
            ->with(['packages' => fn ($query) => $query
                ->where('is_active', true)
                ->whereIn('service_type', ['hotspot', 'both'])
                ->orderBy('price')])
            ->orderBy('name')
            ->get();

        return ShopResource::collection($shops);
    }
}
