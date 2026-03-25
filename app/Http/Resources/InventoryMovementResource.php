<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'sku'        => $this->sku,
            'order_id'   => $this->order_id,
            'type'       => $this->type,
            'qty'        => $this->qty,
            'meta'       => $this->meta,
            'created_at' => $this->created_at,
        ];
    }
}
