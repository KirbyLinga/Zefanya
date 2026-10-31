<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\PlatformSetting;
use App\Models\Admin\PlatformSettingHistory;
use App\Services\Admin\PlatformSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformSettingController extends Controller
{
    public function index(): View
    {
        return view('Admin.Settings.index', [
            'settings' => PlatformSetting::query()->whereIn('key', array_keys(PlatformSettingService::DEFINITIONS))->orderBy('id')->get(),
            'definitions' => PlatformSettingService::DEFINITIONS,
            'history' => PlatformSettingHistory::query()->with('admin')->latest('id')->paginate(20),
        ]);
    }

    public function update(Request $request, PlatformSetting $platformSetting, PlatformSettingService $settings): RedirectResponse
    {
        $changed = $settings->update($request->user('admin'), $platformSetting, $request->only(['value', 'expected_value']));

        return redirect()->route('admin.settings.index')
            ->with('success', $changed ? 'Setting saved. The change has been recorded.' : 'No setting change was needed.');
    }
}
