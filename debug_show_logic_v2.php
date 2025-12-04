<?php

use App\Models\Cita;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    // Find a patient with multiple appointments
    $patient = Cita::select('paciente_id', DB::raw('count(*) as total'))
        ->groupBy('paciente_id')
        ->having('total', '>', 1)
        ->first();

    if (!$patient) {
        echo "No patient with multiple appointments found.\n";
        exit;
    }

    $patientId = $patient->paciente_id;
    echo "Testing with Patient ID: $patientId\n";

    $citas = Cita::where('paciente_id', $patientId)
        ->orderBy('fecha', 'asc')
        ->orderBy('hora_inicio', 'asc')
        ->get();

    foreach ($citas as $cita) {
        // Simulate logic from show method
        $numeroCitaGlobal = Cita::where('paciente_id', $cita->paciente_id)
            ->where(function ($q) use ($cita) {
                $q->where('fecha', '<', $cita->fecha)
                  ->orWhere(function ($q2) use ($cita) {
                      $q2->where('fecha', '=', $cita->fecha)
                         ->where('hora_inicio', '<=', $cita->hora_inicio);
                  });
            })
            ->count();
        
        echo "Cita ID: {$cita->id}, Fecha: {$cita->fecha}, Hora: {$cita->hora_inicio} -> Global: $numeroCitaGlobal\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
