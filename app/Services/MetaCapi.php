<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class MetaCapi
{
    private const GRAPH_VERSION = 'v25.0';

    public static function generateEventId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function sendEvent(
        string $eventName,
        string $eventId,
        array $userData = [],
        array $customData = [],
        ?string $eventSourceUrl = null,
        string $actionSource = 'website'
    ): void {
        $pixelId      = env('META_PIXEL_ID');
        $accessToken  = env('META_ACCESS_TOKEN');
        $appSecret    = env('META_APP_SECRET');
        $testEventCode = env('META_TEST_EVENT_CODE');

        if (! $pixelId || ! $accessToken) {
            Log::warning('Meta CAPI not configured — skipping event', ['event' => $eventName]);
            return;
        }

        $appsecretProof = $appSecret
            ? hash_hmac('sha256', $accessToken, $appSecret)
            : null;

        $payload = [
            'event_name'    => $eventName,
            'event_time'    => time(),
            'event_id'      => $eventId,
            'action_source' => $actionSource,
            'user_data'     => self::buildUserData($userData),
        ];
        if ($eventSourceUrl) {
            $payload['event_source_url'] = $eventSourceUrl;
        }
        if (! empty($customData)) {
            $payload['custom_data'] = $customData;
        }

        $form = [
            'data'         => json_encode([$payload]),
            'access_token' => $accessToken,
        ];
        if ($appsecretProof) {
            $form['appsecret_proof'] = $appsecretProof;
        }
        if ($testEventCode) {
            $form['test_event_code'] = $testEventCode;
        }

        try {
            $client = new Client(['timeout' => 5.0]);
            $response = $client->post(
                'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . $pixelId . '/events',
                ['form_params' => $form]
            );
            Log::info('Meta CAPI event sent', [
                'event'  => $eventName,
                'id'     => $eventId,
                'status' => $response->getStatusCode(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Meta CAPI dispatch failed', [
                'event' => $eventName,
                'id'    => $eventId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function buildUserData(array $u): array
    {
        $hash = fn(?string $v) => $v ? hash('sha256', strtolower(trim($v))) : null;
        $hashPhone = fn(?string $v) => $v ? hash('sha256', preg_replace('/\D/', '', $v)) : null;

        $out = [];
        if (! empty($u['email']))      $out['em'] = [$hash($u['email'])];
        if (! empty($u['phone']))      $out['ph'] = [$hashPhone($u['phone'])];
        if (! empty($u['first_name'])) $out['fn'] = [$hash($u['first_name'])];
        if (! empty($u['last_name']))  $out['ln'] = [$hash($u['last_name'])];
        if (! empty($u['city']))       $out['ct'] = [$hash($u['city'])];
        if (! empty($u['country']))    $out['country'] = [$hash($u['country'])];
        if (! empty($u['fbp']))        $out['fbp'] = $u['fbp'];
        if (! empty($u['fbc']))        $out['fbc'] = $u['fbc'];
        if (! empty($u['client_ip_address']))  $out['client_ip_address'] = $u['client_ip_address'];
        if (! empty($u['client_user_agent']))  $out['client_user_agent'] = $u['client_user_agent'];
        return $out;
    }

    public static function userContextFromRequest(\Illuminate\Http\Request $request): array
    {
        $name = $request->input('name', '');
        $parts = preg_split('/\s+/', trim($name), 2);
        return [
            'email'             => $request->input('email'),
            'phone'             => $request->input('phone'),
            'first_name'        => $parts[0] ?? null,
            'last_name'         => $parts[1] ?? null,
            'country'           => 'ca',
            'fbp'               => $request->cookie('_fbp'),
            'fbc'               => $request->cookie('_fbc'),
            'client_ip_address' => $request->ip(),
            'client_user_agent' => $request->userAgent(),
        ];
    }
}
