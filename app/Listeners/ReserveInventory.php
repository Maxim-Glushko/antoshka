<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Jobs\CheckSupplierDelivery;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Services\SupplierService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

class ReserveInventory implements ShouldQueue
{
    /**
     * Ensure the job is only dispatched after the DB transaction that created
     * the order has committed — prevents reading a not-yet-visible order row.
     */
    public bool $afterCommit = true;

    public function __construct(private readonly SupplierService $supplier) {}

    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        // Keep the transaction short — only DB work inside, HTTP calls outside.
        $reserved = DB::transaction(function () use ($order) {
            /** @var Inventory|null $inventory */
            $inventory = Inventory::where('sku', $order->sku)->lockForUpdate()->first();

            if ($inventory && $inventory->availableQty() >= $order->qty) {
                $this->reserve($order, $inventory);
                return true;
            }

            return false;
        });

        if (! $reserved) {
            $this->requestRestock($order);
        }
    }

    private function reserve(Order $order, Inventory $inventory): void
    {
        $inventory->increment('qty_reserved', $order->qty);

        InventoryMovement::create([
            'sku'      => $order->sku,
            'order_id' => $order->id,
            'type'     => InventoryMovement::TYPE_RESERVE,
            'qty'      => $order->qty,
        ]);

        $order->update(['status' => Order::STATUS_RESERVED]);
    }

    private function requestRestock(Order $order): void
    {
        $result = $this->supplier->reserve($order->sku, $order->qty);

        if (! empty($result['accepted'])) {
            $order->update([
                'status'       => Order::STATUS_AWAITING_RESTOCK,
                'supplier_ref' => $result['ref'],
            ]);

            CheckSupplierDelivery::dispatch($order)->delay(now()->addSeconds(15));
        } else {
            $order->update(['status' => Order::STATUS_FAILED]);
        }
    }
}
