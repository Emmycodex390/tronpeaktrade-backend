<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\P2PVendor;
use App\Models\P2POrder;

class P2PController extends Controller
{
    // Fetch all vendors
    public function index(Request $request)
    {
        $currency = $request->query('currency', 'NGN');
        $asset = $request->query('asset', 'USDT');

        $vendors = P2PVendor::where('currency', $currency)
            ->orderBy('online', 'desc')
            ->get();

        return response()->json(['status' => 'success', 'data' => $vendors]);
    }

    // GET /api/p2p/vendors/sync — nothing else populates this table
    // (no seeder exists), so without this it would just stay empty
    // forever, same gap staking_plans had before its own sync route.
    public function sync()
    {
        $defaults = [
            ['name' => 'CryptoExpress NG', 'currency' => 'NGN', 'price' => 1650.00, 'min_limit' => 5000, 'max_limit' => 2000000, 'payment_methods' => ['Bank Transfer'], 'quantity' => 50000, 'trades' => 320, 'completion' => 98.5, 'verified' => true, 'online' => true],
            ['name' => 'FastTrade Hub', 'currency' => 'NGN', 'price' => 1645.00, 'min_limit' => 10000, 'max_limit' => 5000000, 'payment_methods' => ['Bank Transfer', 'Opay'], 'quantity' => 120000, 'trades' => 890, 'completion' => 99.1, 'verified' => true, 'online' => true],
            ['name' => 'QuickSwap Africa', 'currency' => 'NGN', 'price' => 1655.00, 'min_limit' => 2000, 'max_limit' => 1000000, 'payment_methods' => ['Bank Transfer', 'PalmPay'], 'quantity' => 30000, 'trades' => 145, 'completion' => 96.8, 'verified' => false, 'online' => true],
        ];

        foreach ($defaults as $vendor) {
            P2PVendor::updateOrCreate(
                ['name' => $vendor['name'], 'currency' => $vendor['currency']],
                $vendor
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Vendors synced.',
            'data' => P2PVendor::where('currency', 'NGN')->get(),
        ]);
    }

    // Place an order — previously had a literal "TODO: Save order in DB"
    // and just returned a fake success message with nothing persisted.
    public function placeOrder(Request $request)
    {
        $data = $request->validate([
            'vendor_id' => 'required|exists:p2_p_vendors,id',
            'amount' => 'required|numeric|min:1',
            'type' => 'required|in:buy,sell',
            'asset' => 'required|string|max:10',
        ]);

        $vendor = P2PVendor::findOrFail($data['vendor_id']);

        if ($data['amount'] < $vendor->min_limit || $data['amount'] > $vendor->max_limit) {
            return response()->json([
                'error' => "Amount must be between {$vendor->min_limit} and {$vendor->max_limit} {$vendor->currency}",
            ], 422);
        }

        $order = P2POrder::create([
            'user_id' => $request->user()->id,
            'vendor_id' => $vendor->id,
            'type' => $data['type'],
            'asset' => $data['asset'],
            'amount' => $data['amount'],
            'status' => 'pending',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Order placed successfully',
            'data' => $order,
        ]);
    }

    // GET /api/p2p/orders — the user's own P2P order history. Added
    // since there was previously no way to see an order after placing
    // it (it was never even saved).
    public function myOrders(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data' => P2POrder::with('vendor')
                ->where('user_id', $request->user()->id)
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }
}