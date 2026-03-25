<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $fillable = [
        'sku',
        'qty_on_hand',
        'qty_reserved',
    ];

    public function availableQty(): int
    {
        return $this->qty_on_hand - $this->qty_reserved;
    }
}
