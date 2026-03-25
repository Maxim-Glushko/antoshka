<?php

namespace App\Http\Controllers;

use App\Http\Resources\InventoryMovementResource;
use App\Models\InventoryMovement;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryController extends Controller
{
    public function movements(string $sku): AnonymousResourceCollection
    {
        $movements = InventoryMovement::where('sku', $sku)
            ->latest()
            ->get();

        return InventoryMovementResource::collection($movements);
    }
}
