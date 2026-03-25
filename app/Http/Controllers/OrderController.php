<?php

namespace App\Http\Controllers;

use App\Events\OrderCreated;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = Order::create([
            'sku'    => $request->validated('sku'),
            'qty'    => $request->validated('qty'),
            'status' => Order::STATUS_PENDING,
        ]);

        OrderCreated::dispatch($order);

        return OrderResource::make($order)
            ->response()
            ->setStatusCode(201);
    }

    public function show(int $id): OrderResource
    {
        return OrderResource::make(Order::findOrFail($id));
    }
}
