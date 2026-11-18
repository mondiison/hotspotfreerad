<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately excludes every technical/secret field (wireguard keys,
 * api_username/api_password, shared_secret, zerotier_ip) -- this backs the
 * tenant_admin-facing router picker for the remote console (see
 * RouterConsoleController), and Phase 1's router-detail lockdown means
 * tenant_admin must never see those values anywhere, including here.
 */
class RouterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shop_name' => $this->whenLoaded('shop', fn () => $this->shop?->name),
            'tunnel_mode' => $this->tunnel_mode,
            'is_online' => (bool) $this->is_online,
        ];
    }
}
