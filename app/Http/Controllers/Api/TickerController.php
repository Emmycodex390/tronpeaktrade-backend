<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticker;

class TickerController extends Controller {

    public function crypto($symbol) {
        return Ticker::where('symbol', $symbol)->where('type','crypto')->firstOrFail();
    }

    public function forex($symbol) {
        return Ticker::where('symbol', $symbol)->where('type','forex')->firstOrFail();
    }

    public function futures($symbol) {
        return Ticker::where('symbol', $symbol)->where('type','futures')->firstOrFail();
    }
}