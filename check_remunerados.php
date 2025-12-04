<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "Columns in 'lista_remunerados':\n";
print_r(Schema::connection('sqlsrv')->getColumnListing('lista_remunerados'));

echo "\nColumns in 'lista_equivalentes_remunerados':\n";
print_r(Schema::connection('sqlsrv')->getColumnListing('lista_equivalentes_remunerados'));

echo "\nFirst 5 rows of 'perfiles':\n";
$perfiles = DB::connection('sqlsrv')->table('perfiles')->limit(5)->get();
foreach ($perfiles as $perfil) {
    echo $perfil->id . ": " . $perfil->nombre . "\n";
}

echo "\nChecking a few users with profile 6 or 7 (CENATE):\n";
$users = DB::connection('sqlsrv')->table('usuarios')->whereIn('perfil_id', [6, 7])->limit(5)->get();
foreach ($users as $user) {
    echo "ID: " . $user->id . ", Name: " . $user->nombre_completo . ", Perfil: " . $user->perfil_id . "\n";
}
