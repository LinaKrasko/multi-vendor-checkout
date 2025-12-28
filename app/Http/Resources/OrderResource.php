<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'subtotal_price' => (float)$this->subtotal_price,
            'discount' => (float)$this->discount,
            'total_price' => (float)$this->total_price,
            'sub_orders' => SubOrderResource::collection($this->whenLoaded('subOrders')),
        ];
    }
}
