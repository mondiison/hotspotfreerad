<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'limit_uptime_seconds' => $this->limit_uptime_seconds,
            'data_limit_bytes' => $this->data_limit_bytes,
            'speed_limit_profile' => $this->speed_limit_profile,
            'service_type' => $this->service_type,
        ];
    }
}
