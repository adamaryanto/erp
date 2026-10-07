@extends('layouts.app')

@section('title', 'Rekap Penimbangan')

@section('content')
    <div class="mb-8">
        <h1 class="text-3xl text-gray-900">Rekap Penimbangan Harian</h1>
        <p class="text-gray-600 mt-2">{{ now()->format('d-m-Y') }}</p>
    </div>

    <div class="flex gap-4 mb-8 overflow-x-auto pb-2">
        <div class="bg-white rounded-lg shadow p-5 min-w-[190px] flex-1">
            <p class="text-gray-600 text-sm">Total Netto</p>
            <p class="text-3xl text-gray-900 mt-2">{{ number_format($totalNetKg, 2) }} kg</p>
        </div>

        <div class="bg-white rounded-lg shadow p-5 min-w-[190px] flex-1">
            <p class="text-gray-600 text-sm">Total Keranjang</p>
            <p class="text-3xl text-gray-900 mt-2">{{ $totalCount }}</p>
        </div>

        <div class="bg-white rounded-lg shadow p-5 min-w-[190px] flex-1">
            <p class="text-gray-600 text-sm">Rata-rata per Keranjang</p>
            <p class="text-3xl text-gray-900 mt-2">{{ number_format($totalCount > 0 ? $totalNetKg / $totalCount : 0, 2) }} kg</p>
        </div>

        <div class="bg-white rounded-lg shadow p-5 min-w-[190px] flex-1">
            <p class="text-gray-600 text-sm">Total Supplier</p>
            <p class="text-3xl text-gray-900 mt-2">{{ $suppliers->where('weighings_count', '>', 0)->count() }}</p>
        </div>

        <div class="bg-white rounded-lg shadow p-5 min-w-[190px] flex-1">
            <p class="text-gray-600 text-sm">Susut Hari Ini</p>
            @if($dayDo <= 0)
                <p class="text-3xl text-gray-300 mt-2">—</p>
                <p class="text-xs text-gray-400 mt-2">{{ $hasEmptySessions ? 'Sesi ada, timbangan belum masuk' : 'Belum ada sesi DO' }}</p>
            @elseif(abs($daySusut) < 0.01)
                <p class="text-3xl text-emerald-600 mt-2">BALANCED</p>
                <p class="text-xs text-gray-500 mt-2 font-mono">DO = Bersih</p>
            @else
                <p class="text-3xl text-amber-600 mt-2 font-mono">{{ number_format($daySusut, 2) }}</p>
                <p class="text-xs text-gray-500 mt-2 font-mono">{{ number_format($dayPct, 2) }}% dari DO {{ number_format($dayDo, 2) }}</p>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg text-gray-900">Rekap per Supplier</h2>
            <div class="flex gap-2">
                <a href="{{ route('summary.excel') }}" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm py-2 px-4 rounded">
                    Excel
                </a>
                <a href="{{ route('summary.pdf') }}" target="_blank" class="bg-rose-600 hover:bg-rose-700 text-white text-sm py-2 px-4 rounded">
                    PDF
                </a>
                <a href="{{ route('summary.export-csv') }}" class="bg-green-600 hover:bg-green-700 text-white text-sm py-2 px-4 rounded">
                    Export CSV
                </a>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs text-gray-500 uppercase tracking-wider">Supplier</th>
                        <th class="px-6 py-3 text-right text-xs text-gray-500 uppercase tracking-wider">Keranjang</th>
                        <th class="px-6 py-3 text-right text-xs text-gray-500 uppercase tracking-wider">Total Netto</th>
                        <th class="px-6 py-3 text-right text-xs text-gray-500 uppercase tracking-wider">Rata-rata</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($suppliers->where('weighings_count', '>', 0) as $supplier)
                        @php
                            $supplierWeighings = $weighings->where('supplier_id', $supplier->id);
                            $supplierTotal = $supplierWeighings->sum('net_kg');
                            $supplierCount = $supplierWeighings->count();
                        @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $supplier->name }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">{{ $supplierCount }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 text-right">{{ number_format($supplierTotal, 2) }} kg</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">{{ number_format($supplierCount > 0 ? $supplierTotal / $supplierCount : 0, 2) }} kg</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-gray-500">Belum ada data</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <td class="px-6 py-3 text-sm text-gray-900">GRAND TOTAL</td>
                        <td class="px-6 py-3 text-sm text-gray-900 text-right">{{ $totalCount }}</td>
                        <td class="px-6 py-3 text-sm text-green-600 text-right">{{ number_format($totalNetKg, 2) }} kg</td>
                        <td class="px-6 py-3 text-sm text-gray-900 text-right">{{ number_format($totalCount > 0 ? $totalNetKg / $totalCount : 0, 2) }} kg</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
@endsection
