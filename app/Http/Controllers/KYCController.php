<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserKyc;

class KYCController extends Controller
{
    public function list()
    {
        return response()->json(UserKyc::with('user')->get());
    }

    public function approve($id)
    {
        $kyc = UserKyc::findOrFail($id);
        $kyc->status = 'approved';
        $kyc->save();
        return response()->json($kyc);
    }

    public function reject($id)
    {
        $kyc = UserKyc::findOrFail($id);
        $kyc->status = 'rejected';
        $kyc->save();
        return response()->json($kyc);
    }
}