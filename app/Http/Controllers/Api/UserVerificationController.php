<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserVerificationEntry;
use App\Models\VerificationCode;
use Illuminate\Http\Request;

class UserVerificationController extends Controller
{
    // Get active verification steps
    public function getRequiredCodes()
    {
        $codes = VerificationCode::where('active', true)->get();

        return response()->json($codes);
    }

    // Submit one step at a time (stepper system)
    public function submitCodes(Request $request)
{
    $user = $request->user();

    $activeCodes = VerificationCode::where('active', true)->get();

    foreach ($activeCodes as $code) {

        // FRONTEND sends: value_3 , value_4, etc
        $field = "value_" . $code->id;

        if ($request->has($field)) {

            $value = trim($request->input($field));

            // 1. Required
            if ($value === "") {
                return response()->json([
                    "error" => $code->header . " is required"
                ], 400);
            }

            // 2. MUST MATCH DB CODE
            if ($value !== $code->code) {
                return response()->json([
                    "error" => "Incorrect code for: " . $code->header
                ], 400);
            }

            // 3. Save only if correct
            UserVerificationEntry::updateOrCreate(
                [
                    "user_id" => $user->id,
                    "verification_code_id" => $code->id
                ],
                [
                    "value" => $value
                ]
            );
        }
    }

    return response()->json([
        "success" => true,
        "message" => "Verification step saved"
    ]);
}

    // Return user’s previous answers
    public function getMyCodes(Request $request)
    {
        $user = $request->user();

        $entries = UserVerificationEntry::with("verificationCode")
            ->where("user_id", $user->id)
            ->get();

        return response()->json($entries);
    }
}