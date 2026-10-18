<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\Setting;
use App\Support\WhatsAppPhone;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (Setting::get()->isEmpty()) {
            $data['id'] = '1';
            Setting::create(['id' => '1']);
        }
        $settings = Setting::first();

        return Inertia::render('admin/settings/index', ['settings' => $settings]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Setting $setting)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Setting $setting)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Setting $setting)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'commercial_whatsapp' => ['nullable', 'string', 'max:30'],
            'commercial_whatsapp_name' => ['nullable', 'string', 'max:100'],
            'commercial_whatsapp_message' => ['nullable', 'string', 'max:500'],
            'commercial_whatsapp_public' => ['required', 'boolean'],
            'commercial_whatsapp_internal' => ['required', 'boolean'],
            'web_chat_enabled' => ['sometimes', 'boolean'],
            'web_chat_welcome_message' => ['nullable', 'string', 'max:500'],
            'web_chat_business_hours' => ['nullable', 'regex:/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/'],
            'web_chat_offline_message' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->has('web_chat_enabled')) {
            $validated['web_chat_enabled'] = $request->boolean('web_chat_enabled');
        }

        $phone = trim((string) ($validated['commercial_whatsapp'] ?? ''));
        $normalizedPhone = WhatsAppPhone::normalize($phone);
        if ($phone !== '' && ! preg_match('/^55\d{10,11}$/', $normalizedPhone)) {
            return back()->withErrors(['commercial_whatsapp' => 'Informe um número com DDI, DDD e telefone válidos.']);
        }
        $validated['commercial_whatsapp'] = $phone === '' ? null : $normalizedPhone;

        $storePath = public_path('storage/logos');
        if ($request->hasfile('logo')) {
            $fileName = time().'.'.$request->logo->extension();
            $request->logo->move($storePath, $fileName);
            $previousLogo = $storePath.DIRECTORY_SEPARATOR.$setting->logo;
            if ($setting->logo && file_exists($previousLogo)) {
                unlink($previousLogo);
            }
        }
        $validated['logo'] = $request->hasfile('logo') ? $fileName : $setting->logo;
        $setting->update($validated);

        return redirect()->route('admin.settings.index')->with('success', 'Dados das configurções alterados com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Setting $setting)
    {
        //
    }
}
