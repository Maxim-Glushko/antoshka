<?php

namespace Database\Seeders;

use App\Models\Inventory;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['sku' => 'ABC123', 'qty_on_hand' => 50],
            ['sku' => 'XYZ999', 'qty_on_hand' => 10],
            ['sku' => 'LOW001', 'qty_on_hand' => 2],
            ['sku' => 'EMPTY1', 'qty_on_hand' => 0],
        ];

        foreach ($items as $item) {
            Inventory::updateOrCreate(
                ['sku' => $item['sku']],
                ['qty_on_hand' => $item['qty_on_hand'], 'qty_reserved' => 0],
            );
        }
    }
}
