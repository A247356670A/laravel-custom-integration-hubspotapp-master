<?php

namespace App\Workers;

use App\Models\User;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;
use SevenShores\Hubspot\Factory;
use SevenShores\Hubspot\Resources\CrmAssociations;

class HubspotWorker {
    protected $token;
    protected $hubspot_api_domain = "https://api.hubapi.com";

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    private function parseHubSpotResponse($input)
    {
    	if (!isset($input['errors'])) { return $input; }
    	$message = $input['errors'][0]['message'] ?? "Invalid Request";
        Log::error($input['message'] ?? $message);
        throw new Exception($input['message'] ?? $message);
    }

    public static function getHubSpotToken($code)
    {
        try {

            $client = new Client();
            $request = $client->request('POST', 'https://api.hubapi.com/oauth/v1/token', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => env('HUBSPOT_CLIENT_ID'),
                    'client_secret' => env('HUBSPOT_CLIENT_SECRET'),
                    'redirect_uri' => env('HUBSPOT_CALLBACK_URL'),
                    'code' => $code
                ]
            ]);

            $response = json_decode($request->getBody()->getContents());
            return $response;

        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

    }

    public function generateHubSpotRequest(array $data)
    {
        try {
            $response = (new Client())->request($data['method'], "{$this->hubspot_api_domain}/{$data['path']}", [
                'headers' => [
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                    'authorization' => "Bearer {$this->token}"
                ],
                'json' => $data['body']
            ]);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            Log::error("HubSpotWorker.generateHubSpotRequest - {$e->getMessage()}, response code: {$response->getStatusCode()}");
            throw new Exception($e->getMessage());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $this->parseHubSpotResponse(json_decode($response->getBody()->getContents(), true));
    }

    public function generateGetAccessToken()
    {
        return [
            'method' => 'GET',
            'path' => "oauth/v1/access-tokens/{$this->token}",
            'body' => []
        ];
    }

    public static function getRefreshToken($refresh_token)
    {
        try {

            $client = new Client();
            $request = $client->request('POST', 'https://api.hubapi.com/oauth/v1/token', [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => env('HUBSPOT_CLIENT_ID'),
                    'client_secret' => env('HUBSPOT_CLIENT_SECRET'),
                    'refresh_token' => $refresh_token
                ]
            ]);

            return json_decode($request->getBody()->getContents());

        } catch (Exception $e) {
            Log::error("HubSpotWorker@getRefreshToken: " . $e->getMessage());
            throw new Exception($e->getMessage());
        }

    }

}
