<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Jobs\CheckSupplierDelivery;
use App\Listeners\ReserveInventory;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InventoryReservationTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Reservation from existing stock
    // ---------------------------------------------------------------

    public function test_reserves_order_when_stock_is_sufficient(): void
    {
        Queue::fake();
        Inventory::create(['sku' => 'ABC123', 'qty_on_hand' => 10, 'qty_reserved' => 0]);

        $order = Order::create(['sku' => 'ABC123', 'qty' => 3, 'status' => Order::STATUS_PENDING]);

        app(ReserveInventory::class)->handle(new OrderCreated($order));

        $order->refresh();
        $this->assertEquals(Order::STATUS_RESERVED, $order->status);

        $this->assertDatabaseHas('inventory_movements', [
            'sku'      => 'ABC123',
            'order_id' => $order->id,
            'type'     => InventoryMovement::TYPE_RESERVE,
            'qty'      => 3,
        ]);

        $this->assertDatabaseHas('inventories', [
            'sku'          => 'ABC123',
            'qty_on_hand'  => 10,
            'qty_reserved' => 3,
        ]);

        Queue::assertNothingPushed();
    }

    public function test_does_not_reserve_when_stock_is_exactly_used_up(): void
    {
        Queue::fake();
        Http::fake([
            '*/supplier/reserve' => Http::response(['accepted' => true, 'ref' => 'SUP-001']),
        ]);

        // 2 on hand, 2 reserved → 0 available
        Inventory::create(['sku' => 'ABC123', 'qty_on_hand' => 2, 'qty_reserved' => 2]);

        $order = Order::create(['sku' => 'ABC123', 'qty' => 1, 'status' => Order::STATUS_PENDING]);

        app(ReserveInventory::class)->handle(new OrderCreated($order));

        $order->refresh();
        $this->assertEquals(Order::STATUS_AWAITING_RESTOCK, $order->status);
    }

    // ---------------------------------------------------------------
    // Restock flow via supplier
    // ---------------------------------------------------------------

    public function test_contacts_supplier_and_awaits_restock_when_stock_is_insufficient(): void
    {
        Queue::fake();
        Http::fake([
            '*/supplier/reserve' => Http::response(['accepted' => true, 'ref' => 'SUP-2024001']),
        ]);

        $order = Order::create(['sku' => 'ABC123', 'qty' => 5, 'status' => Order::STATUS_PENDING]);

        app(ReserveInventory::class)->handle(new OrderCreated($order));

        $order->refresh();
        $this->assertEquals(Order::STATUS_AWAITING_RESTOCK, $order->status);
        $this->assertEquals('SUP-2024001', $order->supplier_ref);

        Queue::assertPushed(CheckSupplierDelivery::class, function (CheckSupplierDelivery $job) use ($order) {
            return $job->order->id === $order->id && $job->attempt === 0;
        });
    }

    public function test_fails_order_when_supplier_rejects_reservation(): void
    {
        Http::fake([
            '*/supplier/reserve' => Http::response(['accepted' => false]),
        ]);

        $order = Order::create(['sku' => 'ABC123', 'qty' => 5, 'status' => Order::STATUS_PENDING]);

        app(ReserveInventory::class)->handle(new OrderCreated($order));

        $order->refresh();
        $this->assertEquals(Order::STATUS_FAILED, $order->status);
    }

    // ---------------------------------------------------------------
    // Inventory movements endpoint
    // ---------------------------------------------------------------

    public function test_can_retrieve_inventory_movements_for_sku(): void
    {
        $order = Order::create(['sku' => 'ABC123', 'qty' => 3, 'status' => Order::STATUS_RESERVED]);
        InventoryMovement::create([
            'sku'      => 'ABC123',
            'order_id' => $order->id,
            'type'     => InventoryMovement::TYPE_RESERVE,
            'qty'      => 3,
        ]);

        $this->getJson('/api/inventory/ABC123/movements')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['sku' => 'ABC123', 'type' => 'reserve', 'qty' => 3]);
    }

    public function test_returns_empty_array_for_sku_with_no_movements(): void
    {
        $this->getJson('/api/inventory/UNKNOWN/movements')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
