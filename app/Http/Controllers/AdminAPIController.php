<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ApiKey;

class AdminAPIController extends Controller
{
    public function list()
    {
        return response()->json(ApiKey::all());
    }

    public function create(Request $request)
    {
        $request->validate(['name' => 'required|string|max:50']);
        $key = ApiKey::create([
            'name' => $request->name,
            'key' => bin2hex(random_bytes(16)),
        ]);
        return response()->json($key);
    }

    public function revoke($id)
    {
        ApiKey::destroy($id);
        return response()->json(['message' => 'API key revoked']);
    }
}