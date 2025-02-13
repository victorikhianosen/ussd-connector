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


    public function engine(Request $request)
    {
        try {
            // Log the request
            $input = file_get_contents('php://input');
            // $this->log_request($request->all());

            // Parsing the request data
            return $data = (object) $request->all();
            $messageType = (string) $data->msgType;
            $mcc = $data->mcc;
            $sessionid = (string) $data->sessionId;
            $msisdn = (string) $data->msisdn;
            $content = (string) $data->ussdString;
            $shortCode = (string) $data->serviceCode;

            // Additional keys for Redis
            $service_code_key = $msisdn . ':service_code';
            $session_id_key = $msisdn . ':sessionid';
            $this->log_request($data);;


            // Determine telco from MCC
            $telco = self::NETWORK[$mcc];

            // Check if the session already exists in Redis
            // if (!app('redis')->exists($session_id_key)) {
            // Initial session handling
            // if ($messageType == 0) {
            if ($messageType == 0) {
                $this->init_session($msisdn, $sessionid, $shortCode, $session_id_key, $service_code_key);
                $service_code = $shortCode;
                $command = self::START;

                // Parameters for USSD initiation
                $parameters = [
                    'msisdn' => $msisdn,
                    'content' => "*" . $content . "#",
                    'commandID' => $command,
                    'src' => $telco,
                    'serviceCode' => '*' . $service_code,
                ];

                // Convert parameters to JSON
                $fields = json_encode($parameters);
                // Call USSD service
                $response = response()->json($this->fireUssd($fields, $sessionid, $msisdn, $service_code), 200);

                // Update log
                $this->updatelog($response, (string) $sessionid);
                $this->log_request('Response logged 1: ' . json_encode($response));

                // Initiate billing
                $billingRequest = new Request();  // Create a new request object
                // Populate billing request object with necessary data
                $billingRequest->merge([
                    'msisdn' => $msisdn,
                    'session_id' => $sessionid,
                    'serviceCode' => $service_code
                ]);

                $initiateBillingRes = $this->initiateBilling($billingRequest);

                // Log the billing call
                $this->log_request("Billing API Initiated: " . json_encode($initiateBillingRes));

                return $response;
            }
            // else {
            //         // Handle session timeout
            //         $reply = 'Session Timeout. Kindly dial again [5000]';
            //         $ussd_response = [
            //             'msgType' => 2,
            //             'msisdn' => $msisdn,
            //             'ussdString' => $reply,
            //         ];

            //         $response = response()->json($ussd_response, 200);
            //         $this->updatelog($response, (string) $sessionid);
            //         $this->log_request('Response logged 2: ' . json_encode($response));

            //         return $response;
            //     }
            // } 
            else {
                // Handling continued session
                $command = self::CONTINUE;

                if ('*' . app('redis')->get($service_code_key) == str_replace('@', '(at)', $content)) {
                    $command = self::START;
                }

                $service_code = app('redis')->get($service_code_key);

                // Parameters for continued USSD session
                $parameters = [
                    'msisdn' => $msisdn,
                    'content' => "*" . $content . "#",
                    'commandID' => $command,
                    'src' => $telco,
                    'serviceCode' => '*' . $service_code,
                ];

                // Convert parameters to JSON
                $fields = json_encode($parameters);
                // Call USSD service
                $response = response()->json($this->fireUssd($fields, $sessionid, $msisdn, $service_code), 200);

                // Update log
                $this->updatelog($response, (string) $sessionid);
                // $this->log_request('Response logged 3: ' . json_encode($response));

                return $response;
            }
        } catch (\Throwable $th) {
            $this->log_request('Error: ' . strval($th->getMessage()));
        }
    }



    // public function engine(Request $request)
    // {
    //     try {
    //         // Log the request
    //         $input = file_get_contents('php://input');
    //         $this->log_request($request->all());

    //         // Parsing the request data
    //         $data = (object) $request->all();
    //         $messageType = (string) $data->msgType;
    //         $mcc = $data->mcc;
    //         $sessionid = (string) $data->sessionId;
    //         $msisdn = (string) $data->msisdn;
    //         $content = (string) $data->ussdString;
    //         $shortCode = (string) $data->serviceCode;

    //         // Additional keys for Redis
    //         $service_code_key = $msisdn . ':service_code';
    //         $session_id_key = $msisdn . ':sessionid';

    //         // Determine telco from MCC
    //         $telco = self::NETWORK[$mcc];

    //         // Check if the session already exists in Redis
    //         if (!app('redis')->exists($session_id_key)) {
    //             // Initial session handling
    //             if ($messageType == 0) {
    //                 $this->init_session($msisdn, $sessionid, $shortCode, $session_id_key, $service_code_key);
    //                 $service_code = $shortCode;
    //                 $command = self::START;

    //                 // Parameters for USSD initiation
    //                 $parameters = [
    //                     'msisdn' => $msisdn,
    //                     'content' => "*" . $content . "#",
    //                     'commandID' => $command,
    //                     'src' => $telco,
    //                     'serviceCode' => '*' . $service_code,
    //                 ];

    //                 // Convert parameters to JSON
    //                 $fields = json_encode($parameters);
    //                 // Call USSD service
    //                 $response = response()->json($this->fireUssd($fields, $sessionid, $msisdn, $service_code), 200);

    //                 // Update log
    //                 $this->updatelog($response, (string) $sessionid);
    //                 $this->log_request('Response logged 1: ' . json_encode($response));

    //                 // Initiate billing
    //                 $billingRequest = new Request();  // Create a new request object
    //                 // Populate billing request object with necessary data
    //                 $billingRequest->merge([
    //                     'msisdn' => $msisdn,
    //                     'session_id' => $sessionid,
    //                     'serviceCode' => $service_code
    //                 ]);

    //                 $initiateBillingRes = $this->initiateBilling($billingRequest);

    //                 // Log the billing call
    //                 $this->log_request("Billing API Initiated: " . json_encode($initiateBillingRes));

    //                 return $response;
    //             } else {
    //                 // Handle session timeout
    //                 $reply = 'Session Timeout. Kindly dial again [5000]';
    //                 $ussd_response = [
    //                     'msgType' => 2,
    //                     'msisdn' => $msisdn,
    //                     'ussdString' => $reply,
    //                 ];

    //                 $response = response()->json($ussd_response, 200);
    //                 $this->updatelog($response, (string) $sessionid);
    //                 $this->log_request('Response logged 2: ' . json_encode($response));

    //                 return $response;
    //             }
    //         } else {
    //             // Handling continued session
    //             $command = self::CONTINUE;

    //             if ('*' . app('redis')->get($service_code_key) == str_replace('@', '(at)', $content)) {
    //                 $command = self::START;
    //             }

    //             $service_code = app('redis')->get($service_code_key);

    //             // Parameters for continued USSD session
    //             $parameters = [
    //                 'msisdn' => $msisdn,
    //                 'content' => "*" . $content . "#",
    //                 'commandID' => $command,
    //                 'src' => $telco,
    //                 'serviceCode' => '*' . $service_code,
    //             ];

    //             // Convert parameters to JSON
    //             $fields = json_encode($parameters);
    //             // Call USSD service
    //             $response = response()->json($this->fireUssd($fields, $sessionid, $msisdn, $service_code), 200);

    //             // Update log
    //             $this->updatelog($response, (string) $sessionid);
    //             // $this->log_request('Response logged 3: ' . json_encode($response));

    //             return $response;
    //         }
    //     } catch (\Throwable $th) {
    //         $this->log_request('Error: ' . strval($th->getMessage()));
    //     }
    // }



    public function fireUssd($data, $session_id, $msisdn, $service_code)
    {
        // Log the request data using Eloquent
        SkylabUssdLog::create([
            'session_id' => $session_id,
            'msisdn' => $msisdn,
            'service_code' => $service_code,
            'request_data' => json_encode($data),
        ]);

        // Make an HTTP POST request to the external USSD service
        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post('http://localhost/ussd/send', $data);

        // Check for errors or unsuccessful status code
        if ($response->failed()) {
            $reply = 'Application Unable to handle request [3009]';

            return [
                'msgType' => 2,
                'msisdn' => $msisdn,
                'ussdString' => $reply,
            ];
        }

        // If the response is successful
        $responseBody = $response->json();

        if ($responseBody) {
            $reply = $responseBody['reply'];
            $action = $responseBody['action'];

            // Determine whether to End or Continue session
            $messageType = (strtolower($action) == 'end') ? 2 : 1;

            return [
                'msgType' => $messageType,
                'msisdn' => $msisdn,
                'ussdString' => $reply,
            ];
        } else {
            // If there's no response or an invalid response
            $reply = 'Service unavailable.[1009]';

            return [
                'msgType' => 2,
                'msisdn' => $msisdn,
                'ussdString' => $reply,
            ];
        }
    }


    public function get_service_code($user_input)
    {
        return substr($user_input, 0, 3);
    }



    public function request_log($session, $msisdn, $request, $response = null)
    {
        $conn = $this->pdoConn();
        $sql = 'INSERT INTO mi_connector(msisdn, request, response, sessionid) VALUES(?,?,?,?)';
        $stmt = $conn->prepare($sql);
        $stmt->execute([$msisdn, $request, $response, $session]);
    }

    public function updatelog($response, $session)
    {
        $conn = $this->pdoConn();
        $stmt = $conn->prepare('UPDATE mi_connector SET response = ? WHERE sessionid = ?');

        return $stmt->execute([$response, $session]);
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

    public function pdoConn()
    {
        $dsn = 'mysql:host=localhost;dbname=skylab_ussd';
        $dbuser = 'skylab_ussd';
        $dbpass = 'skylab_ussd';
        try {
            // $conn = new PDO("mysql:host=152.228.212.181;dbname=novaji_introserve", "novaji_introserve", "Zh7mWr4i0A98L1mX");
            $conn = new PDO($dsn, $dbuser, $dbpass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            return $conn;
        } catch (PDOException $e) {
            return 'Connection failed: ' . $e->getMessage();
        }
    }

    public function d()
    {
        if (app('redis')->flushdb()) {
            return response("['code' => 0, 'message' => 'Successful']");
        } else {
            return response("['code' => 1, 'message' => 'Failed']");
        }
    }

    public function log_request($request)
    {
        file_put_contents('../log/skylab_log_' . date("j.n.Y") . '.log', json_encode($request) . PHP_EOL, FILE_APPEND);
    }


    public function skylabEngine(Request $request)
    {
        // return 'Hello';
        // return 'You are connected Successfully';
        $this->log_request($request->all());
        $data_body = [
            'continue' => true,
            "message" => 'Welcome To SkyLab',
            "info" => ''
        ];

        $newRequestData = [
            "mcc" => 20,
            "msgType" => strpos($request->input, "7261") !== false ? 0 : 1,
            // "sessionId" => $request->product_id,
            "sessionId" => $request->session_id,
            "msisdn" => $request->phone,
            "ussdString" => $request->input,
            "serviceCode" => $request->shortcode
        ];

        $newRequest = new Request(); // Create a new request object
        $newRequestData = $newRequest->merge($newRequestData); // Merge the new data into the new request object

        $response = $this->engine($newRequestData); // Call engine with the new request

        // decode the ussd response from our end
        $responseDecoded = json_decode($response->getContent(), true);
        // $this->log_request($responseDecoded);

        // this is to ensure the ussd response contains the ussdString key before sending the
        // response to the user
        if (array_key_exists('ussdString', $responseDecoded)) {
            $ussdString = $responseDecoded['ussdString'];
        } else {
            // return this response in case of an error
            $ussdString = 'Kindly try again later, the service is unavailable';
        }

        if (array_key_exists('msgType', $responseDecoded)) {
            $msgType = $responseDecoded['msgType'];
        } else {
            // return this response in case of an error
            $msgType = 2;
        }

        // array to format message type
        $msgTypeArray = [
            0 => true,
            1 => true,
            2 => false
        ];

        // Kindly don't delete this commented code. It's for Production
        $data_body = [
            'continue' => $msgTypeArray[$msgType],
            "message" => $ussdString,
            // "info" => $msgTypeArray[$msgType]
        ];

        // $data_body = [
        //     'continue' => true,
        //     "message" => 'Welcome To SkyLab',
        //     "info" => ''
        // ];

        return $data_body;
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



    public function skylabBilling(Request $request)
    {
        $data = $request->json()->all();

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

            // Insert log into the skylab_log_billing table
            SkylabLogBilling::create($logData);

            // Call incrementUserSessionBalance (replace with the actual function logic)
            $this->incrementUserSessionBalance($data);

            return response()->json(['message' => 'Log inserted successfully'], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }




    public function incrementUserSessionBalance($data)
    {
        // Check if the type is 'SYNC_NOTIFICATION'
        if ($data['type'] === 'SYNC_NOTIFICATION') {
            $msisdn = $data['details']['phone'];

            try {
                // Check if the msisdn exists in the database using Eloquent
                $session = SkylabUserSessionBalance::where('msisdn', $msisdn)->first();

                if ($session) {
                    // msisdn exists, update session_balance
                    $session->increment('session_balance');  // Increment the session balance by 1
                    return response()->json(['message' => 'Session balance incremented successfully']);
                } else {
                    // msisdn does not exist, insert a new record
                    SkylabUserSessionBalance::create([
                        'msisdn' => $msisdn,
                        'session_balance' => 1,  // Start with a balance of 1
                    ]);
                    return response()->json(['message' => 'New record created successfully']);
                }
            } catch (\Exception $e) {
                return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
            }
        } else {
            return response()->json(['message' => 'Type is not SYNC_NOTIFICATION']);
        }
    }
}
