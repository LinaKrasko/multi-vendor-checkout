<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SubOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'vendor_code' => $this->vendor_code,
            'status' => $this->status,
            'items_snapshot' => $this->items_snapshot,
        ];
    }
}
