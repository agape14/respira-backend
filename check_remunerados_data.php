<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "First row of 'lista_remunerados':\n";
    $row1 = DB::connection('sqlsrv')->table('lista_remunerados')->first();
    print_r($row1);

    echo "\nFirst row of 'lista_equivalentes_remunerados':\n";
    $row2 = DB::connection('sqlsrv')->table('lista_equivalentes_remunerados')->first();
    print_r($row2);
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
