<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$clientId = env('MS_CLIENT_ID');
$clientSecret = env('MS_CLIENT_SECRET');
$tenantId = env('MS_TENANT_ID');

echo "--- Debugging Microsoft Graph Users ---\n";

// 1. Get Token
$url = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
$response = Http::asForm()->post($url, [
    'client_id' => $clientId,
    'scope' => 'https://graph.microsoft.com/.default',
    'client_secret' => $clientSecret,
    'grant_type' => 'client_credentials'
]);

if (!$response->successful()) {
    die("Error getting token: " . $response->body() . "\n");
}

$token = $response->json()['access_token'];
echo "Token obtained successfully.\n";

// 2. List Users
$usersUrl = "https://graph.microsoft.com/v1.0/users?\$select=displayName,userPrincipalName,id,userType,mail";
$usersResponse = Http::withToken($token)->get($usersUrl);

if ($usersResponse->successful()) {
    $users = $usersResponse->json()['value'];
    echo "\nFound " . count($users) . " users:\n";
    echo str_pad("User Type", 12) . " | " . str_pad("Name", 30) . " | " . str_pad("ID", 38) . " | UPN\n";
    echo str_repeat("-", 100) . "\n";
    
    foreach ($users as $user) {
        echo str_pad($user['userType'] ?? 'Unknown', 12) . " | " . 
             str_pad(substr($user['displayName'], 0, 28), 30) . " | " . 
             str_pad($user['id'], 38) . " | " . 
             ($user['userPrincipalName'] ?? 'N/A') . "\n";
    }
    echo "\nRECOMMENDATION: Use the ID of a user where User Type is 'Member'.\n";
} else {
    echo "Error listing users: " . $usersResponse->body() . "\n";
}
