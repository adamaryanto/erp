<?php

namespace App\Http\Controllers;

use App\Models\Basket;
use App\Models\DeliveryOrder;
use App\Models\ScaleStation;
use App\Models\Setting;
use Illuminate\View\View;

class WeighingLiveController extends Controller
{
    /**
     * Show the weighing live page (mode mesin).
     */
    public function index(): View
    {
        return $this->render('mesin');
    }

    /**
     * Show the weighing live page (mode kamera).
     */
    public function cam(): View
    {
        return $this->render('cam');
    }

    private function render(string $mode): View
    {
        // Privasi pemasok: nama supplier TIDAK dikirim ke halaman timbang.
        // Operator pilih No. DO (tanpa nama), supplier diisi server dari DO.
        $openOrders = DeliveryOrder::where('status', 'open')
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->get(['id', 'number', 'target_kg', 'target_ekor']);
        $baskets = Basket::all();
        $settings = Setting::config();
        $stations = ScaleStation::where('is_active', true)->orderBy('code')->get();

        return view('weighing.live', compact('openOrders', 'baskets', 'settings', 'stations', 'mode'));
    }
}
