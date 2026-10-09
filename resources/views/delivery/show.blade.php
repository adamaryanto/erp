@extends('layouts.app')

@section('title', 'Form DHK Mobil ' . $session->vehicle_no . ' - Accurate ERP')

@section('content')
<style>
    @media print {
        body * { visibility: hidden; }
        #print-area, #print-area * { visibility: visible; }
        #print-area { position: absolute; left: 0; top: 0; width: 100%; padding: 0; margin: 0; }
    }
</style>

<div class="space-y-6">
    
    <!-- HEADER BAR & ACTION BUTTONS -->
    <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs mb-1.5">
                <span class="text-slate-400 font-medium">Accurate ERP</span>
                <span class="text-slate-300">/</span>
                <a href="{{ route('delivery.index') }}" class="text-slate-400 hover:text-[#1976D2] font-medium">Rekap per Mobil</a>
                <span class="text-slate-300">/</span>
                <span class="text-[#1976D2] font-semibold">{{ $session->vehicle_no }}</span>
            </div>
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-semibold text-slate-900 font-mono tracking-tight">{{ $session->vehicle_no }}</h1>
            </div>
            <p class="text-xs text-slate-500 mt-0.5">Tanggal Kedatangan: <strong class="text-slate-700 font-mono font-semibold">{{ $session->delivered_at->format('d/m/Y') }}</strong> • Total Ekor: <strong class="text-slate-700 font-mono font-semibold">{{ $session->total_ekor }} ekor</strong></p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('delivery.index') }}" class="h-10 px-3 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-md border border-slate-300 flex items-center gap-1.5 transition">
                <span>← Kembali</span>
            </a>

            <a href="{{ route('delivery.excel', $session) }}" class="h-10 px-4 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-md shadow-sm flex items-center gap-2 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                <span>EXCEL</span>
            </a>

            <a href="{{ route('delivery.pdf', $session) }}" target="_blank" class="h-10 px-4 bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold rounded-md shadow-sm flex items-center gap-2 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                <span>PDF</span>
            </a>

            <button onclick="window.print()" class="h-10 px-4 bg-[#1976D2] hover:bg-[#1565C0] text-white text-xs font-semibold rounded-md shadow-sm flex items-center gap-2 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4H7a2 2 0 01-2-2v-4a2 2 0 012-2h10a2 2 0 012 2v4a2 2 0 01-2 2h-2zm-4-4a2 2 0 100-4 2 2 0 000 4z"></path></svg>
                <span>CETAK FORM DHK</span>
            </button>
        </div>
    </div>

    <!-- RINGKASAN ANGKA KPI (4 STAT CARDS) -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        
        <!-- CARD 1 -->
        <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
            <p class="text-slate-500 text-xs font-medium uppercase">Timbang Kotor ①</p>
            <p class="text-2xl font-semibold font-mono text-slate-900 mt-1">
                {{ number_format($session->grossTotal(), 2) }} <span class="text-xs text-slate-400 font-normal">kg</span>
            </p>
            <p class="text-[11px] text-slate-500 mt-1 font-mono">{{ $session->basketCount() }} keranjang ditimbang</p>
        </div>

        <!-- CARD 2 -->
        <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
            <p class="text-slate-500 text-xs font-medium uppercase">Timbang Bersih ②</p>
            @if($session->hasData())
                <p class="text-2xl font-semibold font-mono text-emerald-600 mt-1">
                    {{ number_format($session->netTotal(), 2) }} <span class="text-xs text-slate-400 font-normal">kg</span>
                </p>
                <p class="text-[11px] text-slate-500 mt-1 font-mono">−{{ number_format($session->basketDeduction(), 2) }} krj −{{ number_format($session->dead_kg, 2) }} mati</p>
            @else
                <p class="text-2xl font-semibold font-mono text-slate-300 mt-1">—</p>
                <p class="text-[11px] text-slate-400 mt-1 font-mono">belum ada timbangan masuk</p>
            @endif
        </div>

        <!-- CARD 3 -->
        <div class="{{ ($session->hasData() && $session->isBalanced()) ? 'bg-white' : ($session->hasData() ? 'bg-amber-50 border-amber-300' : 'bg-white') }} p-4 rounded-lg border shadow-sm">
            <p class="text-slate-500 text-xs font-medium uppercase">Susut ③ + ④ (DO vs Bersih)</p>
            @if($session->hasData())
                <p class="text-2xl font-semibold font-mono mt-1 {{ $session->isBalanced() ? 'text-slate-900' : 'text-amber-600' }}">
                    {{ number_format($session->shrinkage(), 2) }} <span class="text-xs text-slate-400 font-normal">kg</span>
                </p>
                <p class="text-[11px] font-mono mt-1 font-semibold {{ $session->isBalanced() ? 'text-emerald-600' : 'text-amber-600' }}">
                    {{ $session->isBalanced() ? '✓ BALANCED' : number_format($session->shrinkagePct(), 2) . '% (DO: ' . number_format($session->do_weight, 2) . 'kg)' }}
                </p>
            @else
                <p class="text-2xl font-semibold font-mono mt-1 text-slate-300">—</p>
                <p class="text-[11px] font-mono mt-1 text-slate-400">belum ada timbangan masuk</p>
            @endif
        </div>

        <!-- CARD 4 -->
        <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
            <p class="text-slate-500 text-xs font-medium uppercase">Rata-rata Ayam ⑤</p>
            <p class="text-2xl font-semibold font-mono text-slate-900 mt-1">
                {{ number_format($session->avgBird(), 2) }} <span class="text-xs text-slate-400 font-normal">kg/ekor</span>
            </p>
            <p class="text-[11px] text-slate-500 mt-1 font-mono">{{ $session->total_ekor }} ekor (mati: {{ $session->dead_count }} ekor)</p>
        </div>

    </div>

    <!-- MAIN WORKSPACE GRID: FORM PARAMETER + AREA DHK PRINT -->
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">
        
        <!-- LEFT COLUMN: FORM UPDATE SESI (4 COLS) -->
        <div class="xl:col-span-4 bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                <h2 class="font-semibold text-slate-800 text-sm uppercase">UPDATE PARAMETER SESI</h2>
                <span class="text-[10px] font-medium bg-blue-100 text-[#1976D2] px-2 py-0.5 rounded">Form Parameter</span>
            </div>

            <form action="{{ route('delivery.update', $session) }}" method="POST" class="p-5 space-y-3.5">
                @csrf
                @method('PUT')

                <div>
                    <label class="block text-xs font-normal text-slate-600 mb-1">Nomor Mobil / Plat <span class="text-[10px] text-amber-600">kunci link ke timbangan</span></label>
                    <input type="text" name="vehicle_no" required maxlength="20" value="{{ $session->vehicle_no }}" placeholder="cth: B 9482 FJA" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm font-mono font-bold uppercase focus:ring-2 focus:ring-[#1976D2]">
                    <p class="text-[10px] text-slate-500 mt-1">Harus sama dengan plat yang diisi di halaman timbang, kalau beda rekap kosong.</p>
                </div>

                <div>
                    <label class="block text-xs font-normal text-slate-600 mb-1">Tipe Keranjang (Tare Default)</label>
                    <select name="basket_id" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm text-slate-800 focus:ring-2 focus:ring-[#1976D2]">
                        <option value="">-- Tanpa Potongan --</option>
                        @foreach($baskets as $b)
                            <option value="{{ $b->id }}" @selected($session->basket_id == $b->id)>{{ $b->code }} (Tare {{ number_format($b->tare_default_kg, 1) }} kg)</option>
                        @endforeach
                    </select>
                    <p class="text-[10px] text-slate-500 mt-1">Potongan otomatis: {{ $session->basketCount() }} × {{ number_format($session->tarePerBasket(), 1) }} = {{ number_format($session->basketCount() * $session->tarePerBasket(), 2) }} kg</p>
                </div>

                <div>
                    <label class="block text-xs font-normal text-slate-600 mb-1">Override Potongan Keranjang (kg) <span class="text-[10px] text-amber-600">opsional</span></label>
                    <input type="number" name="basket_deduction_manual" step="0.01" min="0" value="{{ $session->basket_deduction_manual }}" placeholder="Kosongkan untuk otomatis" class="w-full h-10 px-3 border border-amber-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-amber-500">
                    <p class="text-[10px] text-slate-500 mt-1">Isi hanya jika keranjang campur/basah (beda tare). Kosongkan = otomatis dari master.</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-normal text-slate-600 mb-1">DO Asal (kg)</label>
                        <input type="number" name="do_weight" required step="0.01" min="0" value="{{ $session->do_weight }}" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-[#1976D2]">
                    </div>
                    <div>
                        <label class="block text-xs font-normal text-slate-600 mb-1">Total Ekor</label>
                        <input type="number" name="total_ekor" required step="1" min="0" value="{{ $session->total_ekor }}" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-[#1976D2]">
                    </div>
                </div>

                <div class="grid grid-cols-4 gap-2">
                    <div>
                        <label class="block text-[11px] font-normal text-slate-600 mb-1">Mati (ekor)</label>
                        <input type="number" name="dead_count" step="1" min="0" value="{{ $session->dead_count }}" class="w-full h-9 px-2 border border-slate-300 rounded text-xs font-mono focus:ring-2 focus:ring-[#1976D2]">
                    </div>
                    <div>
                        <label class="block text-[11px] font-normal text-slate-600 mb-1">Mati (kg)</label>
                        <input type="number" name="dead_kg" step="0.01" min="0" value="{{ $session->dead_kg }}" class="w-full h-9 px-2 border border-slate-300 rounded text-xs font-mono focus:ring-2 focus:ring-[#1976D2]">
                    </div>
                    <div>
                        <label class="block text-[11px] font-normal text-slate-600 mb-1">Basah (ekor)</label>
                        <input type="number" name="wet_count" step="1" min="0" value="{{ $session->wet_count }}" class="w-full h-9 px-2 border border-slate-300 rounded text-xs font-mono focus:ring-2 focus:ring-[#1976D2]">
                    </div>
                    <div>
                        <label class="block text-[11px] font-normal text-slate-600 mb-1">Basah (kg)</label>
                        <input type="number" name="wet_kg" step="0.01" min="0" value="{{ $session->wet_kg }}" class="w-full h-9 px-2 border border-slate-300 rounded text-xs font-mono focus:ring-2 focus:ring-[#1976D2]">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-normal text-slate-600 mb-1">Nama Supir</label>
                    <input type="text" name="driver_name" value="{{ $session->driver_name }}" placeholder="cth: Danto" class="w-full h-9 px-3 border border-slate-300 rounded text-xs text-slate-800 focus:ring-2 focus:ring-[#1976D2]">
                </div>

                <div>
                    <label class="block text-xs font-normal text-slate-600 mb-1">Jenis Ayam</label>
                    <select name="jenis_ayam" class="w-full h-9 px-3 border border-slate-300 rounded text-xs text-slate-800 focus:ring-2 focus:ring-[#1976D2]">
                        <option value="biasa" {{ ($session->jenis_ayam ?? 'biasa') === 'biasa' ? 'selected' : '' }}>Ayam Biasa</option>
                        <option value="parent" {{ ($session->jenis_ayam ?? '') === 'parent' ? 'selected' : '' }}>Parent</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-normal text-slate-600 mb-1">Catatan Tambahan</label>
                    <input type="text" name="note" value="{{ $session->note }}" class="w-full h-9 px-3 border border-slate-300 rounded text-xs text-slate-800 focus:ring-2 focus:ring-[#1976D2]">
                </div>

                @if($session->warningDeadKg() || $session->warningEkorPerBasket() || $session->warningShrinkageNegative())
                    <div class="space-y-2 p-3 bg-yellow-50 border border-yellow-300 rounded">
                        @if($session->warningDeadKg())
                            <p class="text-[11px] text-yellow-800 font-semibold">⚠️ {{ $session->warningDeadKg() }}</p>
                        @endif
                        @if($session->warningEkorPerBasket())
                            <p class="text-[11px] text-yellow-800 font-semibold">⚠️ {{ $session->warningEkorPerBasket() }}</p>
                        @endif
                        @if($session->warningShrinkageNegative())
                            <p class="text-[11px] text-red-800 font-semibold">🔴 {{ $session->warningShrinkageNegative() }}</p>
                        @endif
                    </div>
                @endif

                <div class="pt-2">
                    <button type="submit" class="w-full h-11 bg-[#1976D2] hover:bg-[#1565C0] text-white text-sm font-semibold rounded-md shadow-sm transition">
                        SIMPAN & HITUNG ULANG DHK
                    </button>
                </div>
            </form>
        </div>

        <!-- RIGHT COLUMN: DHK PRINT PREVIEW (8 COLS) -->
        <div class="xl:col-span-8 bg-white rounded-lg border border-slate-200 shadow-sm p-6" id="print-area">
            
            <div class="text-center border-b-2 border-slate-900 pb-3 mb-4 space-y-0.5">
                <h2 class="font-semibold text-lg tracking-wider text-slate-900">{{ $settings->company_name ?? 'PT RUMAH POTONG AYAM (RPA) BAROKAH JAYA' }}</h2>
                <h3 class="font-semibold text-sm tracking-wide uppercase text-slate-700">FORM PENGIRIMAN AYAM HIDUP (DHK)</h3>
                <p class="text-[11px] text-slate-500 font-mono">{{ $settings->company_address ?? 'Jl. Industri Raya No. 88, Cikarang' }} • DHK ERP SYSTEM</p>
            </div>

            <!-- HEADER INFO GRID: kiri = No DO + Do asal + Tgl + mobil, kanan = Rata2 -->
            <div class="grid grid-cols-[1fr_1.5fr] gap-x-8 gap-y-1 text-xs font-mono mb-4 bg-slate-50 p-3 rounded border border-slate-200">
                <div class="space-y-1">
                    <div>No DO/Faktur : <strong class="text-slate-900 font-semibold">{{ $session->deliveryOrder->number ?? '—' }}</strong></div>
                    <div>Do asal :</div>
                    <div class="grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 pl-4">
                        <span>Kg ayam</span><strong class="text-slate-900 font-semibold">: {{ number_format($session->do_weight, 2, ',', '.') }} Kg</strong>
                        <span>total ekor</span><strong class="text-slate-900 font-semibold">: {{ number_format($session->total_ekor, 0, ',', '.') }} ekor</strong>
                    </div>
                </div>
                <div class="space-y-1 border-l border-slate-200 pl-3">
                    <div>Tgl Prod : <strong class="text-slate-900 font-semibold">{{ $session->delivered_at->format('d/m/Y') }}</strong></div>
                    <div>No mobil / Nama supir : <strong class="text-slate-900 font-semibold">{{ $session->vehicle_no }} / {{ $session->driver_name ?: '—' }}</strong></div>
                    <div class="pt-1">Rata2 berat ayam setelah timbang : <strong class="text-slate-900 font-semibold whitespace-nowrap">{{ number_format($session->avgBird(), 2, ',', '.') }} Kg/ekor</strong></div>
                </div>
            </div>

            <!-- WEIGHINGS MATRIX TABLE (selalu 6 pasang No/Kg, kolom kosong di-padding) -->
            @php
                $pairCount = 6;
                $items = $weighings->values();
                $perCol = (int) ceil(max($items->count(), 1) / $pairCount);
                $cols = $items->chunk($perCol);
                while ($cols->count() < $pairCount) { $cols->push(collect()); }
            @endphp
            <table class="w-full text-xs font-mono border-collapse border border-slate-400 mb-4">
                <thead>
                    <tr class="bg-slate-100 border-b border-slate-400 text-slate-700">
                        @for($p = 0; $p < $pairCount; $p++)
                            <th class="border border-slate-400 px-2 py-1 text-center">No</th>
                            <th class="border border-slate-400 px-2 py-1 text-right">KG</th>
                        @endfor
                    </tr>
                </thead>
                <tbody>
                    @if($items->isEmpty())
                        <tr>
                            <td colspan="12" class="border border-slate-400 px-2 py-4 text-center text-slate-400">Belum ada timbangan masuk</td>
                        </tr>
                    @else
                    @for($i = 0; $i < $perCol; $i++)
                        <tr>
                            @foreach($cols as $colIdx => $col)
                                @php $w = $col->values()->get($i); $seqNo = $w ? ($colIdx * $perCol + $i + 1) : null; @endphp
                                <td class="border border-slate-400 px-2 py-1 text-center text-slate-500">{{ $seqNo ?? '' }}</td>
                                <td class="border border-slate-400 px-2 py-1 text-right font-semibold text-slate-800">{{ $w ? number_format($w->gross_avg, 2) : '' }}</td>
                            @endforeach
                        </tr>
                    @endfor
                    <tr class="bg-slate-200 border-b-2 border-slate-400 font-semibold text-slate-700">
                        @foreach($cols as $colIdx => $col)
                            <td class="border border-slate-400 px-2 py-1 text-center text-slate-600">TTL</td>
                            <td class="border border-slate-400 px-2 py-1 text-right text-slate-800">{{ number_format($col->sum('gross_avg'), 2) }}</td>
                        @endforeach
                    </tr>
                    @endif
                </tbody>
            </table>

            <!-- SUMMARY DEDUCTIONS FOOTER GRID -->
            <div class="grid grid-cols-2 gap-x-8 gap-y-1 text-xs font-mono bg-slate-50 p-3 rounded border border-slate-200 mb-6">
                <div>TIMBANG KOTOR   : <strong class="text-slate-900 font-semibold">{{ number_format($session->grossTotal(), 2) }} kg</strong></div>
                <div>SURAT JALAN DO  : <strong class="text-slate-900 font-semibold">{{ number_format($session->do_weight, 2) }} kg</strong></div>
                <div>POTONG KERANJANG: <strong class="text-amber-700 font-semibold">{{ number_format($session->basketDeduction(), 2) }} kg</strong> ({{ $session->basketCount() }} × {{ number_format($session->tarePerBasket(), 1) }}kg)</div>
                <div>SUSUT DO        : <strong class="{{ $session->hasData() && $session->shrinkage() > 0 ? 'text-amber-600' : 'text-emerald-600' }} font-semibold">{{ $session->hasData() ? number_format($session->shrinkage(), 2) . ' kg' : '—' }}</strong></div>
                <div>POTONG AYAM MATI: <strong class="text-rose-600 font-semibold">{{ number_format($session->dead_kg, 2) }} kg</strong> ({{ $session->dead_count }} ekor)</div>
                <div>PERSENTASE SUSUT: <strong class="{{ $session->hasData() && $session->shrinkagePct() > 2.0 ? 'text-rose-600' : 'text-slate-900' }} font-semibold">{{ $session->hasData() ? number_format($session->shrinkagePct(), 2) . '%' : '—' }}</strong></div>
                <div>POTONG BASAH    : <strong class="text-amber-700 font-semibold">{{ number_format($session->wet_kg, 2) }} kg</strong></div>
                <div>TIMBANG BERSIH  : <strong class="text-emerald-700 text-sm font-semibold">{{ $session->hasData() ? number_format($session->netTotal(), 2) . ' kg' : '—' }}</strong></div>
            </div>

        </div>

    </div>

</div>

        </div>

    </div>

</div>
@endsection
