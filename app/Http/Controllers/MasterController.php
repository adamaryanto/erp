<?php

namespace App\Http\Controllers;

use App\Models\Basket;
use App\Models\ScaleStation;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MasterController extends Controller
{
    /**
     * Halaman Master: daftar supplier plasma & referensi tara keranjang.
     */
    public function index(): View
    {
        return view('master.index', [
            'suppliers' => Supplier::orderBy('name')->get(),
            'baskets' => Basket::orderBy('code')->get(),
            'stations' => ScaleStation::orderBy('code')->get(),
        ]);
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:30',
        ]);

        Supplier::create($validated);

        return redirect()->route('master.index')->with('success', 'Supplier ditambahkan');
    }

    public function updateSupplier(Request $request, Supplier $supplier): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:30',
        ]);

        $supplier->update($validated);

        return redirect()->route('master.index')->with('success', 'Supplier diperbarui');
    }

    public function destroySupplier(Supplier $supplier): RedirectResponse
    {
        if ($supplier->weighings()->exists() || $supplier->deliverySessions()->exists()) {
            return redirect()->route('master.index')->withErrors('Supplier sudah punya data timbang, tidak bisa dihapus');
        }

        $supplier->delete();

        return redirect()->route('master.index')->with('success', 'Supplier dihapus');
    }

    public function storeBasket(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:10|unique:baskets,code',
            'size' => 'nullable|string|max:20',
            'tare_default_kg' => 'required|numeric|between:1,10',
        ]);

        Basket::create($validated);

        return redirect()->route('master.index')->with('success', 'Keranjang ditambahkan');
    }

    public function updateBasket(Request $request, Basket $basket): RedirectResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:10|unique:baskets,code,'.$basket->id,
            'size' => 'nullable|string|max:20',
            'tare_default_kg' => 'required|numeric|between:1,10',
        ]);

        $basket->update($validated);

        return redirect()->route('master.index')->with('success', 'Keranjang diperbarui');
    }

    public function destroyBasket(Basket $basket): RedirectResponse
    {
        if ($basket->weighings()->exists() || $basket->deliverySessions()->exists()) {
            return redirect()->route('master.index')->withErrors('Keranjang sudah dipakai menimbang, tidak bisa dihapus');
        }

        $basket->delete();

        return redirect()->route('master.index')->with('success', 'Keranjang dihapus');
    }

    public function storeStation(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20|unique:scale_stations,code',
            'name' => 'required|string|max:255',
            'stage' => 'required|string|in:hidup,gantung,karkas,parting,gudang',
            'location' => 'nullable|string|max:255',
            'com_port' => 'nullable|string|max:20',
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));

        ScaleStation::create($validated);

        return redirect()->route('master.index')->with('success', 'Pos timbang ditambahkan. Jalankan bridge dengan --station '.$validated['code']);
    }

    public function updateStation(Request $request, ScaleStation $station): RedirectResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20|unique:scale_stations,code,'.$station->id,
            'name' => 'required|string|max:255',
            'stage' => 'required|string|in:hidup,gantung,karkas,parting,gudang',
            'location' => 'nullable|string|max:255',
            'com_port' => 'nullable|string|max:20',
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));

        $station->update($validated);

        return redirect()->route('master.index')->with('success', 'Pos timbang diperbarui');
    }

    public function destroyStation(ScaleStation $station): RedirectResponse
    {
        if ($station->weighings()->exists()) {
            return redirect()->route('master.index')->withErrors('Pos sudah punya data timbang, tidak bisa dihapus');
        }

        $station->delete();

        return redirect()->route('master.index')->with('success', 'Pos timbang dihapus');
    }
}
