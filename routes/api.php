<?php

use App\Http\Controllers\api\CamReadingController;
use App\Http\Controllers\api\WeighingController;
use App\Http\Controllers\CounterController;
use App\Http\Controllers\DiagnoseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// MVP lokal (Laragon, jaringan internal): endpoint timbangan dibuat publik
// tanpa auth:sanctum. Browser kirim session cookie + CSRF, bridge Python
// tidak punya token. Proteksi login tetap ada di routes web (auth middleware).
// TODO produksi: amankan destroy + tambah token khusus bridge.
Route::post('/weighings/live', [WeighingController::class, 'storeLive']);
Route::get('/weighings/live', [WeighingController::class, 'getLive']);
Route::post('/bridge/heartbeat', [WeighingController::class, 'bridgeHeartbeat']);
Route::get('/bridge/status', [WeighingController::class, 'bridgeStatus']);
Route::get('/bridge/statuses', [WeighingController::class, 'bridgeStatuses']);
Route::post('/diagnose', [DiagnoseController::class, 'diagnose']);
Route::get('/monitoring/summary', [WeighingController::class, 'monitoringSummary']);
Route::get('/counters', [CounterController::class, 'index']);
Route::post('/counters', [CounterController::class, 'store']);
Route::post('/counters/{counter}/increment', [CounterController::class, 'increment']);
Route::post('/counters/{counter}/reset', [CounterController::class, 'reset']);
Route::delete('/counters/{counter}', [CounterController::class, 'destroy']);
Route::post('/weighings/finalize', [WeighingController::class, 'finalize']);
Route::get('/weighings/today', [WeighingController::class, 'today']);
Route::delete('/weighings/{weighing}', [WeighingController::class, 'destroy']);
// Kamera AI: angka OCR + prediksi keranjang/ayam (pisah dari mesin).
// Service cam_ocr.py POST ke sini; frontend /timbang-cam baca live via
// GET /weighings/live?source=cam (diisi otomatis oleh controller ini).
Route::post('/cam/readings', [CamReadingController::class, 'store']);
Route::get('/cam/readings/today', [CamReadingController::class, 'today']);
Route::post('/cam/capture', [CamReadingController::class, 'capture']);
Route::post('/cam/service/start', [CamReadingController::class, 'startService']);
Route::post('/cam/service/stop', [CamReadingController::class, 'stopService']);
Route::post('/cam/service/roi', [CamReadingController::class, 'saveRoi']);
Route::post('/cam/service/auto', [CamReadingController::class, 'setAuto']);
Route::post('/cam/roi/auto', [CamReadingController::class, 'autoRoi']);
    Route::post('/cam/roi/autocal', [CamReadingController::class, 'autoCalibrate']);
    Route::get('/cam/roi/status', [CamReadingController::class, 'calibrationStatus']);
    Route::get('/cam/service/status', [CamReadingController::class, 'serviceStatus']);
