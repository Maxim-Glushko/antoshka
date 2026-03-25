<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Fake Supplier Endpoints (local dev / demo only)
|--------------------------------------------------------------------------
| These simulate the external supplier API so the full flow can be tested
| without a real third-party service.  CSRF is excluded for supplier/*
| in bootstrap/app.php.
|
| Behaviour of GET /supplier/status/{ref}:
|   - 1st call  → "delayed"
|   - 2nd call+ → "ok"
| This exercises the retry logic automatically.
*/
if (app()->environment('local', 'testing')) {
    Route::post('/supplier/reserve', function (Request $request) {
        $ref = 'SUP-' . now()->format('YmdHis') . '-' . rand(1000, 9999);

        return response()->json(['accepted' => true, 'ref' => $ref]);
    });

    Route::get('/supplier/status/{ref}', function (string $ref) {
        $calls = Cache::increment('supplier_status_' . $ref);

        $status = $calls === 1 ? 'delayed' : 'ok';

        return response()->json(['status' => $status]);
    });
}
