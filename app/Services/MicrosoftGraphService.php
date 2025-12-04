<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MicrosoftGraphService
{
    protected $clientId;
    protected $clientSecret;
    protected $tenantId;
    protected $userId;

    public function __construct()
    {
        $this->clientId = env('MS_CLIENT_ID');
        $this->clientSecret = env('MS_CLIENT_SECRET');
        $this->tenantId = env('MS_TENANT_ID');
        $this->userId = env('MS_USER_ID');
    }

    public function createMeeting($subject, $startTime, $endTime)
    {
        try {
            if (empty($this->userId)) {
                Log::error('Microsoft Graph: MS_USER_ID is missing in .env');
                return null;
            }

            $token = $this->getAccessToken();

            if (!$token) {
                Log::error('Microsoft Graph: No access token obtained.');
                return null;
            }

            $url = "https://graph.microsoft.com/v1.0/users/{$this->userId}/events";
            
            // Ensure UTC format
            $startDateTime = str_ends_with($startTime, 'Z') ? $startTime : $startTime . 'Z';
            $endDateTime = str_ends_with($endTime, 'Z') ? $endTime : $endTime . 'Z';

            // Payload structure for /events
            $body = [
                'subject' => $subject,
                'start' => [
                    'dateTime' => $startDateTime,
                    'timeZone' => 'UTC'
                ],
                'end' => [
                    'dateTime' => $endDateTime,
                    'timeZone' => 'UTC'
                ],
                'isOnlineMeeting' => true,
                'onlineMeetingProvider' => 'teamsForBusiness'
            ];

            $jsonBody = json_encode($body);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonBody)
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $data = json_decode($result, true);
                return $data['onlineMeeting']['joinUrl'] ?? null;
            } else {
                Log::error('Microsoft Graph: Error creating event', [
                    'status' => $httpCode,
                    'body' => $result,
                    'curl_error' => $curlError
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Microsoft Graph: Exception creating event', ['message' => $e->getMessage()]);
            return null;
        }
    }

    public function createOnlineMeeting($subject, $startTime, $endTime)
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) return null;

            $url = "https://graph.microsoft.com/v1.0/users/{$this->userId}/onlineMeetings";
            
            // Ensure UTC format
            $startDateTime = str_ends_with($startTime, 'Z') ? $startTime : $startTime . 'Z';
            $endDateTime = str_ends_with($endTime, 'Z') ? $endTime : $endTime . 'Z';

            $body = [
                'startDateTime' => $startDateTime,
                'endDateTime' => $endDateTime,
                'subject' => $subject
            ];

            $jsonBody = json_encode($body);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonBody)
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $data = json_decode($result, true);
                return $data['joinWebUrl'] ?? null;
            } else {
                Log::error('Microsoft Graph: Error creating online meeting', [
                    'status' => $httpCode,
                    'body' => $result
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Microsoft Graph: Exception creating online meeting', ['message' => $e->getMessage()]);
            return null;
        }
    }

    public function checkUserAccess()
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) return ['success' => false, 'message' => 'No token'];

            $url = "https://graph.microsoft.com/v1.0/users/{$this->userId}";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [
                'success' => $httpCode >= 200 && $httpCode < 300,
                'status' => $httpCode,
                'data' => json_decode($result, true)
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    protected function getAccessToken()
    {
        $url = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";

        $response = Http::asForm()->post($url, [
            'client_id' => $this->clientId,
            'scope' => 'https://graph.microsoft.com/.default',
            'client_secret' => $this->clientSecret,
            'grant_type' => 'client_credentials'
        ]);

        if ($response->successful()) {
            return $response->json()['access_token'];
        }

        Log::error('Microsoft Graph: Error getting access token', [
            'status' => $response->status(),
            'body' => $response->body()
        ]);

        return null;
    }
}
