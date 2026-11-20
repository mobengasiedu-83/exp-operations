<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;

class SettingController extends Controller
{
    /**
     * Display a listing of settings.
     */
    public function index(): Response
    {
        $this->authorize('view settings');

        $settings = Setting::orderBy('group')->orderBy('key')->paginate(25);

        return inertia('Admin/Settings/Index', [
            'settings' => $settings,
        ]);
    }

    /**
     * Update the specified setting.
     */
    public function update(Request $request, Setting $setting): RedirectResponse
    {
        $this->authorize('edit settings');

        $validated = $request->validate([
            'value' => ['sometimes', 'nullable', 'string'],
            'cast' => ['sometimes', 'string', Rule::in(['string', 'boolean', 'integer', 'float', 'array'])],
            'is_feature_flag' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $setting->update($validated);

        return redirect()->route('admin.settings.index')
            ->with('success', 'Setting updated successfully.');
    }
}
