<?php

namespace App\Http\Controllers;

use App\Models\Basket;
use App\Models\ScaleStation;
use App\Models\Setting;
use App\Models\Supplier;
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
        $suppliers = Supplier::all();
        $baskets = Basket::all();
        $settings = Setting::config();
        $stations = ScaleStation::where('is_active', true)->orderBy('code')->get();

        return view('weighing.live', compact('suppliers', 'baskets', 'settings', 'stations', 'mode'));
    }
}
