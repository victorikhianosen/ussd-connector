<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Http;

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

    public function __construct() {}

    public function skylabEngine(Request $request)
    {
        Log::info('Incoming USSD Request:', $request->all());

        try {
            $messageType = strpos($request->input, "7261") !== false ? 0 : 1;
            $mcc = 20;
            $sessionid = (string) $request->session_id;
            $msisdn = (string) $request->phone;
            $shortCode = (string) $request->shortcode;
            $telco = self::NETWORK[$mcc];

            $service_code_key = $msisdn . ':service_code';
            $session_id_key = $msisdn . ':session_id';

            // Check if session exists in Redis
            if (app('redis')->get($session_id_key) !== $sessionid) {
                // If it's a fresh session, set the initial session and service code
                app('redis')->setex($session_id_key, 60, $sessionid);
                app('redis')->setex($service_code_key, 60, $shortCode);

                $parameters = [
                    'msisdn' => $msisdn,
                    'content' => $shortCode,  // First request should send shortcode
                    'commandID' => self::START,
                    'src' => $telco,
                    'serviceCode' => $shortCode,
                ];

                Log::info('USSD Call Parameters:', $parameters);

                $response = $this->fireUssd(json_encode($parameters));
                Log::info('USSD Response:', ['response' => $response]);

                return response()->json($response, 200);
            } else {
                // If session already exists, continue with user input
                $command = self::CONTINUE;

                $parameters = [
                    'msisdn' => $msisdn,
                    'content' => $request->input,  // Use actual user input
                    'commandID' => $command,
                    'src' => $telco,
                    'serviceCode' => app('redis')->get($service_code_key) ?? $shortCode,  // Correct service code retrieval
                ];

                Log::info('USSD Continue Session Parameters:', $parameters);

                $response = $this->fireUssd(json_encode($parameters));
                Log::info('USSD Response:', ['response' => $response]);

                return response()->json($response, 200);
            }
        } catch (\Throwable $th) {
            Log::error('Error Handling USSD Request:', ['error' => $th->getMessage()]);
            return response()->json([
                'msgType' => 2,
                'msisdn' => $request->phone ?? 'unknown',
                'ussdString' => 'Application Error [3009]',
            ], 200);
        }
    }

    public function fireUssd($data)
    {
        $url = 'http://localhost/ussd/send';

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->post($url, json_decode($data, true));

            if ($response->failed()) {
                Log::error('USSD API Error:', ['response' => $response->body()]);
                return [
                    'continue' => false,
                    'message' => 'Application Unable to handle request [3009]',
                    'info' => ''
                ];
            }

            $result = $response->json();

            if ($result && isset($result['reply'])) {
                return [
                    'continue' => strtolower($result['action'] ?? '') !== 'end', // true if session continues
                    'message' => $result['reply'],
                    'info' => ''
                ];
            } else {
                return [
                    'continue' => false,
                    'message' => 'Service unavailable. [1009]',
                    'info' => ''
                ];
            }
        } catch (\Exception $e) {
            Log::error('USSD API Exception:', ['error' => $e->getMessage()]);
            return [
                'continue' => false,
                'message' => 'Application Unable to handle request [3009]',
                'info' => ''
            ];
        }
    }
}
