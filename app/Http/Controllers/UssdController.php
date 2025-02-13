<?php

namespace App\Http\Controllers;

use App\Models\MiConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

define('SESSION_TIMEOUT', 60);

class UssdController extends Controller
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

    public function engine(Request $request)
    {

        // return 'Hello';

        try {
            // log this
            $input = file_get_contents('php://input');
            // var_dump($input);
            // exit;
            $this->log_request($input);
            $data = json_decode($request->getContent());
            $data = (object) json_decode($input);
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
            //     $iyke = new UssdIykejController()
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
                    $parsed_content = '*' . str_replace('@', '(at)', $content) . '#';

                    $parameters = [
                        'msisdn' => $msisdn,
                        'content' => $parsed_content,
                        'commandID' => $command,
                        'src' => $telco,
                        'serviceCode' => '*' . $service_code,
                    ];

                    $fields = json_encode($parameters);
                    $response = response()->json($this->fireUssd($fields, $sessionid, $msisdn, $service_code), 200);
                    $this->updatelog($response, (string) $sessionid);
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
                    'content' => str_replace('@', '(at)', $content),
                    'commandID' => $command,
                    'src' => $telco,
                    'serviceCode' => '*' . $service_code,
                ];

                $fields = json_encode($parameters);
                $response = response()->json($this->fireUssd($fields, $sessionid, $msisdn, $service_code), 200);
                $this->updatelog($response, (string) $sessionid);
                // $this->log_request('i am at 2nd else'.strval($response));
                return $response;
            }
        } catch (\Throwable $th) {
            return $th->getMessage();
            // $this->log_request('i am at error' . strval($th->getMessage()));
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

    public function fireUssd($data, $session_id, $msisdn, $service_code)
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post('http://localhost/ussd/send', $data);

            if ($response->successful()) {
                $responseBody = $response->json();
                $reply = $responseBody['reply'] ?? 'No reply provided';
                $action = $responseBody['action'] ?? 'continue';

                $messageType = (strtolower($action) == 'end') ? 2 : 1;

                return [
                    'msgType' => $messageType,
                    'msisdn' => $msisdn,
                    'ussdString' => $reply,
                ];
            }

            $reply = 'Service unavailable.[1009]';
            return [
                'msgType' => 2,
                'msisdn' => $msisdn,
                'ussdString' => $reply,
            ];
        } catch (\Exception $e) {
            $reply = 'Application Unable to handle request [3009]';

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

    public function log_request($request)
    {
        file_put_contents('/var/www/cloud.novajii.com/ussd-connetor/public/log/ussd_log_' . date('j.n.Y') . '.log', $request . PHP_EOL, FILE_APPEND);
        //file_put_contents('./log/9mobile_log_'.date('j.n.Y').'.log', json_encode($request).PHP_EOL, FILE_APPEND);
    }

    public function request_log($session, $msisdn, $request, $response = null)
    {
        MiConnector::create([
            'msisdn' => $msisdn,
            'request' => $request,
            'response' => $response,
            'sessionid' => $session,
        ]);
    }


    public function updatelog($response, $session)
    {
        $log = MiConnector::where('sessionid', $session)->first();

        if ($log) {
            $log->response = $response;
            return $log->save();
        }

        return false; // Return false if no record is found
    }

    public function init_session($msisdn, $sessionid, $serviceCode, $sessionid_key, $serviceCode_key)
    {
        if (app('redis')->exists($sessionid_key)) {
            // not a new session
            return false;
        }

        app('redis')->del($serviceCode_key);
        app('redis')->set($serviceCode_key, value: $serviceCode);
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

    public function da()
    {
        if (app('redis')->flushdb()) {
            return response("['code' => 0, 'message' => 'Successful']");
        } else {
            return response("['code' => 1, 'message' => 'Failed']");
        }
    }
}
