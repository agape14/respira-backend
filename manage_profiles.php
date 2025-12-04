<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$requiredProfiles = [
    'Administrador' => ['descripcion' => 'Perfil Administrador', 'permiso_ver' => 1, 'permiso_editar' => 1, 'permiso_eliminar' => 1],
    'Psicologo' => ['descripcion' => 'Perfil Psicologo', 'permiso_ver' => 1, 'permiso_editar' => 1, 'permiso_eliminar' => 0],
    'Coordinador' => ['descripcion' => 'Perfil Coordinador', 'permiso_ver' => 1, 'permiso_editar' => 1, 'permiso_eliminar' => 0],
    'Colaborador' => ['descripcion' => 'Perfil Colaborador', 'permiso_ver' => 1, 'permiso_editar' => 1, 'permiso_eliminar' => 0],
    'Visualizador' => ['descripcion' => 'Perfil Visualizador', 'permiso_ver' => 1, 'permiso_editar' => 0, 'permiso_eliminar' => 0],
];

// 1. Rename 'Especialista Psicólogo' to 'Psicologo' if exists
$psico = DB::connection('sqlsrv')->table('perfiles')->where('nombre_perfil', 'Especialista Psicólogo')->first();
if ($psico) {
    echo "Renaming 'Especialista Psicólogo' to 'Psicologo'...\n";
    DB::connection('sqlsrv')->table('perfiles')->where('id', $psico->id)->update(['nombre_perfil' => 'Psicologo']);
}

// 2. Create missing profiles
foreach ($requiredProfiles as $name => $data) {
    $exists = DB::connection('sqlsrv')->table('perfiles')->where('nombre_perfil', $name)->first();
    if (!$exists) {
        echo "Creating profile: $name\n";
        DB::connection('sqlsrv')->table('perfiles')->insert([
            'nombre_perfil' => $name,
            'descripcion' => $data['descripcion'],
            'permiso_ver' => $data['permiso_ver'],
            'permiso_editar' => $data['permiso_editar'],
            'permiso_eliminar' => $data['permiso_eliminar'],
            'estado' => 1
        ]);
    } else {
        echo "Profile exists: $name (ID: " . $exists->id . ")\n";
        // Ensure it's active
        if ($exists->estado != 1) {
            DB::connection('sqlsrv')->table('perfiles')->where('id', $exists->id)->update(['estado' => 1]);
        }
    }
}

// 3. Deactivate others
$allProfiles = DB::connection('sqlsrv')->table('perfiles')->get();
$allowedNames = array_keys($requiredProfiles);
foreach ($allProfiles as $p) {
    if (!in_array($p->nombre_perfil, $allowedNames)) {
        echo "Deactivating profile: " . $p->nombre_perfil . " (ID: " . $p->id . ")\n";
        DB::connection('sqlsrv')->table('perfiles')->where('id', $p->id)->update(['estado' => 0]);
    }
}

// 4. List final active profiles
echo "\nFinal Active Profiles:\n";
$active = DB::connection('sqlsrv')->table('perfiles')->where('estado', 1)->get();
foreach ($active as $p) {
    echo "ID: " . $p->id . " - " . $p->nombre_perfil . "\n";
}
