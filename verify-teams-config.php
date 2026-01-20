<?php

/**
 * Script de verificación de configuración de Microsoft Teams/Graph
 * 
 * Ejecutar desde la raíz del backend:
 * php verify-teams-config.php
 */

require __DIR__.'/vendor/autoload.php';

use Illuminate\Support\Facades\Http;

// Cargar variables de entorno
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "=== VERIFICACIÓN DE CONFIGURACIÓN DE MICROSOFT TEAMS ===\n\n";

// 1. Verificar variables de entorno
echo "1. Variables de Entorno:\n";
$clientId = env('MS_CLIENT_ID');
$clientSecret = env('MS_CLIENT_SECRET');
$tenantId = env('MS_TENANT_ID');
$userId = env('MS_USER_ID');

$varsOk = true;

echo "   MS_CLIENT_ID: " . ($clientId ? "✅ Configurado" : "❌ FALTA") . "\n";
if (!$clientId) $varsOk = false;

echo "   MS_CLIENT_SECRET: " . ($clientSecret ? "✅ Configurado (***)" : "❌ FALTA") . "\n";
if (!$clientSecret) $varsOk = false;

echo "   MS_TENANT_ID: " . ($tenantId ? "✅ Configurado" : "❌ FALTA") . "\n";
if (!$tenantId) $varsOk = false;

echo "   MS_USER_ID: " . ($userId ? "✅ {$userId}" : "❌ FALTA") . "\n";
if (!$userId) $varsOk = false;

if (!$varsOk) {
    echo "\n❌ ERROR: Configurar todas las variables en .env\n";
    exit(1);
}

echo "\n2. Obteniendo Access Token...\n";

// 2. Obtener token
$tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

$response = Http::asForm()->post($tokenUrl, [
    'client_id' => $clientId,
    'scope' => 'https://graph.microsoft.com/.default',
    'client_secret' => $clientSecret,
    'grant_type' => 'client_credentials'
]);

if (!$response->successful()) {
    echo "   ❌ ERROR al obtener token:\n";
    echo "   Status: " . $response->status() . "\n";
    echo "   Body: " . $response->body() . "\n";
    echo "\n   POSIBLES CAUSAS:\n";
    echo "   - MS_CLIENT_ID, MS_CLIENT_SECRET o MS_TENANT_ID incorrectos\n";
    echo "   - Client Secret expirado (regenerar en Azure Portal)\n";
    exit(1);
}

$token = $response->json()['access_token'];
echo "   ✅ Token obtenido exitosamente\n";

echo "\n3. Verificando acceso al usuario...\n";

// 3. Verificar acceso al usuario
$userUrl = "https://graph.microsoft.com/v1.0/users/{$userId}";
$userResponse = Http::withHeaders([
    'Authorization' => "Bearer {$token}",
    'Content-Type' => 'application/json'
])->get($userUrl);

if (!$userResponse->successful()) {
    echo "   ❌ ERROR al acceder al usuario:\n";
    echo "   Status: " . $userResponse->status() . "\n";
    echo "   Body: " . $userResponse->body() . "\n";
    echo "\n   POSIBLES CAUSAS:\n";
    echo "   - MS_USER_ID incorrecto (no existe en el tenant)\n";
    echo "   - Falta permiso 'User.Read.All' en Azure AD\n";
    exit(1);
}

$userData = $userResponse->json();
echo "   ✅ Usuario encontrado: {$userData['displayName']} ({$userData['mail']})\n";

echo "\n4. Verificando permisos de Calendar...\n";

// 4. Verificar permisos de calendario
$calendarUrl = "https://graph.microsoft.com/v1.0/users/{$userId}/events?\$top=1";
$calendarResponse = Http::withHeaders([
    'Authorization' => "Bearer {$token}",
    'Content-Type' => 'application/json'
])->get($calendarUrl);

