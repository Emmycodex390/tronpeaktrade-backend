<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SecurityLog;
use App\Models\Setting;

class SecurityController extends Controller
{
    public function listLogs()
    {
        return response()->json(SecurityLog::latest()->take(50)->get());
    }
}

class SettingsController extends Controller
{
    public function view()
    {
        return response()->json(Setting::all());
    }

    public function update(Request $request)
    {
        foreach($request->all() as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        return response()->json(['message' => 'Settings updated']);
    }
}