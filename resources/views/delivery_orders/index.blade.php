@extends('layouts.app')

@section('title', 'Data DO / Faktur Pembelian')

@section('content')
<div class="space-y-6">

    <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
        <div class="flex items-center gap-2 text-xs mb-1.5">
            <span class="text-slate-400 font-medium">Accurate ERP</span>
            <span class="text-slate-300">/</span>
            <span class="text-slate-400 font-medium">Pembelian</span>
            <span class="text-slate-300">/</span>
            <span class="text-[#1976D2] font-semibold">Data DO / Faktur</span>
        </div>
        <h1 class="text-xl font-semibold text-slate-900 tracking-tight">Data DO / Faktur Pembelian</h1>
        <p class="text-xs text-slate-500 mt-0.5">Dicatat sekali oleh admin beli, dipakai otomatis tiap buka sesi (1 DO bisa N truk). DO yang dikunci tidak muncul di pilihan sesi.</p>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">

        <div class="xl:col-span-5 bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-4 bg-slate-50 border-b border-slate-200">
                <h2 class="font-semibold text-slate-800 text-sm uppercase">+ Input DO Baru</h2>
            </div>
            @if(!auth()->check() || auth()->user()->isAdmin())
            <form action="{{ route('do.store') }}" method="POST" class="p-5 space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-normal text-slate-600 mb-1">No. DO / Faktur <span class="text-rose-500">*</span></label>
                    <input type="text" name="number" required maxlength="50" placeholder="cth: DO-1012" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm font-mono uppercase focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                </div>
                <div>
                    <label class="block text-xs font-normal text-slate-600 mb-1">Pemasok <span class="text-rose-500">*</span></label>
                    <select name="supplier_id" required class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm text-slate-800 focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                        <option value="">-- Pilih Pemasok --</option>
                        @foreach($suppliers as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-normal text-slate-600 mb-1">Tanggal DO</label>
                        <input type="date" name="issued_at" value="{{ today()->format('Y-m-d') }}" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm text-slate-800 focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                    </div>
                    <div>
                        <label class="block text-xs font-normal text-slate-600 mb-1">Total Ekor <span class="text-rose-500">*</span></label>
                        <input type="number" name="target_ekor" required step="1" min="0" placeholder="1491" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-normal text-slate-600 mb-1">Total Kg (DO Asal) <span class="text-rose-500">*</span></label>
                    <input type="number" name="target_kg" required step="0.01" min="0" placeholder="3367.80" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                </div>
                <div>
                    <label class="block text-xs font-normal text-slate-600 mb-1">Catatan (opsional)</label>
                    <input type="text" name="note" placeholder="cth: DO gabungan 2 rit" class="w-full h-10 px-3 border border-slate-300 rounded-md text-sm text-slate-800 focus:ring-2 focus:ring-[#1976D2] focus:border-[#1976D2] transition">
                </div>
                <button class="w-full h-11 bg-[#1976D2] hover:bg-[#1565C0] text-white text-sm font-semibold rounded-md shadow-sm transition">SIMPAN DO</button>
            </form>
            @else
                <p class="p-5 text-sm text-gray-400">Hanya admin yang bisa input DO.</p>
            @endif
        </div>

        <div class="xl:col-span-7 bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                <h3 class="font-semibold text-slate-800 text-sm">Daftar DO / Faktur</h3>
                <span class="text-xs text-slate-500 font-mono">Total: <strong class="text-slate-800">{{ $orders->total() }}</strong></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                            <th class="px-4 py-2.5">No. DO</th>
                            <th class="px-3 py-2.5">Pemasok</th>
                            <th class="px-3 py-2.5 text-right">Target</th>
                            <th class="px-3 py-2.5">Progres Timbang</th>
                            <th class="px-3 py-2.5 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($orders as $o)
                            <tr class="hover:bg-blue-50/40">
                                <td class="px-4 py-2.5">
                                    <div class="font-mono font-bold text-slate-800">{{ $o->number }}</div>
                                    <div class="text-[11px] text-slate-500 font-mono">{{ $o->issued_at ? $o->issued_at->format('d/m/Y') : '—' }} • {{ $o->sessions->count() }} sesi</div>
                                </td>
                                <td class="px-3 py-2.5 text-xs text-slate-700">{{ $o->supplier->name }}</td>
                                <td class="px-3 py-2.5 text-xs font-mono text-right">{{ number_format($o->target_kg, 2, '.', ',') }} kg<br><span class="text-slate-500">{{ number_format($o->target_ekor) }} ekr</span></td>
                                <td class="px-3 py-2.5">
                                    <div class="w-28 bg-gray-200 rounded-full h-2 overflow-hidden">
                                        <div class="h-full {{ $o->isOpen() ? 'bg-emerald-500' : 'bg-slate-400' }}" style="width: {{ number_format($o->progressPct(), 1) }}%"></div>
                                    </div>
                                    <div class="text-[11px] font-mono text-slate-600 mt-1">{{ number_format($o->weighedNet(), 2, '.', ',') }} / {{ number_format($o->target_kg, 2, '.', ',') }} kg</div>
                                </td>
                                <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                    @if(!auth()->check() || auth()->user()->isAdmin())
                                        <form action="{{ route('do.toggle', $o) }}" method="POST" class="inline">
                                            @csrf
                                            <button class="text-xs font-bold {{ $o->isOpen() ? 'text-amber-600 hover:text-amber-800' : 'text-emerald-600 hover:text-emerald-800' }}">{{ $o->isOpen() ? 'KUNCI' : 'BUKA' }}</button>
                                        </form>
                                        <form action="{{ route('do.destroy', $o) }}" method="POST" class="inline ml-2" onsubmit="return confirm('Hapus DO {{ $o->number }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-rose-600 hover:text-rose-800 text-xs font-bold">HAPUS</button>
                                        </form>
                                    @else
                                        <span class="text-xs {{ $o->isOpen() ? 'text-emerald-600' : 'text-slate-400' }} font-bold">{{ $o->isOpen() ? 'OPEN' : 'CLOSED' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400 text-sm">Belum ada DO — input di form samping.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($orders->hasPages())
                <div class="p-3 bg-slate-50 border-t border-slate-200 flex justify-center">{{ $orders->links() }}</div>
            @endif
        </div>

    </div>

</div>
@endsection
