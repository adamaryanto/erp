<?php

use App\Http\Controllers\api\CamReadingController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeliveryOrderController;
use App\Http\Controllers\DeliverySessionController;
use App\Http\Controllers\DiagnoseController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SummaryController;
use App\Http\Controllers\WeighingLiveController;
use App\Models\Weighing;
use Illuminate\Support\Facades\Route;

Route::get('/', [WeighingLiveController::class, 'index'])->name('home');
Route::get('/timbang-cam', [WeighingLiveController::class, 'cam'])->name('cam.index');
// Proxy pratinjau: RTSP/MJPEG → 1 frame JPEG (browser tak bisa putar RTSP).
Route::get('/timbang-cam/snapshot', [CamReadingController::class, 'liveSnapshot'])->name('cam.snapshot');

// Auth routes (disimpan untuk nanti; untuk sekarang semua halaman publik tanpa login).
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.store');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// MVP lokal TANPA LOGIN: pos timbang satu PC, operator langsung pakai.
// TODO produksi: bungkus lagi dengan middleware(['auth']) + middleware(['admin'])
// untuk settings & hapus data (lihat EnsureAdmin + Gate 'admin').
Route::get('/dashboard', function () {
    $weighings = Weighing::whereDate('weighed_at', today())->get();
    $totalNetKg = $weighings->sum('net_kg');
    $totalCount = $weighings->count();

    return view('dashboard', compact('totalNetKg', 'totalCount'));
})->name('dashboard');

Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
// Bulk HARUS di atas {weighing} biar "bulk" tidak diikat sebagai ID.
Route::delete('/history/bulk', [HistoryController::class, 'destroyBulk'])->name('history.bulk');
Route::delete('/history/{weighing}', [HistoryController::class, 'destroy'])->name('history.destroy');

Route::get('/summary', [SummaryController::class, 'index'])->name('summary.index');
Route::get('/summary/export-csv', [SummaryController::class, 'exportCsv'])->name('summary.export-csv');
Route::get('/summary/excel', [SummaryController::class, 'excel'])->name('summary.excel');
Route::get('/summary/pdf', [SummaryController::class, 'pdf'])->name('summary.pdf');

Route::get('/master', [MasterController::class, 'index'])->name('master.index');

Route::get('/data-do', [DeliveryOrderController::class, 'index'])->name('do.index');
Route::post('/data-do', [DeliveryOrderController::class, 'store'])->name('do.store');
Route::post('/data-do/{order}/toggle', [DeliveryOrderController::class, 'toggle'])->name('do.toggle');
Route::delete('/data-do/{order}', [DeliveryOrderController::class, 'destroy'])->name('do.destroy');

Route::get('/rekap-mobil', [DeliverySessionController::class, 'index'])->name('delivery.index');
Route::post('/rekap-mobil', [DeliverySessionController::class, 'store'])->name('delivery.store');
Route::get('/rekap-mobil/{session}', [DeliverySessionController::class, 'show'])->name('delivery.show');
Route::get('/rekap-mobil/{session}/excel', [DeliverySessionController::class, 'excel'])->name('delivery.excel');
Route::get('/rekap-mobil/{session}/pdf', [DeliverySessionController::class, 'pdf'])->name('delivery.pdf');
Route::put('/rekap-mobil/{session}', [DeliverySessionController::class, 'update'])->name('delivery.update');
Route::delete('/rekap-mobil/{session}', [DeliverySessionController::class, 'destroy'])->name('delivery.destroy');

Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

Route::get('/bug-log', [DiagnoseController::class, 'index'])->name('buglog.index');

Route::post('/master/suppliers', [MasterController::class, 'storeSupplier'])->name('master.suppliers.store');
Route::put('/master/suppliers/{supplier}', [MasterController::class, 'updateSupplier'])->name('master.suppliers.update');
Route::delete('/master/suppliers/{supplier}', [MasterController::class, 'destroySupplier'])->name('master.suppliers.destroy');

Route::post('/master/baskets', [MasterController::class, 'storeBasket'])->name('master.baskets.store');
Route::put('/master/baskets/{basket}', [MasterController::class, 'updateBasket'])->name('master.baskets.update');
Route::delete('/master/baskets/{basket}', [MasterController::class, 'destroyBasket'])->name('master.baskets.destroy');

Route::post('/master/stations', [MasterController::class, 'storeStation'])->name('master.stations.store');
Route::put('/master/stations/{station}', [MasterController::class, 'updateStation'])->name('master.stations.update');
Route::delete('/master/stations/{station}', [MasterController::class, 'destroyStation'])->name('master.stations.destroy');
