<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUS_PENDING          = 'pending';
    public const STATUS_RESERVED         = 'reserved';
    public const STATUS_AWAITING_RESTOCK = 'awaiting_restock';
    public const STATUS_FAILED           = 'failed';

    protected $fillable = [
        'sku',
        'qty',
        'status',
        'supplier_ref',
        'supplier_attempts',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
