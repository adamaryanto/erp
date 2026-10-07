<?php

namespace App\Http\Controllers;

use App\Models\LineCounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CounterController extends Controller
{
    /**
     * Daftar semua counter garis (untuk counting gantung).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'counters' => LineCounter::orderBy('code')->get(),
        ]);
    }

    /**
     * Tambah counter baru (misal line gantung ke-2).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20|unique:line_counters,code',
            'name' => 'required|string|max:255',
            'stage' => 'nullable|string|max:20',
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));

        return response()->json([
            'counter' => LineCounter::create($validated),
        ], 201);
    }

    /**
     * Tambah/kurangi hitungan (step bisa negatif untuk koreksi).
     */
    public function increment(Request $request, LineCounter $counter): JsonResponse
    {
        $validated = $request->validate([
            'step' => 'nullable|integer|min:-10000|max:10000',
        ]);

        // Clamp di PHP dulu: kolom unsigned, decrement buta bisa jebol DB.
        $counter->update(['value' => max(0, $counter->value + ($validated['step'] ?? 1))]);

        return response()->json(['counter' => $counter->fresh()]);
    }

    /**
     * Nolkan counter (ganti shift / batch baru).
     */
    public function reset(LineCounter $counter): JsonResponse
    {
        $counter->update(['value' => 0]);

        return response()->json(['counter' => $counter->fresh()]);
    }

    /**
     * Hapus counter.
     */
    public function destroy(LineCounter $counter): JsonResponse
    {
        $counter->delete();

        return response()->json(['status' => 'deleted']);
    }
}
