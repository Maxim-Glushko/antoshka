<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SupplierService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.supplier.base_url', 'http://localhost'), '/');
    }

    /**
     * Ask supplier to reserve/deliver a SKU.
     *
     * @return array{accepted: bool, ref: string}
     */
    public function reserve(string $sku, int $qty): array
    {
        $response = Http::post("{$this->baseUrl}/supplier/reserve", [
            'sku' => $sku,
            'qty' => $qty,
        ]);

        return $response->json();
    }

    /**
     * Check delivery status for a supplier reference.
     *
     * @return string ok|fail|delayed
     */
    public function checkStatus(string $ref): string
    {
        $response = Http::get("{$this->baseUrl}/supplier/status/{$ref}");

        return $response->json('status', 'fail');
    }
}