if (!$calendarResponse->successful()) {
    $errorBody = json_decode($calendarResponse->body(), true);
    $errorCode = $errorBody['error']['code'] ?? 'Unknown';
    
    if ($errorCode === 'ErrorAccessDenied' || $calendarResponse->status() === 403) {
        echo "   ❌ ERROR: Acceso denegado (403)\n";
        echo "\n   SOLUCIÓN REQUERIDA:\n";
        echo "   1. Ir a Azure Portal → Azure Active Directory\n";
        echo "   2. App registrations → Tu aplicación\n";
        echo "   3. API permissions → Add permission\n";
        echo "   4. Microsoft Graph → Application permissions\n";
        echo "   5. Agregar: Calendars.ReadWrite\n";
        echo "   6. Click 'Grant admin consent' (CRÍTICO)\n";
        exit(1);
    }
    
    echo "   ⚠️  WARNING: " . $calendarResponse->status() . "\n";
    echo "   Body: " . $calendarResponse->body() . "\n";
} else {
    echo "   ✅ Acceso al calendario verificado\n";
}

echo "\n5. Verificando permisos de OnlineMeetings...\n";

// 5. Verificar permisos de reuniones online
$meetingsUrl = "https://graph.microsoft.com/v1.0/users/{$userId}/onlineMeetings";
$meetingsResponse = Http::withHeaders([
    'Authorization' => "Bearer {$token}",
    'Content-Type' => 'application/json'
])->get($meetingsUrl);

if (!$meetingsResponse->successful()) {
    $errorBody = json_decode($meetingsResponse->body(), true);
    $errorCode = $errorBody['error']['code'] ?? 'Unknown';
    
    if ($errorCode === 'ErrorAccessDenied' || $meetingsResponse->status() === 403) {
        echo "   ❌ ERROR: Acceso denegado (403)\n";
        echo "\n   SOLUCIÓN REQUERIDA:\n";
        echo "   1. Ir a Azure Portal → Azure Active Directory\n";
        echo "   2. App registrations → Tu aplicación\n";
        echo "   3. API permissions → Add permission\n";
        echo "   4. Microsoft Graph → Application permissions\n";
        echo "   5. Agregar: OnlineMeetings.ReadWrite.All\n";
        echo "   6. Click 'Grant admin consent' (CRÍTICO)\n";
        exit(1);
    }
    
    echo "   ⚠️  WARNING: " . $meetingsResponse->status() . "\n";
    echo "   Body: " . $meetingsResponse->body() . "\n";
} else {
    echo "   ✅ Acceso a OnlineMeetings verificado\n";
}

echo "\n6. Prueba de creación de reunión (sin guardar)...\n";

// 6. Intentar crear una reunión de prueba
$testStart = (new DateTime('+1 hour', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
$testEnd = (new DateTime('+2 hours', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

$eventUrl = "https://graph.microsoft.com/v1.0/users/{$userId}/events";
$eventBody = [
    'subject' => 'TEST - Verificación Teams (BORRAR)',
    'start' => [
        'dateTime' => $testStart,
        'timeZone' => 'UTC'
    ],
    'end' => [
        'dateTime' => $testEnd,
        'timeZone' => 'UTC'
    ],
    'isOnlineMeeting' => true,
    'onlineMeetingProvider' => 'teamsForBusiness'
];

$eventResponse = Http::withHeaders([
    'Authorization' => "Bearer {$token}",
    'Content-Type' => 'application/json'
])->post($eventUrl, $eventBody);

if (!$eventResponse->successful()) {
    echo "   ❌ ERROR al crear evento de prueba:\n";
    echo "   Status: " . $eventResponse->status() . "\n";
    echo "   Body: " . $eventResponse->body() . "\n";
    exit(1);
}

$eventData = $eventResponse->json();
$teamsLink = $eventData['onlineMeeting']['joinUrl'] ?? null;

if ($teamsLink) {
    echo "   ✅ Reunión de prueba creada exitosamente!\n";
    echo "   Teams Link: {$teamsLink}\n";
    
    // Eliminar el evento de prueba
    echo "\n7. Limpiando evento de prueba...\n";
    $eventId = $eventData['id'];
    $deleteUrl = "https://graph.microsoft.com/v1.0/users/{$userId}/events/{$eventId}";
    $deleteResponse = Http::withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->delete($deleteUrl);
    
    if ($deleteResponse->successful()) {
        echo "   ✅ Evento de prueba eliminado\n";
    } else {
        echo "   ⚠️  No se pudo eliminar el evento de prueba (eliminar manualmente)\n";
    }
} else {
    echo "   ⚠️  Evento creado pero sin Teams link\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "✅ CONFIGURACIÓN COMPLETADA EXITOSAMENTE\n";
echo "Tu aplicación puede crear reuniones de Teams correctamente.\n";
echo str_repeat("=", 60) . "\n";

