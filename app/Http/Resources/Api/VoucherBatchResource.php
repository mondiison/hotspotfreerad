<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoucherBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'quantity' => $this->quantity,
            'code_length' => $this->code_length,
            'prefix' => $this->prefix,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'shop' => [
                'id' => $this->shop->id,
                'name' => $this->shop->name,
            ],
            'package' => new PackageResource($this->whenLoaded('package') ?: $this->package),
            // Only ever eager-loaded on the batch-detail endpoint (the list
            // endpoint intentionally omits it -- a batch can have up to 500
            // vouchers, no reason to ship that payload on every list row).
            'vouchers' => VoucherResource::collection($this->whenLoaded('vouchers')),
        ];
    }
}
