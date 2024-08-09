<?php

namespace App\Http\Middleware;

use Closure;
use HubSpot\Utils\Webhooks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HubSpotWebhook
{
    const MAX_ALLOWED_TIMESTAMP = 300000; // 5 minutes in milliseconds

    public function handle(Request $request, Closure $next)
    {

        $signatureV3 = $request->header('X-Hubspot-Signature-V3');

        if (!$signatureV3) {
            if (!Webhooks::isHubspotSignatureValid(
                $request->header('X-Hubspot-Signature'),
                env('HUBSPOT_CLIENT_SECRET'),
                $request->getContent(),
                $request->fullUrl(),
                $request->method(),
                $request->header('X-Hubspot-Signature-Version')
            )) {
                Log::warning("HubspotWebhookMiddleware: Unauthorised request from Hubspot webhook - Invalid Signature", ["req"=>['ip' => $request->ip(), 'user'=>'hubspot_webhooks']]);
                return response()->json(['message' => 'Hubspot signature is invalid'], 400);
            }
        } else {
            $url = $request->url();
            $method = $request->method();
            $body = $request->getContent();
            $hostname = $request->host();
            $signature = $request->header('X-Hubspot-Signature-V3');
            $timestamp = $request->header('X-Hubspot-Request-Timestamp') / 1000;

            // TODO: Validate timestamps are working correctly
            if (time() - $timestamp > self::MAX_ALLOWED_TIMESTAMP) {
                Log::warning("HubspotWebhookMiddleware: Unauthorised request from Hubspot webhook - Signature time mismatch", ["req"=>['ip' => $request->ip(), 'user'=>'hubspot_webhooks']]);
                return response()->json(['message' => 'Hubspot signature is invalid'], 400);
            }

            $rawString = "$method$url$body$timestamp";

            $computedSignature = base64_encode(hash_hmac('sha256', $rawString, env('HUBSPOT_CLIENT_SECRET')));

            if (hash_equals($signature, $computedSignature)) {
                Log::warning("HubspotWebhookMiddleware: Unauthorised request from Hubspot webhook - Invalid signature match", ["req"=>['ip' => $request->ip(), 'user'=>'hubspot_webhooks']]);
                return response()->json(['message' => 'Hubspot signature is invalid'], 400);
            }
        }

        Log::debug("HubspotWebhookMiddleware: Success", ["req"=>['ip' => $request->ip(), 'user'=>'hubspot_webhooks']]);
        return $next($request);
    }
}
