<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'sale_amount' => $this->sale_amount !== null ? (float) $this->sale_amount : null,
            'sold_at' => $this->sold_at?->toIso8601String(),
            'used_at' => $this->used_at?->toIso8601String(),
        ];
    }
}
