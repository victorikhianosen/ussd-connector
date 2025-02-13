<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class Bet1x9mobileController extends Controller
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
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct() {}

    /**
     * Store USSD contoller entrypoint.
     *
     * @return Response
     */
    public function engine(Request $request)
    {
        try {
            // log this
            $input = file_get_contents('php://input');
            $this->log_request($request->all());
            //$data = json_decode($request->getContent());
            $data = (object) $request->all();
            $messageType = (string) $data->msgType;
            $mcc = $data->mcc;
            $sessionid = (string) $data->sessionId;
            $msisdn = (string) $data->msisdn;
            $content = (string) $data->ussdString;
            $shortCode = (string) $data->serviceCode;
            $service_code_key = $msisdn . ':service_code';
            $session_id_key = $msisdn . ':sessionid';
            $telco = self::NETWORK[$mcc];
            // if(isset($data->operator)){
            //     $iyke = new UssdIykejController();
            //     return $iyke->engine($request);
            // }
            // $this->request_log($data->sessionId, $data->msisdn, $request->getContent());
            // try {
            // var_dump(app('redis')->get($session_id_key)); exit;
            if (!app('redis')->exists($session_id_key)) {
                if ($messageType == 0) {
                    $this->init_session($msisdn, $sessionid, $shortCode, $session_id_key, $service_code_key);
                    $service_code = $shortCode;
                    $command = self::START;
                    // 23 dont add * and # the USSD services breaks down
                    // $parsed_content = '*' . str_replace('@', '(at)', $content) . '#';
                    $parameters = [
                        'msisdn' => $msisdn,
                        'content' => $content,
                        'commandID' => $command,
                        'src' => $telco,
                        'serviceCode' => '*' . $service_code,
                    ];

                    $fields = json_encode($parameters);
                    $response = response()->json($this->fireUssd($fields, $sessionid, $msisdn, $service_code), 200);
                    $this->updatelog($response, (string) $sessionid);
                    // $this->log_request('i am at redis'.strval($response));

                    $this->log_request('Response logged: ' . json_encode($response));
                    return $response;
                } else {
                    $reply = 'Session Timout. Kindly dial again [4009]';
                    $ussd_response = [
                        'msgType' => 2,
                        'msisdn' => $msisdn,
                        'ussdString' => $reply,
                    ];

                    $response = response()->json($ussd_response, 200);
                    $this->updatelog($response, (string) $sessionid);
                    // $this->log_request('i am at 1st else'.strval($response));
                    $this->log_request('Response logged: ' . json_encode($response));
                    return $response;
                }
            } else {
                $command = self::CONTINUE;
                if ('*' . app('redis')->get($service_code_key) == str_replace('@', '(at)', $content)) {
                    $command = self::START;
                }
                $service_code = app('redis')->get($service_code_key);

                $parameters = [
                    'msisdn' => $msisdn,
                    'content' => $content,
                    'commandID' => $command,
                    'src' => $telco,
                    'serviceCode' => '*' . $service_code,
                ];

                $fields = json_encode($parameters);
                $response = response()->json($this->fireUssd($fields, $sessionid, $msisdn, $service_code), 200);
                $this->updatelog($response, (string) $sessionid);
                // $this->log_request('i am at 2nd else'.strval($response));
                $this->log_request('Response logged: ' . json_encode($response));
                return $response;
            }
        } catch (\Throwable $th) {
            $this->log_request('i am at error' . strval($th->getMessage()));
        }
        // } catch (\Exception $e) {

        //     $reply = "Application Unable to handle request [3009]";
        //     $ussd_response = [

        //         'msgType' => 2,
        //         'msisdn' => $msisdn,
        //         'ussdString' => $reply,

        //     ];

        //     return response()->json($ussd_response, 200);
        // }
    }

    // this code calls the ussd code in /var/www/html/ussd


    public function fireUssd($data, $session_id, $msisdn, $service_code)
    {
        try {
            $response = Http::withHeaders([
                'Content-type' => 'application/json',
            ])->post('http://localhost/ussd/send', $data);

            if ($response->failed()) {
                $reply = 'Application Unable to handle request [3009]';

                return [
                    'msgType' => 2,
                    'msisdn' => $msisdn,
                    'ussdString' => $reply,
                ];
            }

            $responseData = $response->json();

            if ($responseData) {
                $reply = $responseData['reply'];
                $action = $responseData['action'];

                // Determine whether to End or Continue session
                $messageType = (strtolower($action) == 'end') ? 2 : 1;

                return [
                    'msgType' => $messageType,
                    'msisdn' => $msisdn,
                    'ussdString' => $reply,
                ];
            } else {
                $reply = 'Service unavailable.[1009]';

                return [
                    'msgType' => 2,
                    'msisdn' => $msisdn,
                    'ussdString' => $reply,
                ];
            }
        } catch (\Exception $e) {
            // Log error and return a fallback message
            Log::error('USSD Fire Error: ' . $e->getMessage());

            return [
                'msgType' => 2,
                'msisdn' => $msisdn,
                'ussdString' => 'Application Unable to handle request [3009]',
            ];
        }
    }

    public function get_service_code($user_input)
    {
        return substr($user_input, 0, 3);
    }



    // public function request_log($session, $msisdn, $request, $response = null)
    // {
    //     $conn = $this->pdoConn();
    //     $sql = 'INSERT INTO mi_connector(msisdn, request, response, sessionid) VALUES(?,?,?,?)';
    //     $stmt = $conn->prepare($sql);
    //     $stmt->execute([$msisdn, $request, $response, $session]);
    // }

    // public function updatelog($response, $session)
    // {
    //     $conn = $this->pdoConn();
    //     $stmt = $conn->prepare('UPDATE mi_connector SET response = ? WHERE sessionid = ?');

    //     return $stmt->execute([$response, $session]);
    // }

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

    // public function pdoConn()
    // {
    //     $dsn = 'mysql:host=localhost;dbname=novaji_introserve';
    //     $dbuser = 'root';
    //     $dbpass = 'Amore123_';
    //     try {
    //         // $conn = new PDO("mysql:host=152.228.212.181;dbname=novaji_introserve", "novaji_introserve", "Zh7mWr4i0A98L1mX");
    //         $conn = new PDO($dsn, $dbuser, $dbpass);
    //         // set the PDO error mode to exception
    //         $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    //         $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    //         $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    //         return $conn;
    //     } catch (PDOException $e) {
    //         return 'Connection failed: ' . $e->getMessage();
    //     }
    // }

    public function d()
    {
        if (app('redis')->flushdb()) {
            return response("['code' => 0, 'message' => 'Successful']");
        } else {
            return response("['code' => 1, 'message' => 'Failed']");
        }
    }
    public function ninaEngine(Request $request)
    {

        // return 'Welcome To 1xBet';
        return $data_body;
    }

    public function log_request($request)
    {
        file_put_contents('./log/9mobile_log_' . date("j.n.Y") . '.log', json_encode($request) . PHP_EOL, FILE_APPEND);
    }



    public function ussdRequest(Request $request)
    {


        $this->log_request(json_encode($request->all()));
        $data_body = [
            'status' => true,
            "message" => 'Welcome To 1xBet',
            "userresponse" => true
        ];
        // return $data_body;
        $newRequestData = [
            "mcc" => 20,
            "msgType" => strpos($request->text, "6069") != false ? 0 : 1,
            "sessionId" => $request->Sessioncb,
            "msisdn" => $request->msisdn,
            "ussdString" => $request->text == "*6069" ? "6069" : $request->text,
            "serviceCode" => $request->shortcode
        ];

        $request = new Request();
        $request_Data = $request->merge($newRequestData);

        // $messageType = (string) $data->msgType;
        // $mcc = $data->mcc;
        // $sessionid = (string) $data->sessionId;
        // $msisdn = (string) $data->msisdn;
        // $content = (string) $data->ussdString;
        // $shortCode = (string) $data->serviceCode;
        // $service_code_key = $msisdn . ':service_code';
        // $session_id_key = $msisdn . ':sessionid';
        // $telco = self::NETWORK[$mcc];

        // call ussd controller functions
        // $ussdController = new UssdController();

        $reponse = $this->engine($request_Data);
        // decode the ussd response from our end
        $responseDecoded = json_decode($reponse->getContent(), true);
        $this->log_request($responseDecoded);
        // this is to ensure the ussd response contains the ussdString key before sending the
        // response to the user
        if (array_key_exists('ussdString', $responseDecoded)) {
            $ussdString = $responseDecoded['ussdString'];
        } else {
            // return this response incase of an error
            $ussdString = 'Kindly try again later, the service is unavailable';
        }
        if (array_key_exists('msgType', $responseDecoded)) {
            $msgType = $responseDecoded['msgType'];
        } else {
            // return this response incase of an error
            $msgType = 2;
        }
        // array to format messsage type
        $msgTypeArray = [
            0 => true,
            1 => true,
            2 => false
        ];

        $data_body = [
            'status' => true,
            "message" => $ussdString,
            // "userresponse" => $msgTypeArray[$msgType]
            "userresponse" => 'Testing the application'
        ];
        // return 'Welcome To 1xBet';
        return $data_body;
    }
}
