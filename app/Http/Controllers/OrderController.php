<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    // ✅ Create Order
    public function store(Request $request)
    {
        $validated = $request->validate([
            'pair' => 'required|string',
            'side' => 'required|in:buy,sell',
            'type' => 'required|in:limit,market,stop',
            'price' => 'nullable|numeric',
            'amount' => 'required|numeric|min:0.00001',
            'trigger_price' => 'nullable|numeric',
        ]);

        $order = Order::create([
            'user_id' => Auth::id() ?? 1, // fallback if testing without auth
            'pair' => strtoupper($validated['pair']),
            'side' => $validated['side'],
            'type' => $validated['type'],
            'price' => $validated['price'] ?? null,
            'amount' => $validated['amount'],
            'trigger_price' => $validated['trigger_price'] ?? null,
            'order_id' => 'ORD-' . strtoupper(Str::random(10)),
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order created successfully',
            'data' => $order
        ]);
    }

    // ✅ Get All Orders for User
    public function index()
    {
        $orders = Order::where('user_id', Auth::id() ?? 1)->latest()->get();
        return response()->json($orders);
    }

    // ✅ Cancel Order
    public function cancel($id)
    {
        $order = Order::where('id', $id)->where('user_id', Auth::id() ?? 1)->firstOrFail();
        $order->status = 'cancelled';
        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully',
            'data' => $order
        ]);
    }
}