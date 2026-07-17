<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class SmsService
{
    protected $provider;

    public function __construct()
    {
        $this->provider = config('services.sms.provider', env('SMS_PROVIDER', 'log'));
    }

    /**
     * Send an SMS to a single recipient.
     * Returns an array with success and provider response.
     */
    public function send(string $to, string $message): array
    {
        if (empty($to)) {
            return ['success' => false, 'error' => 'no_recipient'];
        }

        // ── AUTOMATIC KENYAN PHONE FORMATTER ──────────────────────────
        // Strip away any spaces, dashes, or parentheses
        $to = preg_replace('/\s+/', '', $to);

        // If it starts with 07... or 01..., replace the 0 with +254
        if (preg_match('/^(07|01)\d{8}$/', $to)) {
            $to = '+254' . substr($to, 1);
        } 
        // If it starts with 7... or 1... (missing the leading 0), prepend +254
        elseif (preg_match('/^(7|1)\d{8}$/', $to)) {
            $to = '+254' . $to;
        }

        if ($this->provider === 'log') {
            Log::info("SMS to {$to}: {$message}");
            return ['success' => true, 'provider' => 'log'];
        }

        if ($this->provider === 'twilio') {
            try {
                $accountSid = env('TWILIO_ACCOUNT_SID');
                $authToken = env('TWILIO_AUTH_TOKEN');
                $from = env('TWILIO_FROM');

                if (!$accountSid || !$authToken || !$from) {
                    return ['success' => false, 'error' => 'twilio_not_configured'];
                }

                $response = Http::withBasicAuth($accountSid, $authToken)
                    ->asForm()
                    ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                        'From' => $from,
                        'To' => $to,
                        'Body' => $message,
                    ]);

                return ['success' => $response->successful(), 'provider' => 'twilio', 'response' => $response->json()];
            } catch (\Throwable $e) {
                Log::error('SmsService twilio error: ' . $e->getMessage());
                return ['success' => false, 'error' => 'exception', 'message' => $e->getMessage()];
            }
        }

        if ($this->provider === 'africastalking' || $this->provider === 'africa') {
            try {
                $username = env('AFRICASTALKING_USERNAME');
                $apiKey = env('AFRICASTALKING_API_KEY');
                $from = env('AFRICASTALKING_SENDER', null);

                if (!$username || !$apiKey) {
                    return ['success' => false, 'error' => 'africastalking_not_configured'];
                }

                $payload = [
                    'username' => $username,
                    'to' => $to,
                    'message' => $message,
                ];

                if ($from) $payload['from'] = $from;

                $response = Http::withHeaders([
                    'apiKey' => $apiKey,
                    'Accept' => 'application/json',
                ])
                ->withoutVerifying()
                ->timeout(30)
                ->asForm()
                //->post('https://api.africastalking.com/version1/messaging', $payload);
                
                // To the Sandbox endpoint URL:
                ->post('https://api.sandbox.africastalking.com/version1/messaging', $payload);

                return ['success' => $response->successful(), 'provider' => 'africastalking', 'response' => $response->json()];
            } catch (\Throwable $e) {
                Log::error('SmsService africastalking error: ' . $e->getMessage());
                return ['success' => false, 'error' => 'exception', 'message' => $e->getMessage()];
            }
        }

        return ['success' => false, 'error' => 'provider_not_supported'];
    }
}
