<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Workers\HubspotWorker;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthorisationController extends Controller
{
    public function hubspotCallback(Request $request)
    {
        Log::info("AuthorisationController.hubspotCallback - HubSpot Authorisation starting to process.");
        try {

            $token = HubSpotWorker::getHubSpotToken($request->code);
            $access_token = $token->access_token;
            $refresh_token = $token->refresh_token;

            $hubspot_worker = new HubspotWorker($access_token);
            $authenticated_user = $hubspot_worker->generateHubSpotRequest($hubspot_worker->generateGetAccessToken());
            if ($authenticated_user) {
                $user = User::where([
                    ['hubspot_user_id', $authenticated_user['user_id']],
                    ['hubspot_account_id', $authenticated_user['hub_id']]
                ])->first();

                if ($user) { 
                    $user->update([
                        'hubspot_refresh_token' => $refresh_token,
                        'hubspot_access_token' => $access_token,
                        'hubspot_access_token_expires_in' => time() + ($token->expires_in * 0.95),
                        'hubspot_state' => "CONNECTED" 
                    ]);
                } else {

                    $new_user = User::create([
                        'hubspot_account_id' => $authenticated_user['hub_id'],
                        'hubspot_user_id' => $authenticated_user['user_id'],
                        'hubspot_refresh_token' => $refresh_token,
                        'hubspot_access_token' => $access_token,
                        'hubspot_access_token_expires_in' => time() + ($token->expires_in * 0.95),
                        'hubspot_state' => "CONNECTED",
                    ]);

                    if ($new_user) {
                        $request->session()->put('user', $new_user);
                        $request->session()->put('user_in_session', $authenticated_user['user']);
                        Log::info("AuthorisationController.hubspotCallback - HubSpot Authorisation successfully done!");
                        return response()->json("HubSpot Authorisation successfully done!");
                    }
                }
            }

            return response()->json("User already authorised");
            
        } catch (Exception $e) {
            Log::error("AuthorisationController.hubspotCallback - {$e->getMessage()}");
            throw new Exception($e->getMessage());
        }
    }
}
