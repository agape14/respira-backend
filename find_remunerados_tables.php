<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$sql = "SELECT TABLE_SCHEMA, TABLE_NAME 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_NAME LIKE '%remunerados%'";

$results = DB::connection('sqlsrv')->select($sql);
foreach ($results as $row) {
    echo $row->TABLE_SCHEMA . "." . $row->TABLE_NAME . "\n";
}
