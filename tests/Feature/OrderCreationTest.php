<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_order_with_pending_status_and_fires_event(): void
    {
        Event::fake();

        $response = $this->postJson('/api/order', ['sku' => 'ABC123', 'qty' => 3]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'sku'    => 'ABC123',
                'qty'    => 3,
                'status' => 'pending',
            ]);

        $this->assertDatabaseHas('orders', [
            'sku'    => 'ABC123',
            'qty'    => 3,
            'status' => 'pending',
        ]);

        Event::assertDispatched(OrderCreated::class, function (OrderCreated $e) {
            return $e->order->sku === 'ABC123' && $e->order->qty === 3;
        });
    }

    public function test_validates_sku_and_qty_are_required(): void
    {
        $response = $this->postJson('/api/order', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sku', 'qty']);
    }

    public function test_validates_qty_must_be_at_least_1(): void
    {
        $response = $this->postJson('/api/order', ['sku' => 'ABC123', 'qty' => 0]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['qty']);
    }

    public function test_can_retrieve_order_by_id(): void
    {
        $order = Order::create([
            'sku'    => 'ABC123',
            'qty'    => 2,
            'status' => Order::STATUS_PENDING,
        ]);

        $this->getJson('/api/orders/' . $order->id)
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $order->id, 'status' => 'pending']);
    }

    public function test_returns_404_for_missing_order(): void
    {
        $this->getJson('/api/orders/99999')->assertStatus(404);
    }
}
