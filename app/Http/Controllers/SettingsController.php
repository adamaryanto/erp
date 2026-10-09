<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /**
     * Show the settings page.
     */
    public function index(): View
    {
        // TODO produksi: aktifkan lagi $this->authorize('admin') saat login diwajibkan.
        $settings = Setting::config();

        return view('settings.index', compact('settings'));
    }

    /**
     * Update settings.
     */
    public function update(Request $request): RedirectResponse
    {
        // TODO produksi: aktifkan lagi $this->authorize('admin') saat login diwajibkan.
        $validated = $request->validate([
            'com_port' => 'required|string',
            'baud_rate' => 'required|integer',
            'parity' => 'required|string|in:N,E,O',
            'parsing_regex' => 'required|string',
            'sampling_hz' => 'required|integer|min:5|max:100',
            'duration_sec' => 'required|integer|min:1|max:60',
            'trim_percent' => 'required|integer|min:0|max:50',
            'unstable_threshold_kg' => 'required|numeric|min:0.1|max:5',
            'auto_mode_enabled' => 'boolean',
            'auto_trigger_kg' => 'required|numeric|min:5|max:20',
            'auto_empty_kg' => 'required|numeric|min:0.5|max:5',
            'auto_duration_sec' => 'required|numeric|min:0.5|max:60',
            'beep_enabled' => 'boolean',
            'printer_name' => 'nullable|string|max:255',
            'receipt_paper' => 'required|string|in:58mm,80mm',
            'receipt_footer' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'company_address' => 'nullable|string|max:500',
            'company_phone' => 'nullable|string|max:100',
            'company_logo' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'cam_snapshot_url' => 'nullable|string|max:500',
            'cam_stream_url' => 'nullable|string|max:500',
            'cam_rotate' => 'nullable|integer|in:0,90,180,270',
            'ocr_min_conf' => 'nullable|numeric|min:0|max:1',
        ]);

        $settings = Setting::config();

        if ($request->hasFile('company_logo')) {
            if ($settings->company_logo) {
                Storage::disk('public')->delete($settings->company_logo);
            }
            $validated['company_logo'] = $request->file('company_logo')->store('company', 'public');
        } else {
            unset($validated['company_logo']);
        }

        $settings->update($validated);

        return redirect()->route('settings.index')->with('success', 'Pengaturan diperbarui');
    }
}
