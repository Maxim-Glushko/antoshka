<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->string('sku');
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // reserve | restock
            $table->unsignedInteger('qty');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('sku');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
