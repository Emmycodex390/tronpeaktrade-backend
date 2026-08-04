<?php

namespace App\Http\Controllers;

use App\Models\DepositAddress;
use Illuminate\Http\Request;

class DepositAddressController extends Controller
{
    /**
     * GET /api/deposit-addresses — any authenticated user, read-only.
     * Only returns active addresses; inactive ones are admin-side only
     * (e.g. being rotated out) and shouldn't be shown to depositors.
     */
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => DepositAddress::where('active', true)->get(),
        ]);
    }

    /**
     * GET /api/admin/deposit-addresses — admin sees everything, including
     * inactive entries, so they can be re-enabled later.
     */
    public function adminIndex()
    {
        return response()->json(['success' => true, 'data' => DepositAddress::orderBy('coin')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'coin' => 'required|string',
            'network' => 'required|string',
            'address' => 'required|string',
            'active' => 'nullable|boolean',
        ]);

        $entry = DepositAddress::updateOrCreate(
            ['coin' => strtoupper($data['coin']), 'network' => $data['network']],
            ['address' => $data['address'], 'active' => $data['active'] ?? true]
        );

        return response()->json(['success' => true, 'data' => $entry]);
    }

    public function update(Request $request, $id)
    {
        $entry = DepositAddress::findOrFail($id);

        $data = $request->validate([
            'coin' => 'sometimes|string',
            'network' => 'sometimes|string',
            'address' => 'sometimes|string',
            'active' => 'sometimes|boolean',
        ]);

        if (isset($data['coin'])) {
            $data['coin'] = strtoupper($data['coin']);
        }

        $entry->update($data);

        return response()->json(['success' => true, 'data' => $entry]);
    }

    public function destroy($id)
    {
        DepositAddress::destroy($id);
        return response()->json(['success' => true]);
    }
}
