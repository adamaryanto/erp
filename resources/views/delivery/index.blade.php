@extends('layouts.app')

@section('title', 'Rekap per Mobil - Accurate ERP RPA')

@section('content')
<div class="space-y-6">
    
    <!-- HEADER & BREADCRUMB BAR -->
    <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs mb-1.5">
                <span class="text-slate-400 font-medium">Accurate ERP</span>
                <span class="text-slate-300">/</span>
                <span class="text-slate-400 font-medium">Penimbangan</span>
                <span class="text-slate-300">/</span>
                <span class="text-[#1976D2] font-semibold">Rekap per Mobil</span>
            </div>
            <h1 class="text-xl font-semibold text-slate-900 tracking-tight">Rekap Penerimaan Ayam per Armada Mobil</h1>
            <p class="text-xs text-slate-500 mt-0.5">Satu sesi mencatat kedatangan satu truk armada. Angka timbangan ditarik otomatis & rumus susut DO dihitung presisi.</p>
        </div>
    </div>

    <!-- MAIN SECTION: FORM BUKA SESI + TABEL DAFTAR SESI MOBIL -->
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">
        
        <!-- LEFT COLUMN: FORM BUKA SESI BARU (5 COLS) -->
        <div class="xl:col-span-5 bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-[#1976D2]"></span>
                    <h2 class="font-semibold text-slate-800 text-sm uppercase">+ BUKA SESI MOBIL BARU</h2>
                </div>
                <span class="text-[10px] font-medium bg-blue-100 text-[#1976D2] px-2 py-0.5 rounded">Form Sesi</span>
            </div>

            <form action="{{ route('delivery.store') }}" method="POST" class="p-5 space-y-4">
                @csrf
                
                <!-- BAGIAN 1: INFORMASI ARMADA & SUPPLIER -->
                <div class="space-y-3 pb-3 border-b border-slate-100">
                    <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-[#1976D2]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        1. Informasi Armada & Supplier
                    </div>

                    <div>
                        <label class="block text-xs font-normal text-slate-600 mb-1">Pilih No. DO / Faktur <span class="text-[10px] text-emerald-600">→ supplier + kg + ekor keisi otomatis</span></label>
                        <select id="doSelect" name="do_id" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm text-slate-800 font-mono focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                            <option value="">-- Tanpa DO (isi manual) --</option>
                            @foreach($openOrders as $o)
                                <option value="{{ $o->id }}" data-supplier="{{ $o->supplier_id }}" data-kg="{{ $o->target_kg }}" data-ekor="{{ $o->target_ekor }}">{{ $o->number }} — {{ number_format($o->target_kg, 2, '.', ',') }} kg / {{ number_format($o->target_ekor) }} ekr</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-normal text-slate-600 mb-1">Supplier Plasma / Peternak <span class="text-rose-500">*</span></label>
                        <select name="supplier_id" id="supplierSelect" required class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm text-slate-800 font-normal focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                            <option value="">-- Pilih Supplier Plasma --</option>
                            @foreach($suppliers as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-normal text-slate-600 mb-1">Nomor Mobil / Plat <span class="text-rose-500">*</span></label>
                            <input type="text" name="vehicle_no" id="vehicleInput" required placeholder="B 9482 FJA" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm font-mono uppercase focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                        </div>
                        <div>
                            <label class="block text-xs font-normal text-slate-600 mb-1">Tanggal Timbang <span class="text-rose-500">*</span></label>
                            <input type="date" name="delivered_at" required value="{{ today()->format('Y-m-d') }}" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm text-slate-800 focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-normal text-slate-600 mb-1">Nama Supir</label>
                        <input type="text" name="driver_name" id="driverInput" list="driverList" placeholder="cth: Danto" autocomplete="off" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm text-slate-800 focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                        <datalist id="driverList">
                            @foreach($knownDrivers as $d)
                                <option value="{{ $d->driver_name }}" data-plate="{{ $d->vehicle_no }}"></option>
                            @endforeach
                        </datalist>
                        <p class="text-[10px] text-slate-500 mt-1">Pilih supir lama → nomor mobil keisi otomatis.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-normal text-slate-600 mb-1">Jenis Ayam</label>
                        <select name="jenis_ayam" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm text-slate-800 focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                            <option value="biasa" selected>Ayam Biasa</option>
                            <option value="parent">Parent</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-normal text-slate-600 mb-1">Tipe Keranjang (Tare Default)</label>
                        <select name="basket_id" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm text-slate-800 focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                            <option value="">-- Gunakan Tare Default Keranjang --</option>
                            @foreach($baskets as $b)
                                <option value="{{ $b->id }}">{{ $b->code }} (Tare {{ number_format($b->tare_default_kg, 1) }} kg)</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <!-- BAGIAN 2: DATA SURAT JALAN (DO) -->
                <div class="space-y-3 pb-3 border-b border-slate-100">
                    <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        2. Target Surat Jalan (DO) <span class="text-[10px] text-emerald-600 normal-case">(terisi otomatis dari No. DO di atas)</span>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-normal text-slate-600 mb-1">Berat DO Asal (kg) <span class="text-rose-500">*</span></label>
                            <input type="number" name="do_weight" id="doWeightInput" required step="0.01" min="0" placeholder="3796.00" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                        </div>
                        <div>
                            <label class="block text-xs font-normal text-slate-600 mb-1">Total Ekor Ayam <span class="text-rose-500">*</span></label>
                            <input type="number" name="total_ekor" id="ekorInput" required step="1" min="0" placeholder="1540" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                        </div>
                    </div>
                </div>

                <!-- BAGIAN 3: POTONGAN & KONDISI KHUSUS -->
                <div class="space-y-3">
                    <div class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        3. Potongan / Condition Non-Timbang
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="block text-[11px] font-normal text-slate-600 mb-1">Mati (ekor)</label>
                            <input type="number" name="dead_count" step="1" min="0" value="0" class="w-full h-9 px-2 border border-slate-300 rounded text-xs font-mono focus:ring-2 focus:ring-[#1976D2]">
                        </div>
                        <div>
                            <label class="block text-[11px] font-normal text-slate-600 mb-1">Mati (kg)</label>
                            <input type="number" name="dead_kg" step="0.01" min="0" value="0" class="w-full h-9 px-2 border border-slate-300 rounded text-xs font-mono focus:ring-2 focus:ring-[#1976D2]">
                        </div>
                        <div>
                            <label class="block text-[11px] font-normal text-slate-600 mb-1">Basah (kg)</label>
                            <input type="number" name="wet_kg" step="0.01" min="0" value="0" class="w-full h-9 px-2 border border-slate-300 rounded text-xs font-mono focus:ring-2 focus:ring-[#1976D2]">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-normal text-slate-600 mb-1">Catatan Tambahan (Opsional)</label>
                        <input type="text" name="note" placeholder="Kondisi cuaca hujan / supir Budi" class="w-full h-9 px-3 border border-slate-300 rounded text-xs text-slate-800 focus:ring-2 focus:ring-[#1976D2]">
                    </div>
                </div>

                <!-- SUBMIT BUTTON -->
                <div class="pt-2">
                    <button type="submit" class="w-full h-11 bg-[#1976D2] hover:bg-[#1565C0] text-white text-sm font-semibold rounded-md shadow-sm transition flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                        <span>BUKA SESI & PROSES REKAP</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- RIGHT COLUMN: DAFTAR SESI REKAP PER MOBIL (7 COLS) -->
        <div class="xl:col-span-7 bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden space-y-0">
            
            <!-- Table Header Toolbar -->
            <div class="p-4 bg-slate-50 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-slate-800 text-sm">Daftar Sesi Rekap per Mobil</h3>
                    <p class="text-xs text-slate-500">Klik 'Detail & DHK' untuk melihat potongan & cetak dokumen</p>
                </div>
                <div class="text-xs text-slate-500 font-mono">
                    Total: <strong class="text-slate-800 font-medium">{{ $sessions->total() }}</strong> sesi
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="bg-slate-100 text-slate-700 font-semibold uppercase tracking-wider border-b border-slate-200">
                            <th class="py-3 px-4">Tgl / Mobil</th>
                            <th class="py-3 px-4">No. DO</th>   
                            <th class="py-3 px-4 text-right">Netto Timbang</th>
                            <th class="py-3 px-4 text-right">Susut DO</th>
                            <th class="py-3 px-4 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($sessions as $s)
                            <tr class="hover:bg-blue-50/40 transition-colors">
                                <td class="py-3 px-4">
                                    <div class="font-mono text-[#1976D2] text-sm font-semibold">{{ $s->vehicle_no }}</div>
                                    <div class="text-[11px] text-slate-500 font-mono">{{ $s->delivered_at->format('d/m/Y') }}</div>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="text-slate-900 font-medium font-mono">{{ $s->deliveryOrder->number ?? '—' }}</div>
                                    <div class="text-[10px] text-slate-400 font-mono">{{ $s->total_ekor }} ekor • DO: {{ number_format($s->do_weight, 2) }} kg</div>
                                </td>
                                <td class="py-3 px-4 text-right font-mono text-emerald-600 text-sm font-semibold">
                                    {{ $s->hasData() ? number_format($s->netTotal(), 2) . ' ' : '— ' }}<span class="text-[10px] text-slate-400 font-normal">kg</span>
                                </td>
                                <td class="py-3 px-4 text-right font-mono">
                                    @if(!$s->hasData())
                                        <span class="text-slate-300">—</span>
                                    @elseif(abs($s->shrinkage()) < 0.01)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium bg-emerald-100 text-emerald-800 border border-emerald-200">
                                            ✓ BALANCED
                                        </span>
                                    @else
                                        <div class="text-amber-600 font-medium">
                                            {{ number_format($s->shrinkage(), 2) }} kg
                                        </div>
                                        <span class="text-[10px] px-1.5 py-0.2 rounded font-medium {{ $s->shrinkagePct() > 2.0 ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700' }}">
                                            {{ number_format($s->shrinkagePct(), 2) }}%
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <a href="{{ route('delivery.show', $s) }}" class="px-2.5 py-1.5 bg-blue-50 hover:bg-blue-100 text-[#1976D2] font-semibold rounded border border-blue-200 text-xs transition flex items-center gap-1">
                                            <span>Detail & DHK</span>
                                        </a>

                                        <form action="{{ route('delivery.destroy', $s) }}" method="POST" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus sesi mobil {{ $s->vehicle_no }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="p-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded border border-rose-200 text-xs transition" title="Hapus Sesi">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-10 text-center text-slate-400">
                                    Belum ada sesi penerimaan mobil. Silakan buka sesi baru melalui form di samping.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bottom -->
            @if($sessions->hasPages())
                <div class="p-3 bg-slate-50 border-t border-slate-200 flex justify-center">
                    {{ $sessions->links() }}
                </div>
            @endif

        </div>

    </div>

    <script>
        // Pilih DO → supplier + kg + ekor keisi otomatis (masih bisa diubah manual).
        document.getElementById('doSelect').addEventListener('change', function () {
            const opt = this.selectedOptions[0];
            if (!opt || !opt.value) return;
            const sup = document.getElementById('supplierSelect');
            const kg = document.getElementById('doWeightInput');
            const ek = document.getElementById('ekorInput');
            if (sup && opt.dataset.supplier) sup.value = opt.dataset.supplier;
            if (kg && opt.dataset.kg) kg.value = opt.dataset.kg;
            if (ek && opt.dataset.ekor) ek.value = opt.dataset.ekor;
        });
        // Ketik/pilih supir lama → plat terakhirnya keisi otomatis.
        document.getElementById('driverInput').addEventListener('input', function () {
            const list = document.getElementById('driverList');
            const plate = document.getElementById('vehicleInput');
            if (!list || !plate) return;
            const hit = [...list.options].find(o => o.value.toLowerCase() === this.value.toLowerCase());
            if (hit && hit.dataset.plate) plate.value = hit.dataset.plate;
        });
    </script>

</div>
@endsection
