<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AIInvestment;
use Illuminate\Http\Request;

class AIInvestmentController extends Controller {
    public function store(Request $request) {
        $data = $request->validate([
            'pair'=>'required|string',
            'type'=>'required|in:market,limit,stop',
            'side'=>'required|in:buy,sell',
            'amount'=>'required|numeric',
            'duration_days'=>'required|integer|min:1|max:30',
        ]);

        $data['user_id'] = $request->user()->id;
        $data['expected_return'] = $data['amount'] * (1 + 0.025); // default projection

        $investment = AIInvestment::create($data);

        return response()->json(['success'=>true,'investment'=>$investment]);
    }
}