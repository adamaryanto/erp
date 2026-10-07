@extends('layouts.app')

@section('title', 'Riwayat Penimbangan')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Riwayat Penimbangan</h1>
            <p class="text-gray-600 mt-2">Data penimbangan hari ini</p>
        </div>
        <form method="GET" action="{{ route('history.index') }}" class="flex items-center gap-2">
            <label class="text-xs font-semibold text-gray-600">POS</label>
            <select name="station" onchange="this.form.submit()" class="h-10 px-3 border border-gray-300 rounded-md text-sm font-mono focus:ring-2 focus:ring-blue-500">
                <option value="">Semua pos</option>
                @foreach($stations as $st)
                    <option value="{{ $st->code }}" @selected($stationFilter === $st->code)>{{ $st->code }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pos</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jam</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Supplier</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mobil</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Keranjang</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Bruto</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Tara</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Netto</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($weighings as $w)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $w->id }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-mono font-bold text-gray-700">{{ $w->station->code ?? '—' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $w->weighed_at->format('H:i:s') }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $w->supplier->name }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $w->vehicle_no }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $w->basket->code }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">{{ number_format($w->gross_avg, 2) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">{{ number_format($w->tare_kg, 2) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-green-600 text-right">{{ number_format($w->net_kg, 2) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <div class="flex gap-2">
                                    <button class="text-blue-600 hover:text-blue-900" onclick="printReceipt({{ $w->id }})">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4H7a2 2 0 01-2-2v-4a2 2 0 012-2h10a2 2 0 012 2v4a2 2 0 01-2 2zm-6-4a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                        </svg>
                                    </button>
                                    @if(!auth()->check() || auth()->user()->isAdmin())
                                        <form action="{{ route('history.destroy', $w) }}" method="POST" class="inline" onsubmit="return confirm('Yakin hapus?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-900">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-6 py-4 text-center text-gray-500">Belum ada data penimbangan hari ini</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($weighings->hasPages())
        <div class="mt-6 flex justify-center">
            {{ $weighings->links() }}
        </div>
    @endif

    <script>
        function printReceipt(id) {
            // Placeholder for receipt printing logic
            alert('Fitur cetak struk akan diimplementasikan di fase berikutnya');
        }
    </script>
@endsection
