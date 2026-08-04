<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserKyc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class KycController extends Controller
{
    /**
     * POST /api/kyc/face-verify
     */
    public function verify(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'id_type' => ['required', 'string', 'in:passport,driver_license,national_id,others'],
            'id_document_front' => ['required', 'image', 'mimes:jpeg,png,jpg,pdf', 'max:5120'],
            'id_document_back' => ['nullable', 'image', 'mimes:jpeg,png,jpg,pdf', 'max:5120'],
            'selfie' => ['required', 'image', 'mimes:jpeg,png,jpg', 'max:5120'],
        ]);

        $folder = "kyc/{$user->id}";

        // Store files
        $frontPath = $request->file('id_document_front')->store($folder, 'public');
        $backPath = $request->hasFile('id_document_back')
            ? $request->file('id_document_back')->store($folder, 'public')
            : null;
        $selfiePath = $request->file('selfie')->store($folder, 'public');

        // Create or update KYC record
        $kyc = UserKYC::updateOrCreate(
            ['user_id' => $user->id],
            [
                'id_type' => $request->id_type,
                'id_document_front' => $frontPath,
                'id_document_back' => $backPath,
                'selfie' => $selfiePath,
                'status' => 'pending',
                'face_match_score' => null,
                'rejection_reason' => null,
                'verified_at' => null,
            ]
        );

        // Public URLs
        $frontUrl = asset('storage/' . $frontPath);
        $backUrl = $backPath ? asset('storage/' . $backPath) : null;
        $selfieUrl = asset('storage/' . $selfiePath);

        // Call Face++ API
        $confidence = null;
        $finalStatus = 'pending';

        try {
            $response = Http::asForm()->post(config('services.facepp.base_url'), [
                'api_key' => config('services.facepp.key'),
                'api_secret' => config('services.facepp.secret'),
                'image_url1' => $selfieUrl,
                'image_url2' => $frontUrl, // image_url2
                'return_attributes' => 'none',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $confidence = $data['confidence'] ?? null;
                $threshold = config('services.facepp.match_threshold', 80);

                if ($confidence !== null && $confidence >= $threshold) {
                    $finalStatus = 'verified';
                    $kyc->update([
                        'status' => 'verified',
                        'face_match_score' => $confidence,
                        'verified_at' => now(),
                    ]);
                } else {
                    $finalStatus = 'rejected';
                    $kyc->update([
                        'status' => 'rejected',
                        'face_match_score' => $confidence,
                        'rejection_reason' => 'Face match score too low',
                    ]);
                }
            }
            // If API fails → leave as pending (admin will review manually later)
        } catch (\Exception $e) {
            \Log::warning("Face++ API failed for user {$user->id}: " . $e->getMessage());
            // Do nothing — status stays 'pending'
        }

        // Always return fresh KYC record
        $kyc->refresh();

        return response()->json([
            'message' => $finalStatus === 'verified'
                ? 'KYC verified automatically!'
                : ($finalStatus === 'rejected'
                    ? 'Face verification failed'
                    : 'KYC submitted and pending review'),
            'status' => $finalStatus,
            'score' => $confidence,
            'kyc' => $kyc,
        ]);
    }

    /**
     * GET /api/kyc/status
     */
    public function status(Request $request)
    {
        $user = Auth::user();

        $kyc = UserKYC::where('user_id', $user->id)->first();

        if (!$kyc) {
            return response()->json([
                'status' => 'not_submitted',
                'face_match_score' => null,
                'rejection_reason' => null,
                'verified_at' => null,
                'kyc' => null,
            ]);
        }

        return response()->json([
            'status' => $kyc->status, // pending | verified | rejected
            'face_match_score' => $kyc->face_match_score,
            'rejection_reason' => $kyc->rejection_reason,
            'verified_at' => $kyc->verified_at,
            'kyc' => $kyc,
        ]);
    }
}