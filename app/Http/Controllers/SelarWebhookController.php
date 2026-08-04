<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\InvestmentPayment;

class SelarWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Log incoming webhook for debugging (optional)
        \Log::info('Selar Webhook:', $request->all());

        // Example Selar payload:
        // {
        //   "transaction_reference": "uuid-of-investment",
        //   "status": "paid",
        //   "amount": 1000
        // }

        $transactionId = $request->input('transaction_reference');
        $status = $request->input('status');

        if (!$transactionId || !$status) {
            return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        // Find investment by transaction_id
        $investment = InvestmentPayment::where('transaction_id', $transactionId)->first();

        if (!$investment) {
            return response()->json(['status' => 'error', 'message' => 'Investment not found'], 404);
        }

        // Update investment status
        if ($status === 'paid') {
            $investment->status = 'active';
            $investment->save();
        } elseif ($status === 'failed') {
            $investment->status = 'failed';
            $investment->save();
        }

        return response()->json(['status' => 'success']);
    }
}