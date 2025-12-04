<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$perfiles = DB::connection('sqlsrv')->table('perfiles')->get();
foreach ($perfiles as $perfil) {
    echo "ID: " . $perfil->id . " - Name: " . $perfil->nombre_perfil . "\n";
}
