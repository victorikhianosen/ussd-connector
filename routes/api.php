<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UssdController;
use App\Http\Controllers\SkylabHMLController;
use App\Http\Controllers\Bet1x9mobileController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::post('ussd-connector', [UssdController::class, 'engine']);
Route::prefix('skylab')->group(function() {
    Route::post('connector', [SkylabHMLController::class, 'skylabEngine']);
    Route::post('initiate-billing',[SkylabHMLController::class, 'initiateBilling']);
    Route::post('billing-callback', [SkylabHMLController::class, 'billingCallBack']);    
});


// Route::post('skylab-connector', function() {
//     return 'Viftor';
// });

Route::post('bet1x9mobile-connector', [Bet1x9mobileController::class, 'ninaEngine']);


// $router->post('ussd-connector', "UssdController@engine");
// $router->post('skylab-connector', "SkylabHMLController@skylabEngine");
// $router->post('skylab-billing', "SkylabHMLController@skylabBilling");
// // $router->post('skylab-callbilling', "SkylabHMLController@callInitiateBilling");
// $router->post('skylab-status', "SkylabHMLController@skylabStatus");
// $router->post('uptime', "NotificationController@uptime");
// // The ussdRequest-connector is the endpoint for 1xbet 9mobile connector with Ninajoger
// // $router->post('bet1x9mobile-connector', "Bet1x9mobileController@ninaEngine");
// $router->post('ussdRequest-connector', "Bet1x9mobileController@ussdRequest");
// $router->get('/matches', 'MatchController@getMatches');