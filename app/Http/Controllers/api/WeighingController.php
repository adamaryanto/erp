<?php

namespace App\Http\Controllers\api;

use App\Events\WeightReceived;
use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use App\Models\DeliverySession;
use App\Models\LineCounter;
use App\Models\ScaleStation;
use App\Models\User;
use App\Models\Weighing;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WeighingController extends Controller
{
    /**
     * Kode pos dari request (body/query), default POS-01 untuk kompatibilitas lama.
     */
    private function stationCode(Request $request): string
    {
        return strtoupper(trim($request->input('station', 'POS-01') ?: 'POS-01'));
    }

    /**
     * Sumber angka: 'mesin' (kabel/USB/bridge) atau 'cam' (AI kamera).
     * Default mesin agar bridge lama tetap jalan. Cache dipisah per sumber
     * supaya dua sumber tidak timpa-menimpa.
     */
    private function sourceCode(Request $request): string
    {
        $source = strtolower(trim($request->input('source', 'mesin') ?: 'mesin'));

        return in_array($source, ['mesin', 'cam'], true) ? $source : 'mesin';
    }

    /**
     * Store live weight reading from bridge.
     * Disimpan di cache 30 detik PER POS agar bisa dibaca frontend
     * via GET /api/weighings/live?station=POS-01.
     */
    public function storeLive(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'weight' => 'required|numeric',
            'station' => 'nullable|string|max:20',
            'source' => 'nullable|string|max:10',
        ]);

        $code = $this->stationCode($request);
        $source = $this->sourceCode($request);
        ScaleStation::resolve($code);

        Cache::put("live_weight:{$code}:{$source}", [
            'weight' => (float) $validated['weight'],
            'at' => now()->toIso8601String(),
        ], 30);

        // TEMPORARY (gampang dihapus): riwayat 60 sampel terakhir per pos
        // untuk deteksi "angka beku". Expire sendiri 10 menit.
        $histKey = "live_weight_history:{$code}:{$source}";
        $hist = Cache::get($histKey, []);
        $hist[] = ['w' => (float) $validated['weight'], 'at' => now()->toIso8601String()];
        if (count($hist) > 60) {
            $hist = array_slice($hist, -60);
        }
        Cache::put($histKey, $hist, 600);

        try {
            broadcast(new WeightReceived($validated['weight']));
        } catch (\Throwable $e) {
            // Jangan gagalkan request kalau broadcast bermasalah (misal driver log).
            report($e);
        }

        return response()->json([
            'status' => 'received',
            'station' => $code,
            'source' => $source,
            'weight' => $validated['weight'],
        ]);
    }

    /**
     * Ambil angka live terakhir untuk ditampilkan di web.
     * connected = true jika data masuk < 4 detik lalu (bridge jalan + kabel konek).
     */
    public function getLive(Request $request): JsonResponse
    {
        $code = $this->stationCode($request);
        $source = $this->sourceCode($request);
        $live = Cache::get("live_weight:{$code}:{$source}");

        // Fallback: data lama sebelum ada scope (per pos, lalu global).
        if (! $live && $source === 'mesin') {
            $live = Cache::get('live_weight:'.$code);
        }
        if (! $live && $code === 'POS-01' && $source === 'mesin') {
            $live = Cache::get('live_weight');
        }

        if (! $live) {
            return response()->json([
                'station' => $code,
                'source' => $source,
                'weight' => null,
                'connected' => false,
                'age_seconds' => null,
            ]);
        }

        $age = (int) abs(now()->diffInSeconds(Carbon::parse($live['at'])));

        // CAM ~1.5 bacaan/detik: umur >2 dtk sudah basi. Peringatan kuning
        // di 2 dtk (badge), putus di 3 dtk — angka hantu tidak dipajang lama.
        $maxAge = $source === 'cam' ? 3 : 3;

        return response()->json([
            'station' => $code,
            'source' => $source,
            'weight' => $live['weight'],
            'connected' => $age <= $maxAge,
            'age_seconds' => $age,
        ]);
    }

    /**
     * Heartbeat dari bridge di PC pos timbang (tiap ~10 detik).
     * Isinya status driver/COM di mesin itu — karena web tidak bisa
     * mengintip file PC lain, bridge-lah yang melapor ke sini.
     */
    public function bridgeHeartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hostname' => 'nullable|string|max:255',
            'station' => 'nullable|string|max:20',
            'com_port' => 'nullable|string|max:50',
            'ports' => 'nullable|array',
            'installers' => 'nullable|array',
        ]);

        $code = $this->stationCode($request);
        $station = ScaleStation::resolve($code);

        if (! empty($validated['com_port'])) {
            $station->update(['com_port' => $validated['com_port']]);
        }

        Cache::put('bridge_status:'.$code, [
            'hostname' => $validated['hostname'] ?? null,
            'com_port' => $validated['com_port'] ?? null,
            'ports' => $validated['ports'] ?? [],
            'installers' => $validated['installers'] ?? [],
            'at' => now()->toIso8601String(),
        ], 120);

        return response()->json(['status' => 'ok', 'station' => $code]);
    }

    /**
     * Status terakhir bridge yang dilaporkan (untuk dipantau dari web).
     */
    public function bridgeStatus(Request $request): JsonResponse
    {
        $code = $this->stationCode($request);
        $status = Cache::get('bridge_status:'.$code);

        // Fallback: bridge lama tanpa station menulis key global lama.
        if (! $status && $code === 'POS-01') {
            $status = Cache::get('bridge_status');
        }

        if (! $status) {
            return response()->json(['station' => $code, 'reported' => false]);
        }

        $age = (int) abs(now()->diffInSeconds(Carbon::parse($status['at'])));

        return response()->json([
            'station' => $code,
            'reported' => true,
            'fresh' => $age <= 30,
            'age_seconds' => $age,
            'hostname' => $status['hostname'],
            'com_port' => $status['com_port'],
            'ports' => $status['ports'],
            'installers' => $status['installers'],
        ]);
    }

    /**
     * Status semua pos + heartbeat masing-masing (untuk dashboard).
     */
    public function bridgeStatuses(): JsonResponse
    {
        $stations = ScaleStation::orderBy('code')->get();

        $data = $stations->map(function (ScaleStation $station) {
            $status = Cache::get('bridge_status:'.$station->code);
            if (! $status && $station->code === 'POS-01') {
                $status = Cache::get('bridge_status');
            }

            $age = $status ? (int) abs(now()->diffInSeconds(Carbon::parse($status['at']))) : null;

            return [
                'code' => $station->code,
                'name' => $station->name,
                'location' => $station->location,
                'online' => $status !== null && $age <= 30,
                'age_seconds' => $age,
                'hostname' => $status['hostname'] ?? null,
                'com_port' => $status['com_port'] ?? $station->com_port,
            ];
        });

        return response()->json(['stations' => $data]);
    }

    /**
     * Ringkasan live untuk dashboard monitoring: angka tiap pos,
     * total hari ini per tahap, counter gantungan, dan yield karkas.
     */
    public function monitoringSummary(): JsonResponse
    {
        $stations = ScaleStation::where('is_active', true)->orderBy('code')->get();

        $stationData = $stations->map(function (ScaleStation $station) {
            $live = Cache::get('live_weight:'.$station->code);
            if (! $live && $station->code === 'POS-01') {
                $live = Cache::get('live_weight');
            }
            $age = $live ? (int) abs(now()->diffInSeconds(Carbon::parse($live['at']))) : null;

            $today = Weighing::whereDate('weighed_at', today())
                ->where('scale_station_id', $station->id);

            return [
                'code' => $station->code,
                'name' => $station->name,
                'stage' => $station->stage,
                'online' => $live !== null && $age <= 3,
                'live_weight' => $live['weight'] ?? null,
                'age_seconds' => $age,
                'today_kg' => (float) (clone $today)->sum('gross_avg'),
                'today_count' => (clone $today)->count(),
            ];
        });

        $byStage = [];
        foreach (['hidup', 'gantung', 'karkas', 'parting', 'gudang'] as $stage) {
            $rows = $stationData->where('stage', $stage);
            $byStage[$stage] = [
                'kg' => round($rows->sum('today_kg'), 2),
                'count' => $rows->sum('today_count'),
                'online' => $rows->contains('online', true),
            ];
        }

        $counters = LineCounter::orderBy('code')->get();
        $ekorHidup = (int) DeliverySession::whereDate('delivered_at', today())->sum('total_ekor');
        $ekorGantung = (int) $counters->where('stage', 'gantung')->sum('value');

        $liveKg = $byStage['hidup']['kg'];
        $carcassKg = $byStage['karkas']['kg'];
        $yieldPct = $liveKg > 0 ? $carcassKg / $liveKg * 100 : 0;

        return response()->json([
            'now' => now()->toIso8601String(),
            'stations' => $stationData,
            'stages' => $byStage,
            'counters' => $counters,
            'ekor_hidup' => $ekorHidup,
            'ekor_gantung' => $ekorGantung,
            'yield' => [
                'live_kg' => $liveKg,
                'carcass_kg' => $carcassKg,
                'pct' => round($yieldPct, 2),
                'drop_kg' => round($liveKg - $carcassKg, 2),
                'ekor_hilang' => max(0, $ekorHidup - $ekorGantung),
            ],
        ]);
    }

    /**
     * Finalize a weighing session and save to database.
     */
    public function finalize(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'do_id' => 'nullable|exists:delivery_orders,id',
            'basket_id' => 'required|exists:baskets,id',
            'station' => 'nullable|string|max:20',
            'source' => 'nullable|string|max:10',
            'vehicle_no' => 'nullable|string|max:20',
            'gross_avg' => 'required|numeric',
            'tare_kg' => 'required|numeric|between:1,10',
            'min_kg' => 'required|numeric',
            'max_kg' => 'required|numeric',
            'stddev' => 'required|numeric',
            'raw_samples' => 'required|array',
            'is_manual_tare' => 'boolean',
            'note' => 'nullable|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Catat payload ringkas (tanpa raw_samples yg panjang) biar 422
            // bisa ditelusur dari laravel.log tanpa tebak-tebakan.
            $payload = $request->except('raw_samples');
            $payload['raw_samples_count'] = is_array($request->input('raw_samples')) ? count($request->input('raw_samples')) : null;
            \Illuminate\Support\Facades\Log::warning('finalize 422', ['errors' => $e->errors(), 'payload' => $payload]);
            throw $e;
        }

        $netKg = $validated['gross_avg'] - $validated['tare_kg'];

        // API dipanggil dari browser (session) maupun bridge Python (tanpa login),
        // sedangkan operator_id NOT NULL. Fallback ke user login, kalau tidak ada
        // pakai operator/admin pertama agar tidak 500.
        $operatorId = auth()->id()
            ?? User::where('role', 'operator')->value('id')
            ?? User::first()?->id;

        $station = ScaleStation::resolve($validated['station'] ?? null);

        // Privasi pemasok: halaman timbang kirim do_id, supplier diisi server
        // dari DO (nama pemasok tidak pernah turun ke browser operator).
        $doId = $validated['do_id'] ?? null;
        $supplierId = $validated['supplier_id'] ?? null;
        if ($doId) {
            $do = DeliveryOrder::findOrFail($doId);
            $supplierId = $do->supplier_id;
        }
        if (! $supplierId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pilih No. DO dulu (atau supplier).',
            ], 422);
        }

        $weighing = Weighing::create([
            'supplier_id' => $supplierId,
            'do_id' => $doId,
            'basket_id' => $validated['basket_id'],
            'scale_station_id' => $station->id,
            'source' => $this->sourceCode($request),
            'operator_id' => $operatorId,
            'vehicle_no' => strtoupper(trim($validated['vehicle_no'] ?? '')) ?: '-',
            'weighed_at' => now(),
            'gross_avg' => $validated['gross_avg'],
            'tare_kg' => $validated['tare_kg'],
            'net_kg' => $netKg,
            'min_kg' => $validated['min_kg'],
            'max_kg' => $validated['max_kg'],
            'stddev' => $validated['stddev'],
            'raw_samples' => $validated['raw_samples'],
            'is_manual_tare' => $validated['is_manual_tare'] ?? false,
            'note' => $validated['note'] ?? null,
        ]);

        return response()->json([
            'status' => 'saved',
            'weighing' => $weighing->load(['supplier', 'basket', 'operator', 'station']),
        ], 201);
    }

    /**
     * Get weighings for today, optionally filtered by station code.
     */
    public function today(Request $request): JsonResponse
    {
        $query = Weighing::whereDate('weighed_at', today())
            ->with(['supplier', 'basket', 'operator', 'station'])
            ->orderByDesc('weighed_at');

        if ($request->filled('station')) {
            $station = ScaleStation::where('code', strtoupper(trim($request->input('station'))))->first();
            $query->when($station, fn ($q) => $q->where('scale_station_id', $station->id));
        }

        $weighings = $query->get();

        return response()->json([
            'weighings' => $weighings,
            'total_count' => $weighings->count(),
            'total_net_kg' => $weighings->sum('net_kg'),
        ]);
    }

    /**
     * Delete a weighing (admin only).
     */
    public function destroy(Weighing $weighing): JsonResponse
    {
        // MVP lokal: hapus langsung. Proteksi admin ada di route web history.destroy.
        // TODO produksi: cek role admin via token/sanctum.
        $weighing->delete();

        return response()->json(['status' => 'deleted']);
    }
}
