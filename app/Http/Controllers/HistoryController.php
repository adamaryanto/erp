<?php

namespace App\Http\Controllers;

use App\Models\ScaleStation;
use App\Models\Weighing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistoryController extends Controller
{
    /**
     * Show the history page for today's weighings.
     */
    public function index(Request $request): View
    {
        $query = Weighing::whereDate('weighed_at', today())
            ->with(['supplier', 'basket', 'operator', 'station'])
            ->orderByDesc('weighed_at');

        $stationFilter = strtoupper(trim($request->input('station', '')));
        if ($stationFilter !== '') {
            $station = ScaleStation::where('code', $stationFilter)->first();
            $query->when($station, fn ($q) => $q->where('scale_station_id', $station->id));
        }

        $weighings = $query->paginate(15)->withQueryString();
        $stations = ScaleStation::orderBy('code')->get();

        return view('weighing.history', compact('weighings', 'stations', 'stationFilter'));
    }

    /**
     * Delete a weighing record.
     * TODO produksi: batasi admin + wajib alasan (saat login diwajibkan).
     */
    public function destroy(Weighing $weighing): RedirectResponse
    {
        $weighing->delete();

        return redirect()->route('history.index')->with('success', 'Penimbangan dihapus');
    }
}
