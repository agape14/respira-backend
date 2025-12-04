<?php

use App\Models\Cita;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Checking Citas data...\n";

// Check distribution of id_sesion
$withSesion = Cita::whereNotNull('id_sesion')->count();
$withoutSesion = Cita::whereNull('id_sesion')->count();

echo "Citas with id_sesion: $withSesion\n";
echo "Citas without id_sesion: $withoutSesion\n";

// Sample of Citas with id_sesion
echo "\nSample Citas with id_sesion:\n";
$samples = Cita::whereNotNull('id_sesion')->orderBy('id', 'desc')->take(10)->get();
foreach ($samples as $cita) {
    echo "ID: {$cita->id}, Paciente: {$cita->paciente_id}, id_sesion: {$cita->id_sesion}, Fecha: {$cita->fecha}\n";
}

// Check if id_sesion repeats for a patient
echo "\nChecking repetition of id_sesion for a patient:\n";
$patientId = Cita::whereNotNull('id_sesion')->value('paciente_id');
if ($patientId) {
    $citasPatient = Cita::where('paciente_id', $patientId)->orderBy('fecha')->get();
    foreach ($citasPatient as $cita) {
        echo "Patient $patientId - Cita {$cita->id}: id_sesion={$cita->id_sesion}\n";
    }
}

// Check SesionUno table
echo "\nChecking SesionUno table:\n";
$sesiones = DB::connection('sqlsrv')->table('sesionUno')->take(5)->get();
foreach ($sesiones as $s) {
    echo "SesionUno ID: {$s->id}, Paciente: {$s->paciente_id}, NroSesion: {$s->nro_sesion}\n";
}
