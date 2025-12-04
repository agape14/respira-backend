<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$columns = Schema::connection('sqlsrv')->getColumnListing('usuarios');
echo "Columns in 'usuarios' table:\n";
print_r($columns);

// Also check if there is a 'plazas' table or similar
$tables = DB::connection('sqlsrv')->select('SELECT name FROM sys.tables');
echo "\nTables in database:\n";
foreach ($tables as $table) {
    echo $table->name . "\n";
}
