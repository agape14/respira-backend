<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use App\Mail\CitaProgramadaMailable;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function enviarNotificacionCita($cita)
    {
        try {
            $paciente = Usuario::find($cita->paciente_id);
            $medico = Usuario::find($cita->medico_id);
            
            if (!$paciente) {
                Log::warning("No se pudo enviar correo: Paciente no encontrado ID {$cita->paciente_id}");
                return;
            }

            // Obtener email del serumista
            $emailDestino = $this->obtenerEmailSerumista($paciente);

            if (empty($emailDestino)) {
                Log::warning("No se pudo enviar correo: No se encontró email para el paciente {$paciente->nombre_completo}");
                return;
            }

            // Verificar si estamos en modo desarrollo/redirección
            $redirectEmail = env('MAIL_REDIRECT_TO');
            if (!empty($redirectEmail)) {
                Log::info("Redireccionando correo de {$emailDestino} a {$redirectEmail}");
                $emailDestino = $redirectEmail;
            }

            $data = [
                'nombrePaciente' => $paciente->nombre_completo,
                'fecha' => $cita->fecha,
                'horaInicio' => substr($cita->hora_inicio, 0, 5),
                'horaFin' => substr($cita->hora_fin, 0, 5),
                'nombreTerapeuta' => $medico ? $medico->nombre_completo : 'Terapeuta',
                'videoEnlace' => $cita->video_enlace
            ];

            Mail::to($emailDestino)->send(new CitaProgramadaMailable($data));
            Log::info("Correo de notificación enviado a {$emailDestino} para cita ID {$cita->id}");

        } catch (\Exception $e) {
            Log::error("Error al enviar notificación de cita: " . $e->getMessage());
        }
    }

    public function enviarNotificacionDerivacion($derivacion)
    {
        try {
            $paciente = Usuario::find($derivacion->paciente_id);
            $especialista = Usuario::find($derivacion->cenate_id);
            
            if (!$paciente) {
                Log::warning("No se pudo enviar correo derivación: Paciente no encontrado ID {$derivacion->paciente_id}");
                return;
            }

            // Obtener email del serumista
            $emailDestino = $this->obtenerEmailSerumista($paciente);

            if (empty($emailDestino)) {
                Log::warning("No se pudo enviar correo derivación: No se encontró email para el paciente {$paciente->nombre_completo}");
                return;
            }

            // Verificar si estamos en modo desarrollo/redirección
            $redirectEmail = env('MAIL_REDIRECT_TO');
            if (!empty($redirectEmail)) {
                Log::info("Redireccionando correo derivación de {$emailDestino} a {$redirectEmail}");
                $emailDestino = $redirectEmail;
            }

            $data = [
                'nombrePaciente' => $paciente->nombre_completo,
                'nombreEspecialista' => $especialista ? $especialista->nombre_completo : 'Especialista',
                'observacion' => $derivacion->observa,
                'fecha' => date('d/m/Y', strtotime($derivacion->fecha))
            ];

            Mail::to($emailDestino)->send(new \App\Mail\DerivacionMailable($data));
            Log::info("Correo de derivación enviado a {$emailDestino} para derivación ID {$derivacion->id}");

        } catch (\Exception $e) {
            Log::error("Error al enviar notificación de derivación: " . $e->getMessage());
        }
    }

    private function obtenerEmailSerumista($usuario)
    {
        // Intentar buscar en serumista_remunerados por CMP
        $serumista = DB::connection('sqlsrv')->table('serumista_remunerados')
            ->where('CMP', $usuario->cmp)
            ->first();

        if ($serumista && !empty($serumista->Email)) {
            return $serumista->Email;
        }

        // Intentar buscar en serumista_equivalentes_remunerados por CMP
        $serumista = DB::connection('sqlsrv')->table('serumista_equivalentes_remunerados')
            ->where('CMP', $usuario->cmp)
            ->first();

        if ($serumista && !empty($serumista->Email)) {
            return $serumista->Email;
        }

        // Si no se encuentra por CMP, intentar por nombre_usuario (asumiendo que es el email)
        // O buscar por nombre_usuario en las tablas de serumistas
        $serumista = DB::connection('sqlsrv')->table('serumista_remunerados')
            ->where('Email', $usuario->nombre_usuario)
            ->first();

        if ($serumista && !empty($serumista->Email)) {
            return $serumista->Email;
        }

        // Último recurso: usar el nombre_usuario si parece un email
        if (filter_var($usuario->nombre_usuario, FILTER_VALIDATE_EMAIL)) {
            return $usuario->nombre_usuario;
        }

        return null;
    }
}
