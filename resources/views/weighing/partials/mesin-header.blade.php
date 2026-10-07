<!-- BREADCRUMB MESIN -->
<div class="mb-5 flex items-center justify-between bg-white px-4 py-3 rounded-lg border border-gray-200 shadow-sm">
    <div class="flex items-center gap-2 text-xs">
        <span class="text-gray-400 font-medium">Accurate ERP</span>
        <span class="text-gray-300">/</span>
        <span class="text-gray-400 font-medium">Penimbangan</span>
        <span class="text-gray-300">/</span>
        <span class="text-blue-700 font-bold">Timbang Mesin</span>
    </div>
    <div class="flex items-center gap-2">
    <button @click="recheckConnection()"
            :class="isConnected ? 'bg-emerald-50 text-emerald-700 border-emerald-300' : 'bg-rose-50 text-rose-700 border-rose-300'"
            title="Klik untuk cek ulang koneksi ke bridge"
            class="px-3 py-1.5 rounded-md border text-xs font-semibold flex items-center gap-2">
        <span class="w-2 h-2 rounded-full" :class="isConnected ? 'bg-emerald-500' : 'bg-rose-500'"></span>
        <span x-text="connectionPillLabel"></span>
    </button>
    <template x-if="usbSupported && weightSource !== 'usb'">
        <button @click="connectUsb()"
                title="Baca timbangan langsung via kabel USB (Chrome/Edge, tanpa bridge)"
                class="px-3 py-1.5 rounded-md border border-indigo-300 bg-indigo-50 text-indigo-700 text-xs font-semibold flex items-center gap-2">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
            <span>HUBUNGKAN USB</span>
        </button>
    </template>
    <template x-if="weightSource === 'usb'">
        <button @click="disconnectUsb()"
                title="Putus USB dan kembali ke mode bridge"
                class="px-3 py-1.5 rounded-md border border-indigo-300 bg-indigo-600 text-white text-xs font-semibold flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-white animate-pulse"></span>
            <span>USB <span x-text="selectedStation"></span> — PUTUSKAN</span>
        </button>
    </template>
    <template x-if="!usbSupported">
        <span class="text-[10px] text-gray-400 font-medium hidden sm:inline">USB langsung: pakai Chrome/Edge</span>
    </template>
    <a href="{{ route('buglog.index') }}?autocheck=1"
       title="Diagnosa otomatis: timbangan, laptop, atau web?"
       class="px-3 py-1.5 rounded-md border border-rose-300 bg-rose-50 text-rose-700 text-xs font-semibold flex items-center gap-2">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
        <span>CEK ERROR</span>
    </a>
    </div>
</div>