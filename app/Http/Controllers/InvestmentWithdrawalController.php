<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\InvestmentWithdrawalCodeMail;
use App\Models\InvestmentPayment;
use App\Models\InvestmentWithdrawalVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Admin-side management for matured-investment withdrawal verifications.
 * One investment can have several of these, created one at a time —
 * e.g. a standard confirmation, plus another added later if the account
 * looks compromised. Deliberately separate from Subscription Codes
 * (codes.tsx) and the unrelated account-wide VerificationCode gate.
 */
class InvestmentWithdrawalController extends Controller
{
    /**
     * GET /api/admin/investments/matured
     *
     * Completed investments with an unpaid remainder, each with its
     * full verification history so admin can see what's already been
     * sent/confirmed before deciding whether to add another.
     */
    public function matured()
    {
        $investments = InvestmentPayment::with(['user:id,name,email', 'withdrawalVerifications' => function ($q) {
            $q->orderBy('created_at', 'desc');
        }])
            ->where('status', 'completed')
            ->get()
            ->filter(function ($inv) {
                $totalOwed = $inv->amount + $inv->expected_profit;
                return ($totalOwed - ($inv->paid_out ?? 0)) > 0.000001;
            })
            ->map(function ($inv) {
                return [
                    'id' => $inv->id,
                    'user_name' => $inv->user->name ?? 'Unknown',
                    'user_email' => $inv->user->email ?? null,
                    'plan_name' => $inv->plan_name,
                    'amount_owed' => $inv->amount + $inv->expected_profit - ($inv->paid_out ?? 0),
                    'verifications' => $inv->withdrawalVerifications->map(fn ($v) => [
                        'id' => $v->id,
                        'label' => $v->label,
                        'sent_at' => $v->sent_at,
                        'verified_at' => $v->verified_at,
                    ]),
                ];
            })
            ->values();

        return response()->json(['data' => $investments]);
    }

    /**
     * POST /api/admin/investments/{id}/verifications
     *
     * Creates ONE new verification requirement for this investment and
     * immediately emails the code. Calling this again on an investment
     * that already has confirmed verifications adds another independent
     * requirement — e.g. for "I think this account was compromised,
     * re-verify before releasing funds" — without touching prior ones.
     */
    public function store(Request $request, $id)
    {
        $request->validate([
            'label' => 'required|string|min:2|max:120',
        ]);

        $investment = InvestmentPayment::with('user')->findOrFail($id);

        if ($investment->status !== 'completed') {
            return response()->json(['error' => 'This investment hasn\'t matured yet.'], 422);
        }

        if (!$investment->user || !$investment->user->email) {
            return response()->json(['error' => 'This user has no email on file to send a code to.'], 422);
        }

        $verification = InvestmentWithdrawalVerification::create([
            'investment_payment_id' => $investment->id,
            'created_by' => Auth::id(),
            'label' => $request->label,
            'code' => (string) random_int(100000, 999999),
            'sent_at' => now(),
        ]);

        Mail::to($investment->user->email)->send(new InvestmentWithdrawalCodeMail($investment, $verification));

        return response()->json([
            'status' => 'success',
            'message' => "Code sent to {$investment->user->email}.",
            'verification' => [
                'id' => $verification->id,
                'label' => $verification->label,
                'sent_at' => $verification->sent_at,
                'verified_at' => null,
            ],
        ]);
    }

    /**
     * POST /api/admin/investment-verifications/{verificationId}/resend
     *
     * Regenerates the code on an existing unconfirmed requirement and
     * re-emails it — for "the email didn't arrive," not for creating a
     * new independent requirement (use store() for that).
     */
    public function resend($verificationId)
    {
        $verification = InvestmentWithdrawalVerification::with('investment.user')->findOrFail($verificationId);

        if ($verification->verified_at) {
            return response()->json(['error' => 'This verification is already confirmed.'], 422);
        }

        if (!$verification->investment->user || !$verification->investment->user->email) {
            return response()->json(['error' => 'This user has no email on file to send a code to.'], 422);
        }

        $verification->update([
            'code' => (string) random_int(100000, 999999),
            'sent_at' => now(),
        ]);

        Mail::to($verification->investment->user->email)
            ->send(new InvestmentWithdrawalCodeMail($verification->investment, $verification));

        return response()->json(['status' => 'success', 'message' => 'Code resent.']);
    }

    /**
     * DELETE /api/admin/investment-verifications/{verificationId}
     *
     * Cancels a verification requirement — e.g. it was created by
     * mistake, or is no longer needed. If this was the last unconfirmed
     * one, the investment becomes withdrawable again immediately.
     */
    public function destroy($verificationId)
    {
        $verification = InvestmentWithdrawalVerification::findOrFail($verificationId);
        $verification->delete();

        return response()->json(['status' => 'success', 'message' => 'Verification removed.']);
    }
}