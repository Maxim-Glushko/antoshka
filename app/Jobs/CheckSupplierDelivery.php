<?php

namespace App\Jobs;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Services\SupplierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CheckSupplierDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * We manage retries ourselves via re-dispatch, so Laravel should not retry on failure.
     */
    public int $tries = 1;

    public function __construct(
        public Order $order,
        public int $attempt = 0,
    ) {}

    public function handle(SupplierService $supplier): void
    {
        $status = $supplier->checkStatus($this->order->supplier_ref);

        match ($status) {
            'ok'      => $this->handleOk(),
            'fail'    => $this->order->update(['status' => Order::STATUS_FAILED]),
            'delayed' => $this->handleDelayed(),
            default   => $this->order->update(['status' => Order::STATUS_FAILED]),
        };
    }

    private function handleOk(): void
    {
        DB::transaction(function () {
            $order = $this->order; // already fresh — SerializesModels reloads from DB on unserialize

            // Ensure inventory row exists before locking
            DB::table('inventories')->insertOrIgnore([
                'sku'          => $order->sku,
                'qty_on_hand'  => 0,
                'qty_reserved' => 0,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            $inventory = Inventory::where('sku', $order->sku)->lockForUpdate()->firstOrFail();

            // Supplier delivered the goods → record them and immediately reserve for this order
            $inventory->increment('qty_on_hand', $order->qty);
            $inventory->increment('qty_reserved', $order->qty);

            InventoryMovement::create([
                'sku'      => $order->sku,
                'order_id' => $order->id,
                'type'     => InventoryMovement::TYPE_RESTOCK,
                'qty'      => $order->qty,
                'meta'     => ['supplier_ref' => $order->supplier_ref],
            ]);

            $order->update(['status' => Order::STATUS_RESERVED]);
        });
    }

    private function handleDelayed(): void
    {
        // Maximum 2 retries (attempts 0 and 1 may retry; attempt 2 means we already retried twice)
        if ($this->attempt >= 2) {
            $this->order->update(['status' => Order::STATUS_FAILED]);
            return;
        }

        $this->order->increment('supplier_attempts');

        static::dispatch($this->order->fresh(), $this->attempt + 1)
            ->delay(now()->addSeconds(15));
    }
}
