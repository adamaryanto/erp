<?php

namespace App\Http\Controllers;

use App\Models\DeliveryOrder;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeliveryOrderController extends Controller
{
    /**
     * Daftar DO + form tambah. DO = patokan pembelian (1 DO bisa N truk).
     */
    public function index(): View
    {
        return view('delivery_orders.index', [
            'orders' => DeliveryOrder::with(['supplier', 'sessions'])
                ->orderByDesc('issued_at')
                ->orderByDesc('id')
                ->paginate(15),
            'suppliers' => Supplier::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (auth()->check() && ! auth()->user()->isAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'number' => 'required|string|max:50|unique:delivery_orders,number',
            'supplier_id' => 'required|exists:suppliers,id',
            'issued_at' => 'nullable|date',
            'target_kg' => 'required|numeric|min:0',
            'target_ekor' => 'required|integer|min:0',
            'note' => 'nullable|string',
        ]);
        $validated['number'] = strtoupper(trim($validated['number']));

        DeliveryOrder::create($validated);

        return redirect()->route('do.index')->with('success', 'DO tersimpan — siap dipilih saat buka sesi');
    }

    /** Kunci/buka DO (DO closed tidak muncul di dropdown buka sesi). */
    public function toggle(DeliveryOrder $order): RedirectResponse
    {
        if (auth()->check() && ! auth()->user()->isAdmin()) {
            abort(403);
        }

        $order->update(['status' => $order->isOpen() ? 'closed' : 'open']);

        return redirect()->route('do.index')->with('success', 'DO '.$order->number.' '.($order->isOpen() ? 'dibuka' : 'dikunci'));
    }

    public function destroy(DeliveryOrder $order): RedirectResponse
    {
        if (auth()->check() && ! auth()->user()->isAdmin()) {
            abort(403);
        }
        if ($order->sessions()->exists()) {
            return redirect()->route('do.index')->with('success', 'DO dipakai '.$order->sessions()->count().' sesi — lepas dulu sebelum hapus');
        }

        $order->delete();

        return redirect()->route('do.index')->with('success', 'DO dihapus');
    }
}
