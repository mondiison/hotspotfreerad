<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * $this->packages is expected pre-loaded and pre-filtered by the
 * controller (active hotspot/both packages only -- the same filter
 * VoucherManagementService::createBatch() itself enforces), so this
 * resource never has to re-derive that rule a second time.
 */
class ShopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'location_city' => $this->location_city,
            'packages' => PackageResource::collection($this->whenLoaded('packages')),
        ];
    }
}
