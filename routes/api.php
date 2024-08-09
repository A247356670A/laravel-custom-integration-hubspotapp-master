<?php
use App\Http\Controllers\AuthorisationController;
use App\Http\Controllers\HubspotController;
use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::group(['prefix' => 'webhooks'], function () {
    Route::post('/hubspot', [WebhookController::class, 'handleHubspotWebhook'])->middleware('hubspot-webhook');
});
// Route::group(['prefix' => 'webhooks'], function () {
//     Route::post('/hubspot', [WebhookController::class, 'handleHubspotWebhook']);
// });
