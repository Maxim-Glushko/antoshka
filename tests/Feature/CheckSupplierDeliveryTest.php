<?php

namespace Tests\Feature;

use App\Jobs\CheckSupplierDelivery;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Services\SupplierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckSupplierDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->order = Order::create([
            'sku'          => 'ABC123',
            'qty'          => 3,
            'status'       => Order::STATUS_AWAITING_RESTOCK,
            'supplier_ref' => 'SUP-2024001',
        ]);
    }

    // ---------------------------------------------------------------
    // Supplier responds "ok"
    // ---------------------------------------------------------------

    public function test_reserves_order_and_records_restock_on_ok_response(): void
    {
        Http::fake(['*/supplier/status/*' => Http::response(['status' => 'ok'])]);

        $this->runJob();

        $this->order->refresh();
        $this->assertEquals(Order::STATUS_RESERVED, $this->order->status);

        $this->assertDatabaseHas('inventory_movements', [
            'sku'      => 'ABC123',
            'order_id' => $this->order->id,
            'type'     => InventoryMovement::TYPE_RESTOCK,
            'qty'      => 3,
        ]);

        $this->assertDatabaseHas('inventories', [
            'sku'          => 'ABC123',
            'qty_on_hand'  => 3,
            'qty_reserved' => 3,
        ]);
    }

    public function test_ok_response_creates_inventory_row_when_sku_has_no_prior_stock(): void
    {
        Http::fake(['*/supplier/status/*' => Http::response(['status' => 'ok'])]);

        $this->assertDatabaseMissing('inventories', ['sku' => 'ABC123']);

        $this->runJob();

        $this->assertDatabaseHas('inventories', ['sku' => 'ABC123']);
    }

    // ---------------------------------------------------------------
    // Supplier responds "fail"
    // ---------------------------------------------------------------

    public function test_fails_order_on_fail_response(): void
    {
        Http::fake(['*/supplier/status/*' => Http::response(['status' => 'fail'])]);

        $this->runJob();

        $this->order->refresh();
        $this->assertEquals(Order::STATUS_FAILED, $this->order->status);
    }

    // ---------------------------------------------------------------
    // Supplier responds "delayed"
    // ---------------------------------------------------------------

    public function test_reschedules_job_on_first_delayed_response(): void
    {
        Queue::fake();
        Http::fake(['*/supplier/status/*' => Http::response(['status' => 'delayed'])]);

        $this->runJob(attempt: 0);

        Queue::assertPushed(CheckSupplierDelivery::class, function (CheckSupplierDelivery $job) {
            return $job->order->id === $this->order->id && $job->attempt === 1;
        });

        $this->order->refresh();
        $this->assertNotEquals(Order::STATUS_FAILED, $this->order->status);
    }

    public function test_reschedules_job_on_second_delayed_response(): void
    {
        Queue::fake();
        Http::fake(['*/supplier/status/*' => Http::response(['status' => 'delayed'])]);

        $this->runJob(attempt: 1);

        Queue::assertPushed(CheckSupplierDelivery::class, function (CheckSupplierDelivery $job) {
            return $job->attempt === 2;
        });
    }

    public function test_fails_order_after_two_delayed_retries(): void
    {
        Queue::fake();
        Http::fake(['*/supplier/status/*' => Http::response(['status' => 'delayed'])]);

        $this->runJob(attempt: 2);

        $this->order->refresh();
        $this->assertEquals(Order::STATUS_FAILED, $this->order->status);
        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------
    // Full retry flows
    // ---------------------------------------------------------------

    public function test_full_flow_delayed_then_ok(): void
    {
        Queue::fake();

        // Set up sequence: first call returns "delayed", second returns "ok"
        Http::fake([
            '*/supplier/status/*' => Http::sequence()
                ->push(['status' => 'delayed'])
                ->push(['status' => 'ok']),
        ]);

        $this->runJob(attempt: 0);
        Queue::assertPushed(CheckSupplierDelivery::class, fn ($j) => $j->attempt === 1);

        $this->runJob(attempt: 1);

        $this->order->refresh();
        $this->assertEquals(Order::STATUS_RESERVED, $this->order->status);
    }

    public function test_full_flow_delayed_twice_then_failed(): void
    {
        Queue::fake();
        Http::fake(['*/supplier/status/*' => Http::response(['status' => 'delayed'])]);

        $this->runJob(attempt: 0);
        $this->runJob(attempt: 1);
        $this->runJob(attempt: 2);

        $this->order->refresh();
        $this->assertEquals(Order::STATUS_FAILED, $this->order->status);
    }

    // ---------------------------------------------------------------
    // Unknown / unexpected status
    // ---------------------------------------------------------------

    public function test_fails_order_on_unknown_supplier_status(): void
    {
        Http::fake(['*/supplier/status/*' => Http::response(['status' => 'unknown_value'])]);

        $this->runJob();

        $this->order->refresh();
        $this->assertEquals(Order::STATUS_FAILED, $this->order->status);
    }

    // ---------------------------------------------------------------
    // Helper
    // ---------------------------------------------------------------

    private function runJob(int $attempt = 0): void
    {
        $job = new CheckSupplierDelivery($this->order->fresh(), $attempt);
        $job->handle(app(SupplierService::class));
    }
}
