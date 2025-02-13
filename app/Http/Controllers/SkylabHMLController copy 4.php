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



    public function Logs($data)
    {
        Log::channel('skylab')->info(json_encode($data));
    }

    public function skylabEngine(Request $request)
    {
        $this->Logs($request->all());

        $newRequestData = [
            "mcc" => 20,
            "msgType" => strpos($request->input, "7261") !== false ? 0 : 1,
            "sessionId" => $request->session_id,
            "msisdn" => $request->phone,
            "ussdString" => $request->input,
            "serviceCode" => $request->shortcode
        ];
        Log::info("New Request Created:", $newRequestData);

        $newRequest = Request::create('/', 'POST', $newRequestData);
        Log::info("New Request Created:", $newRequest->all());

        // Call the engine with the new request
        $response = $this->engine($newRequest);

        // Log response before decoding
        // Log::info("Engine Response:", ['response' => $response]);

        if (!$response) {
            // Log::error("Engine returned null response!");
            return [
                'continue' => false,
                "message" => 'Kindly try again later, the service is unavailable'
            ];
        }

        $responseDecoded = json_decode($response->getContent(), true);
        // Log::info("Decoded Response:", $responseDecoded);

        $ussdString = $responseDecoded['ussdString'] ?? 'Kindly try again later, the service is unavailable';
        $msgType = $responseDecoded['msgType'] ?? 2;

        $msgTypeArray = [
            0 => true,
            1 => true,
            2 => false
        ];

        return [
            'continue' => $msgTypeArray[$msgType],
            "message" => $ussdString,
        ];
    }


    public function engine(Request $request)
    {
        try {
            // Log the request
            $input = file_get_contents('php://input');
            $data = (object) $request->all();
            $messageType = (string) $data->msgType;
            $mcc = $data->mcc;
            $sessionid = (string) $data->sessionId;
            $msisdn = (string) $data->msisdn;
            $content = (string) $data->ussdString;
            $shortCode = (string) $data->serviceCode;

            // Additional keys for Redis
            $service_code_key = $msisdn . ':service_code';
            $session_id_key = $msisdn . ':sessionid';
            $this->Logs($data);


            // Determine telco from MCC
            $telco = self::NETWORK[$mcc];


            if ($messageType == 0) {
                $this->init_session($msisdn, $sessionid, $shortCode, $session_id_key, $service_code_key);
                $service_code = $shortCode;
                $command = self::START;
                $parameters = [
                    'msisdn' => $msisdn,
                    'content' => "*" . $content . "#",
                    'commandID' => $command,
                    'src' => $telco,
                    'serviceCode' => '*' . $service_code,
                ];

                $fields = json_encode($parameters);
                $response = response()->json($this->fireUssd($fields, $sessionid, $msisdn, $service_code), 200);
                $this->Logs('Response logged 1: ' . json_encode($response));

                $billingRequest = new Request();
                $billingRequest->merge([
                    'msisdn' => $msisdn,
                    'session_id' => $sessionid,
                    'serviceCode' => $service_code
                ]);

                $initiateBillingRes = $this->initiateBilling($billingRequest);

                // Log the billing call
                $this->Logs("Billing API Initiated: " . json_encode($initiateBillingRes));

                return $response;
            } else {
                // Handling continued session
                $command = self::CONTINUE;

                if ('*' . app('redis')->get($service_code_key) == str_replace('@', '(at)', $content)) {
                    $command = self::START;
                }

                $service_code = app('redis')->get($service_code_key);
                $parameters = [
                    'msisdn' => $msisdn,
                    'content' => "*" . $content . "#",
                    'commandID' => $command,
                    'src' => $telco,
                    'serviceCode' => '*' . $service_code,
                ];

                $fields = json_encode($parameters);
                $response = response()->json($this->fireUssd($fields, $sessionid, $msisdn, $service_code), 200);
                return $response;
            }
        } catch (\Throwable $th) {
            $this->Logs('Error: ' . strval($th->getMessage()));
        }
    }
    public function fireUssd($data, $session_id, $msisdn, $service_code)
    {
        try {
            // Make the HTTP request
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post('http://localhost/ussd/send', $data);


            Log::info("Request Dataddd:", (array) $data);
            if (is_null($response)) {
                Log::error("Received null response from the USSD service.");
                return [
                    'msgType' => 2,
                    'msisdn' => $msisdn,
                    'ussdString' => 'Service unavailable due to an error.[1001]',
                ];
            }

            // Log response as a string (use body for logging to avoid issues)
            Log::info('USSD Service Response: ' . $response->body());

            // Check if the response is successful
            if ($response->failed()) {
                // Log the error details
                Log::error("HTTP request failed: ", ['status' => $response->status(), 'response' => $response->body()]);
                return [
                    'msgType' => 2,
                    'msisdn' => $msisdn,
                    'ussdString' => 'Application Unable to handle request [3009]',
                ];
            }

            // Check if the response is successful and contains valid body content
            if ($response->successful()) {
                // Get response body as JSON
                $responseBody = $response->json();

                // Check if response contains necessary keys
                if (isset($responseBody['reply'], $responseBody['action'])) {
                    $reply = $responseBody['reply'];
                    $action = $responseBody['action'];

                    // Determine the message type based on the action
                    $messageType = (strtolower($action) === 'end') ? 2 : 1;

                    return [
                        'msgType' => $messageType,
                        'msisdn' => $msisdn,
                        'ussdString' => $reply,
                    ];
                }
            }

            // If no valid response, return a default message
            Log::warning("Invalid or missing reply/action in response.");
            return [
                'msgType' => 2,
                'msisdn' => $msisdn,
                'ussdString' => 'Service unavailable.[1009]',
            ];
        } catch (\Exception $e) {
            // Log the exception for better debugging
            Log::error("Error with USSD service request: " . $e->getMessage());

            // Return a fallback error message
            return [
                'msgType' => 2,
                'msisdn' => $msisdn,
                'ussdString' => 'Service unavailable due to an error.[1010]',
            ];
        }
    }


    public function init_session($msisdn, $sessionid, $serviceCode, $sessionid_key, $serviceCode_key)
    {
        if (app('redis')->exists($sessionid_key)) {
            // not a new session
            return false;
        }

        app('redis')->del($serviceCode_key);
        app('redis')->set($serviceCode_key, $serviceCode);
        app('redis')->expire($serviceCode_key, SESSION_TIMEOUT);
        app('redis')->set($sessionid_key, $sessionid);
        app('redis')->expire($sessionid_key, SESSION_TIMEOUT);

        return true;
    }

    public function initiateBilling($billingRequest)
    {
        $msisdn = $billingRequest->msisdn;
        $msisdn = '0' . substr($msisdn, 3);

        $url = 'http://91.109.117.92:8001/api/v1/product/subscription/initiate';

        $data = [
            'product_id' => '315',
            'phone' => $msisdn,
            'telco' => 'MTN',
            'channel' => 'USSD',
        ];

        $start_time = microtime(true); // Start time

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer SkzvHsZQio500agIqRl9',
            ])->post($url, $data);

            $end_time = microtime(true); // End time
            $execution_time = $end_time - $start_time; // Execution time

            // Log request and execution time (optional)
            // \Log::info('Response from Billing API: ' . $response->body() . '. Response time: ' . $execution_time . ' seconds');

            return $response->body();
        } catch (\Exception $e) {
            // Log the error (optional)
            Log::error('Billing API Error: ' . $e->getMessage());

            return response()->json(['error' => 'Billing API request failed.'], 500);
        }
    }

    public function billingCallBack(Request $request)
    {

        // return 'Billing Callback reached';

        $data = $request->all();
        $this->Logs($data);

        if (empty($data)) {
            return response()->json(['error' => 'No data provided'], 400);
        }

        try {
            // Prepare the data for insertion
            $logData = [
                'type' => $data['type'],
                'telco' => $data['telco'],
                'action' => $data['action'],
                'shortcode' => $data['shortcode'],
                'product_id' => $data['product']['id'],
                'product_name' => $data['product']['name'],
                'product_identity' => $data['product']['identity'],
                'product_type' => $data['product']['type'],
                'product_subscription_type' => $data['product']['subscription_type'],
                'product_status' => $data['product']['status'],
                'details_phone' => $data['details']['phone'],
                'details_amount' => $data['details']['amount'],
                'details_channel' => $data['details']['channel'],
                'details_date' => $data['details']['date'],
                'details_expiry' => $data['details']['expiry'],
                'details_auto_renewal' => $data['details']['auto_renewal'],
                'details_telco_status_code' => $data['details']['telco_status_code'],
                'details_telco_ref' => $data['details']['telco_ref'],
            ];


            return response()->json(['message' => 'Log inserted successfully'], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }
}
