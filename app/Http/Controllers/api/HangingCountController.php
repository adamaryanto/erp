<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\HangingCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HangingCountController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station' => 'nullable|string|max:20',
            'count' => 'required|integer|min:1|max:10000',
            'direction' => 'nullable|string|in:L2R,R2L',
            'note' => 'nullable|string|max:255',
        ]);

        $count = HangingCount::create([
            'station' => strtoupper(trim($validated['station'] ?? 'POS-01')) ?: 'POS-01',
            'count' => (int) $validated['count'],
            'direction' => $validated['direction'] ?? 'L2R',
            'note' => $validated['note'] ?? null,
            'counted_at' => now(),
        ]);

        return response()->json(['status' => 'saved', 'hanging_count' => $count], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $station = strtoupper(trim($request->query('station', 'POS-01') ?: 'POS-01'));
        $rows = HangingCount::where('station', $station)
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'station', 'count', 'direction', 'note', 'counted_at']);

        return response()->json(['rows' => $rows], 200);
    }
}
