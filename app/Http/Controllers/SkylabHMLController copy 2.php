<?php

namespace App\Http\Controllers;

use PDOException;
use Illuminate\Http\Request;
use App\Models\SkylabUssdLog;
use App\Models\SkylabLogBilling;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\SkylabUserSessionBalance;

class SkylabHMLController extends Controller
{

    public const START = 111;
    public const CONTINUE = 112;
    public const END = 113;

    public const NETWORK = [
        20 => 'airtel',
        621 => 'mtn',
        60 => 'etisalat',
    ];

    /**
     *
     * @return void
     */
    public function __construct() {}


    public function skylabEngine(Request $request)
    {

        // Log the request
        $input = $request->all();
        // $this->log_request($request->all());
        Log::channel('skylab')->info('Incoming Request: ' . json_encode($input));
    }
}
