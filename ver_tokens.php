<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "                    TOKENS EXTERNOS GENERADOS\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$tokens = DB::connection('sqlsrv')->table('external_tokens')->get();

if ($tokens->isEmpty()) {
    echo "❌ No hay tokens en la base de datos\n";
    echo "   Ejecuta: php artisan db:seed --class=ExternalTokenSeeder\n\n";
    exit(1);
}

foreach ($tokens as $token) {
    echo "Token:       " . $token->token . "\n";
    echo "Aplicación:  " . $token->nombre_aplicacion . "\n";
    echo "Consejo:     " . ($token->consejo_regional_id ? "ID: " . $token->consejo_regional_id : "Todos") . "\n";
    echo "Estado:      " . ($token->estado == 1 ? "Activo ✅" : "Inactivo ❌") . "\n";
    echo "Expira:      " . $token->fecha_expiracion . "\n";
    echo "\n";
    echo "URL de prueba:\n";
    echo "http://localhost:5173/external/dashboard?token=" . $token->token . "&id_cr=1\n";
    echo "\n";
    echo "-------------------------------------------------------------------\n\n";
}

echo "Para probar, copia cualquiera de las URLs de arriba en tu navegador.\n\n";

