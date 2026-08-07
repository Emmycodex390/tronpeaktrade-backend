<?php

namespace App\Http\Controllers;

use App\Mail\StakeWithdrawalCodeMail;
use App\Models\UserStake;
use App\Models\StakeWithdrawalVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Admin-side management for matured-stake withdrawal verifications.
 * Mirrors InvestmentWithdrawalController exactly — see that file for
 * the full reasoning on why this is a separate table/controller rather
 * than a shared polymorphic one.
 */
class StakeWithdrawalController extends Controller
{
    /**
     * GET /api/admin/stakes/matured
     */
    public function matured()
    {
        $stakes = UserStake::with(['user:id,name,email', 'withdrawalVerifications' => function ($q) {
            $q->orderBy('created_at', 'desc');
        }])
            ->where('status', 'active')
            ->get()
            ->filter(function ($stake) {
                return $stake->ends_at && now()->greaterThanOrEqualTo($stake->ends_at);
            })
            ->map(function ($stake) {
                return [
                    'id' => $stake->id,
                    'user_name' => $stake->user->name ?? 'Unknown',
                    'user_email' => $stake->user->email ?? null,
                    'coin' => $stake->coin,
                    'amount' => $stake->amount,
                    'verifications' => $stake->withdrawalVerifications->map(fn ($v) => [
                        'id' => $v->id,
                        'label' => $v->label,
                        'sent_at' => $v->sent_at,
                        'verified_at' => $v->verified_at,
                    ]),
                ];
            })
            ->values();

        return response()->json(['data' => $stakes]);
    }

    /**
     * POST /api/admin/stakes/{id}/verifications
     */
    public function store(Request $request, $id)
    {
        $request->validate([
            'label' => 'nullable|string|max:120',
        ]);

        $stake = UserStake::with('user')->findOrFail($id);

        if (!$stake->user || !$stake->user->email) {
            return response()->json(['error' => 'This user has no email on file to send a code to.'], 422);
        }

        $verification = StakeWithdrawalVerification::create([
            'user_stake_id' => $stake->id,
            'created_by' => Auth::id(),
            'label' => $request->label,
            'code' => (string) random_int(100000, 999999),
            'sent_at' => now(),
        ]);

        Mail::to($stake->user->email)->send(new StakeWithdrawalCodeMail($stake, $verification));

        return response()->json([
            'status' => 'success',
            'message' => "Code sent to {$stake->user->email}.",
        ]);
    }

    /**
     * POST /api/admin/stake-verifications/{verificationId}/resend
     */
    public function resend($verificationId)
    {
        $verification = StakeWithdrawalVerification::with('stake.user')->findOrFail($verificationId);

        if ($verification->verified_at) {
            return response()->json(['error' => 'This verification is already confirmed.'], 422);
        }

        if (!$verification->stake->user || !$verification->stake->user->email) {
            return response()->json(['error' => 'This user has no email on file to send a code to.'], 422);
        }

        $verification->update([
            'code' => (string) random_int(100000, 999999),
            'sent_at' => now(),
        ]);

        Mail::to($verification->stake->user->email)
            ->send(new StakeWithdrawalCodeMail($verification->stake, $verification));

        return response()->json(['status' => 'success', 'message' => 'Code resent.']);
    }

    /**
     * DELETE /api/admin/stake-verifications/{verificationId}
     */
    public function destroy($verificationId)
    {
        $verification = StakeWithdrawalVerification::findOrFail($verificationId);
        $verification->delete();

        return response()->json(['status' => 'success', 'message' => 'Verification removed.']);
    }
}
