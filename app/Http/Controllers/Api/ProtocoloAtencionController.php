<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Models\SesionUno;
use App\Models\Turno;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

class ProtocoloAtencionController extends Controller
{
    /**
     * Obtener estadísticas para las tarjetas (KPIs)
     * GET /api/protocolos/stats
     */
    public function stats(Request $request)
    {
        try {
            $user = $request->user();

            // Scope de Riesgo Moderado o Alto
            $queryBase = Cita::whereHas('paciente', function ($query) {
                $query->whereHas('phq9Responses', function ($q) {
                    $q->where(function ($q2) {
                        $q2->where('riesgo', 'like', '%Moderado%')
                           ->orWhere('riesgo', 'like', '%alto%');
                    });
                })->orWhereHas('gadResponses', function ($q) {
                    $q->where(function ($q2) {
                        $q2->where('riesgo', 'like', '%Moderado%')
                           ->orWhere('riesgo', 'like', '%alto%');
                    });
                });
            });

            // Filtro automático por perfil: Si es psicólogo, solo ver sus citas
            if ($user && $user->perfil) {
                $nombrePerfil = strtolower($user->perfil->nombre_perfil ?? '');
                if (strpos($nombrePerfil, 'psicologo') !== false || strpos($nombrePerfil, 'psicólogo') !== false) {
                    $queryBase->where('medico_id', $user->id);
                }
            }

            // Solo excluir derivados si NO se está filtrando específicamente por derivados
            if (!($request->filled('estado') && $request->estado === 'derivados')) {
                $queryBase->whereDoesntHave('derivado');
            }

            // Aplicar filtros de terapeuta, paciente y mes (se aplican a TODOS los contadores)
            if ($request->filled('terapeuta_id') && $request->terapeuta_id !== 'todos') {
                $queryBase->where('medico_id', $request->terapeuta_id);
            }

            if ($request->filled('paciente_id') && $request->paciente_id !== 'todos') {
                $queryBase->where('paciente_id', $request->paciente_id);
            }

            if ($request->filled('mes') && $request->mes !== 'todos') {
                if (is_numeric($request->mes)) {
                    $queryBase->whereMonth('fecha', $request->mes)
                          ->whereYear('fecha', now_lima()->year);
                }
            }

            // Si hay filtro de estado, contar solo ese estado
            // Si NO hay filtro de estado, contar todos los estados
            $filtroEstado = $request->filled('estado') && $request->estado !== 'todos'
                ? $request->estado
                : null;

            if ($filtroEstado) {
                // HAY filtro de estado: mostrar solo ese contador, resto en 0
                $porAtender = 0;
                $atendidos = 0;
                $noSePresentaron = 0;
                $cancelados = 0;
                $finalizados = 0;
                $derivados = 0;

                if ($filtroEstado === '1') {
                    $porAtender = (clone $queryBase)->where('estado', 1)->count();
                } elseif ($filtroEstado === '2') {
                    $atendidos = (clone $queryBase)->where('estado', 2)->count();
                } elseif ($filtroEstado === '3') {
                    $noSePresentaron = (clone $queryBase)->where('estado', 3)->count();
                } elseif ($filtroEstado === '4') {
                    $cancelados = (clone $queryBase)->where('estado', 4)->count();
                } elseif ($filtroEstado === 'finalizados') {
                    $finalizados = (clone $queryBase)->whereHas('finalizado')->count();
                } elseif ($filtroEstado === 'derivados') {
                    // Contar derivados (citas con registro en tabla derivados)
                    $derivados = (clone $queryBase)->whereHas('derivado')->count();
                }
            } else {
                // NO hay filtro de estado: mostrar todos los contadores
                $porAtender = (clone $queryBase)->where('estado', 1)->count();
                $atendidos = (clone $queryBase)->where('estado', 2)->count();
                $noSePresentaron = (clone $queryBase)->where('estado', 3)->count();
                $cancelados = (clone $queryBase)->where('estado', 4)->count();
                $finalizados = (clone $queryBase)->whereHas('finalizado')->count();
                $derivados = (clone $queryBase)->whereHas('derivado')->count();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'por_atender' => $porAtender,
                    'atendidos' => $atendidos,
                    'no_se_presentaron' => $noSePresentaron,
                    'cancelados' => $cancelados,
                    'finalizados' => $finalizados,
                    'derivados' => $derivados
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener lista de pacientes que tienen citas en el protocolo
     * GET /api/protocolos/pacientes
     */
    public function pacientes(Request $request)
    {
        try {
            $user = $request->user();

            // Obtener pacientes únicos que tienen citas de riesgo moderado o alto
            $query = DB::table('citas')
                ->join('usuarios as pacientes', 'citas.paciente_id', '=', 'pacientes.id')
                ->where(function ($query) {
                    $query->whereExists(function ($query) {
                        $query->selectRaw('1')
                            ->from('phq9_responses')
                            ->whereColumn('phq9_responses.user_id', 'pacientes.id')
                            ->where(function ($q) {
                                $q->where('phq9_responses.riesgo', 'like', '%Moderado%')
                                  ->orWhere('phq9_responses.riesgo', 'like', '%alto%');
                            });
                    })
                    ->orWhereExists(function ($query) {
                        $query->selectRaw('1')
                            ->from('gad_responses')
                            ->whereColumn('gad_responses.user_id', 'pacientes.id')
                            ->where(function ($q) {
                                $q->where('gad_responses.riesgo', 'like', '%Moderado%')
                                  ->orWhere('gad_responses.riesgo', 'like', '%alto%');
                            });
                    });
                });

            // Filtro automático por perfil: Si es psicólogo, solo ver sus pacientes
            if ($user && $user->perfil) {
                $nombrePerfil = strtolower($user->perfil->nombre_perfil ?? '');
                if (strpos($nombrePerfil, 'psicologo') !== false || strpos($nombrePerfil, 'psicólogo') !== false) {
                    $query->where('citas.medico_id', $user->id);
                }
            }

            $pacientes = $query
                ->select('pacientes.id', 'pacientes.nombre_completo', 'pacientes.cmp')
                ->distinct()
                ->orderBy('pacientes.nombre_completo')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pacientes
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener pacientes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar atenciones/evaluaciones con filtros
     * GET /api/protocolos
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            // Cargar relaciones necesarias
            // Siempre cargar finalizado y derivado para poder mostrar badges correctamente
            $eagerLoad = ['paciente', 'medico', 'turno', 'finalizado.usuario', 'derivado.especialista'];

            $query = Cita::with($eagerLoad)
                // Scope de Riesgo Moderado o Alto
                ->whereHas('paciente', function ($query) {
                    $query->whereHas('phq9Responses', function ($q) {
                        $q->where(function ($q2) {
                            $q2->where('riesgo', 'like', '%Moderado%')
                               ->orWhere('riesgo', 'like', '%alto%');
                        });
                    })->orWhereHas('gadResponses', function ($q) {
                        $q->where(function ($q2) {
                            $q2->where('riesgo', 'like', '%Moderado%')
                               ->orWhere('riesgo', 'like', '%alto%');
                        });
                    });
                });

            // Filtro automático por perfil: Si es psicólogo, solo ver sus citas
            if ($user && $user->perfil) {
                $nombrePerfil = strtolower($user->perfil->nombre_perfil ?? '');
                if (strpos($nombrePerfil, 'psicologo') !== false || strpos($nombrePerfil, 'psicólogo') !== false) {
                    $query->where('medico_id', $user->id);
                }
            }

            // Solo excluir derivados si NO se está filtrando específicamente por derivados
            if (!($request->filled('estado') && $request->estado === 'derivados')) {
                $query->whereDoesntHave('derivado');
            }

            // Filtros
            if ($request->filled('terapeuta_id') && $request->terapeuta_id !== 'todos') {
                $query->where('medico_id', $request->terapeuta_id);
            }

            if ($request->filled('paciente_id') && $request->paciente_id !== 'todos') {
                $query->where('paciente_id', $request->paciente_id);
            }

            if ($request->filled('mes') && $request->mes !== 'todos') {
                if (is_numeric($request->mes)) {
                    $query->whereMonth('fecha', $request->mes)
                          ->whereYear('fecha', now_lima()->year);
                }
            }

            if ($request->filled('estado') && $request->estado !== 'todos') {
                if ($request->estado === 'finalizados') {
                    // Filtrar solo citas con intervención finalizada (tabla citas_finalizados)
                    $query->whereHas('finalizado');
                } elseif ($request->estado === 'derivados') {
                    // Filtrar solo citas derivadas (tabla derivados)
                    $query->whereHas('derivado');
                } else {
                    // Filtrar por estado normal
                    $query->where('estado', $request->estado);
                }
            }

            // Ordenamiento
            $query->orderBy('fecha', 'desc')
                  ->orderBy('hora_inicio', 'asc');

            // Paginación
            $perPage = $request->get('per_page', 10);
            $citas = $query->paginate($perPage);

            // Enriquecer datos con Intervención, Sesión y próxima cita
            $citas->getCollection()->transform(function ($cita) {
                // Enriquecer con intervención y sesión
                $cita = $this->enrichCitaWithInterventionSession($cita);

                // Buscar si existe una próxima cita para este paciente
                $proximaCita = Cita::where('paciente_id', $cita->paciente_id)
                    ->whereIn('estado', [1, 2]) // Agendado o Atendido
                    ->where(function ($q) use ($cita) {
                        $q->where('fecha', '>', $cita->fecha)
                          ->orWhere(function ($q2) use ($cita) {
                              $q2->where('fecha', '=', $cita->fecha)
                                 ->where('hora_inicio', '>', $cita->hora_inicio);
                          });
                    })
                    ->orderBy('fecha', 'asc')
                    ->orderBy('hora_inicio', 'asc')
                    ->first();

                $cita->proxima_cita_id = $proximaCita ? $proximaCita->id : null;

                return $cita;
            });

            return response()->json([
                'success' => true,
                'data' => $citas->items(),
                'total' => $citas->total(),
                'per_page' => $citas->perPage(),
                'current_page' => $citas->currentPage(),
                'last_page' => $citas->lastPage()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al listar protocolos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener detalles de la sesión/protocolo
     * GET /api/protocolos/{cita_id}
     */
    public function show($citaId)
    {
        try {
            $cita = Cita::with(['paciente', 'medico'])->findOrFail($citaId);

            // Obtener datos de la sesión
            // Primero intentamos por id_sesion de la cita
            $sesion = null;
            if ($cita->id_sesion) {
                $sesion = SesionUno::find($cita->id_sesion);
            }

            // Si no hay id_sesion o no se encontró, buscar por paciente (última sesión)
            if (!$sesion) {
                $sesion = SesionUno::where('paciente_id', $cita->paciente_id)
                    ->orderBy('id', 'desc')
                    ->first();
            }

            // Enriquecer cita con números de intervención y sesión
            $citaEnriquecida = $this->enrichCitaWithInterventionSession($cita);

            // Verificar si esta cita está finalizada (intervención finalizada)
            $finalizacion = \App\Models\CitasFinalizado::where('cita_id', $citaId)
                ->with(['paciente', 'usuario' => function($query) {
                    $query->select('id', 'nombre_completo');
                }])
                ->first();

            $esta_finalizada = $finalizacion ? true : false;

            // Verificar si esta cita está derivada
            $derivacion = \App\Models\Derivado::where('cita_id', $citaId)
                ->with(['especialista' => function($query) {
                    $query->select('id', 'nombre_completo');
                }, 'paciente'])
                ->first();

            $esta_derivada = $derivacion ? true : false;

            // Determinar si es ESSALUD o MINSA
            $tipo_derivacion = null;
            if ($derivacion && $cita->paciente) {
                $cmp = $cita->paciente->cmp;
                $dni = $cita->paciente->nombre_usuario;

                // Verificar si está en plaza remunerada (ESSALUD)
                $esRemunerado = DB::connection('sqlsrv')->table('serumista_remunerados')
                    ->where('CMP', $cmp)
                    ->orWhere('NumeroDocumento', $dni)
                    ->exists();

                // Verificar si está en equivalentes (MINSA)
                $esEquivalente = DB::connection('sqlsrv')->table('serumista_equivalentes_remunerados')
                    ->where('CMP', $cmp)
                    ->orWhere('NumeroDocumento', $dni)
                    ->exists();

                if ($esRemunerado) {
                    $tipo_derivacion = 'ESSALUD';
                } elseif ($esEquivalente) {
                    $tipo_derivacion = 'MINSA';
                } else {
                    $tipo_derivacion = 'MINSA'; // Por defecto
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'cita' => $citaEnriquecida,
                    'sesion' => $sesion,
                    'numero_cita_global' => $citaEnriquecida->numero_cita_global,
                    'es_riesgo_alto' => $this->checkHighRisk($cita->paciente_id),
                    'esta_finalizada' => $esta_finalizada,
                    'finalizacion' => $finalizacion,
                    'esta_derivada' => $esta_derivada,
                    'derivacion' => $derivacion,
                    'tipo_derivacion' => $tipo_derivacion
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener detalles del protocolo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Agendar cita (próxima sesión)
     * POST /api/protocolos/agendar
     */
    public function agendar(Request $request, \App\Services\MicrosoftGraphService $graphService, \App\Services\NotificationService $notificationService)
    {
        try {
            $validator = Validator::make($request->all(), [
                'turno_id' => 'required|integer|exists:sqlsrv.turnos,id',
                'paciente_id' => 'required|integer|exists:sqlsrv.usuarios,id',
                'cita_origen_id' => 'nullable|integer|exists:sqlsrv.citas,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar turno disponible
            $citaExistente = Cita::where('turno_id', $request->turno_id)
                ->where('estado', '!=', 4) // 4 = Cancelado
                ->first();

            if ($citaExistente) {
                return response()->json([
                    'success' => false,
                    'message' => 'El turno ya está ocupado'
                ], 422);
            }

            $turno = Turno::findOrFail($request->turno_id);

            // Verificar si el paciente ya tiene una cita en ese horario (Overlap)
            $overlap = Cita::where('paciente_id', $request->paciente_id)
                ->where('estado', '!=', 4) // No cancelado
                ->where('fecha', $turno->fecha)
                ->where(function ($q) use ($turno) {
                    $q->where(function ($q2) use ($turno) {
                        $q2->where('hora_inicio', '>=', $turno->hora_inicio)
                           ->where('hora_inicio', '<', $turno->hora_fin);
                    })->orWhere(function ($q2) use ($turno) {
                        $q2->where('hora_fin', '>', $turno->hora_inicio)
                           ->where('hora_fin', '<=', $turno->hora_fin);
                    })->orWhere(function ($q2) use ($turno) {
                        $q2->where('hora_inicio', '<', $turno->hora_inicio)
                           ->where('hora_fin', '>', $turno->hora_fin);
                    });
                })
                ->exists();

            if ($overlap) {
                return response()->json([
                    'success' => false,
                    'message' => 'El paciente ya tiene una cita programada en este horario (o se solapa).'
                ], 422);
            }

            // Determinar sesión
            $idSesion = null;
            if ($request->cita_origen_id) {
                $citaOrigen = Cita::find($request->cita_origen_id);
                $idSesion = $citaOrigen->id_sesion;
            }

            // Crear nueva cita usando Query Builder para SQL Server
            // Convertir fecha a formato SQL Server
            $fechaFormateada = Carbon::parse($turno->fecha)->format('Y-m-d');

            $citaId = DB::connection('sqlsrv')
                ->table('citas')
                ->insertGetId([
                    'paciente_id' => $request->paciente_id,
                    'medico_id' => $turno->medico_id,
                    'turno_id' => $request->turno_id,
                    'fecha' => $fechaFormateada,
                    'hora_inicio' => $turno->hora_inicio,
                    'hora_fin' => $turno->hora_fin,
                    'estado' => 1, // Agendado
                    'user_id' => $request->user()->id,
                    'id_sesion' => $idSesion,
                    'created_at' => DB::raw("GETDATE()"),
                    'updated_at' => DB::raw("GETDATE()")
                ]);

            $cita = Cita::find($citaId);

            // Generar reunión de Teams
            try {
                $paciente = \App\Models\Usuario::find($request->paciente_id);
                $nombrePaciente = $paciente ? $paciente->nombre_completo : 'Paciente';

                // Combinar fecha y hora usando la zona horaria de Perú
                $timezone = 'America/Lima';

                // Extraer solo la fecha (sin hora) del campo fecha
                $fechaSolo = Carbon::parse($turno->fecha)->format('Y-m-d');

                // Extraer solo la hora (sin microsegundos) de hora_inicio y hora_fin
                $horaInicio = substr($turno->hora_inicio, 0, 8); // HH:MM:SS
                $horaFin = substr($turno->hora_fin, 0, 8); // HH:MM:SS

                // Crear instancias Carbon con la zona horaria local
                $startDateTimeLocal = Carbon::parse($fechaSolo . ' ' . $horaInicio, $timezone);
                $endDateTimeLocal = Carbon::parse($fechaSolo . ' ' . $horaFin, $timezone);

                // Convertir a UTC para Microsoft Graph
                $startDateTime = $startDateTimeLocal->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
                $endDateTime = $endDateTimeLocal->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');

                $subject = "Cita Psicológica - " . $nombrePaciente;

                $teamsLink = $graphService->createMeeting($subject, $startDateTime, $endDateTime);

                if ($teamsLink) {
                    $cita->video_enlace = $teamsLink;
                    $cita->save();
                    \Illuminate\Support\Facades\Log::info("Enlace Teams generado para cita ID {$cita->id} (Protocolo): {$teamsLink}");

                    // Enviar notificación por correo
                    $notificationService->enviarNotificacionCita($cita);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Error generando reunión Teams en Protocolo::agendar: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Cita agendada exitosamente',
                'cita_id' => $cita->id,
                'video_enlace' => $cita->video_enlace
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al agendar cita',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reprogramar cita (No se presentó -> Nuevo Turno)
     * POST /api/protocolos/reprogramar
     */
    /**
     * Reprogramar cita (No se presentó -> Nuevo Turno)
     * POST /api/protocolos/reprogramar
     */
    public function reprogramar(Request $request, \App\Services\MicrosoftGraphService $graphService, \App\Services\NotificationService $notificationService)
    {
        try {
            $validator = Validator::make($request->all(), [
                'cita_id' => 'required|integer|exists:sqlsrv.citas,id',
                'nuevo_turno_id' => 'required|integer|exists:sqlsrv.turnos,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $cita = Cita::findOrFail($request->cita_id);
            $nuevoTurno = Turno::findOrFail($request->nuevo_turno_id);

            // Verificar nuevo turno disponible
            $ocupado = Cita::where('turno_id', $request->nuevo_turno_id)
                ->where('estado', '!=', 4)
                ->exists();

            if ($ocupado) {
                return response()->json([
                    'success' => false,
                    'message' => 'El nuevo turno ya está ocupado'
                ], 422);
            }

            // Verificar si el paciente ya tiene una cita en ese horario (Overlap)
            $overlap = Cita::where('paciente_id', $cita->paciente_id)
                ->where('estado', '!=', 4) // No cancelado
                ->where('fecha', $nuevoTurno->fecha)
                ->where(function ($q) use ($nuevoTurno) {
                    $q->where(function ($q2) use ($nuevoTurno) {
                        $q2->where('hora_inicio', '>=', $nuevoTurno->hora_inicio)
                           ->where('hora_inicio', '<', $nuevoTurno->hora_fin);
                    })->orWhere(function ($q2) use ($nuevoTurno) {
                        $q2->where('hora_fin', '>', $nuevoTurno->hora_inicio)
                           ->where('hora_fin', '<=', $nuevoTurno->hora_fin);
                    })->orWhere(function ($q2) use ($nuevoTurno) {
                        $q2->where('hora_inicio', '<', $nuevoTurno->hora_inicio)
                           ->where('hora_fin', '>', $nuevoTurno->hora_fin);
                    });
                })
                ->exists();

            if ($overlap) {
                return response()->json([
                    'success' => false,
                    'message' => 'El paciente ya tiene una cita programada en este horario (o se solapa).'
                ], 422);
            }

            // Crear nueva cita usando Query Builder para SQL Server
            // Convertir fecha a formato SQL Server
            $fechaFormateada = Carbon::parse($nuevoTurno->fecha)->format('Y-m-d');

            $nuevaCitaId = DB::connection('sqlsrv')
                ->table('citas')
                ->insertGetId([
                    'paciente_id' => $cita->paciente_id,
                    'medico_id' => $nuevoTurno->medico_id,
                    'turno_id' => $nuevoTurno->id,
                    'fecha' => $fechaFormateada,
                    'hora_inicio' => $nuevoTurno->hora_inicio,
                    'hora_fin' => $nuevoTurno->hora_fin,
                    'estado' => 1, // Agendado
                    'user_id' => $request->user()->id,
                    'id_sesion' => $cita->id_sesion,
                    'created_at' => DB::raw("GETDATE()"),
                    'updated_at' => DB::raw("GETDATE()")
                ]);

            $nuevaCita = Cita::find($nuevaCitaId);

            // Generar reunión de Teams
            try {
                $paciente = \App\Models\Usuario::find($cita->paciente_id);
                $nombrePaciente = $paciente ? $paciente->nombre_completo : 'Paciente';

                // Combinar fecha y hora usando la zona horaria de Perú
                $timezone = 'America/Lima';

                // Extraer solo la fecha (sin hora) del campo fecha
                $fechaSolo = Carbon::parse($nuevoTurno->fecha)->format('Y-m-d');

                // Extraer solo la hora (sin microsegundos) de hora_inicio y hora_fin
                $horaInicio = substr($nuevoTurno->hora_inicio, 0, 8); // HH:MM:SS
                $horaFin = substr($nuevoTurno->hora_fin, 0, 8); // HH:MM:SS

                // Crear instancias Carbon con la zona horaria local
                $startDateTimeLocal = Carbon::parse($fechaSolo . ' ' . $horaInicio, $timezone);
                $endDateTimeLocal = Carbon::parse($fechaSolo . ' ' . $horaFin, $timezone);

                // Convertir a UTC para Microsoft Graph
                $startDateTime = $startDateTimeLocal->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
                $endDateTime = $endDateTimeLocal->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');

                $subject = "Cita Psicológica (Reprogramada) - " . $nombrePaciente;

                $teamsLink = $graphService->createMeeting($subject, $startDateTime, $endDateTime);

                if ($teamsLink) {
                    $nuevaCita->video_enlace = $teamsLink;
                    $nuevaCita->save();
                    \Illuminate\Support\Facades\Log::info("Enlace Teams generado para cita reprogramada ID {$nuevaCita->id}: {$teamsLink}");

                    // Enviar notificación por correo
                    $notificationService->enviarNotificacionCita($nuevaCita);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Error generando reunión Teams en Protocolo::reprogramar: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Cita reprogramada exitosamente',
                'nueva_cita_id' => $nuevaCita->id,
                'video_enlace' => $nuevaCita->video_enlace
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al reprogramar cita',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Guardar/Registrar sesión
     * POST /api/protocolos/save
     */
    public function save(Request $request)
    {
        try {
            DB::connection('sqlsrv')->beginTransaction();

            $cita = Cita::findOrFail($request->cita_id);

            // Actualizar o Crear SesionUno
            // Si ya existe id_sesion, actualizamos. Si no, creamos.

            $sesion = null;
            if ($cita->id_sesion) {
                $sesion = SesionUno::find($cita->id_sesion);
            }

            if (!$sesion) {
                // Crear nueva sesión
                $sesion = new SesionUno();
                $sesion->paciente_id = $cita->paciente_id;
                $sesion->user_id = $request->user()->id;
                $sesion->fecha_inicio = now_lima();
                $sesion->nro_sesion = 1; // Inicial
            }

            // Actualizar campos recibidos
            $sesion->fill($request->except(['cita_id', 'estado']));
            $sesion->save();

            // Vincular cita con sesión si no lo estaba y actualizar estado
            // Usar Query Builder directamente para evitar problemas con SQL Server y timestamps
            $updateData = [
                'estado' => 2,
                'updated_at' => DB::raw("GETDATE()")
            ];

            if (!$cita->id_sesion) {
                $updateData['id_sesion'] = $sesion->id;
            }

            DB::connection('sqlsrv')
                ->table('citas')
                ->where('id', $cita->id)
                ->update($updateData);

            DB::connection('sqlsrv')->commit();

            return response()->json([
                'success' => true,
                'message' => 'Sesión registrada exitosamente'
            ]);

        } catch (\Exception $e) {
            DB::connection('sqlsrv')->rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar sesión',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Finalizar Intervención (Cierre prematuro o final)
     * POST /api/protocolos/finalizar_intervencion
     *
     * Este método marca la intervención como finalizada.
     * La siguiente cita del paciente será: Intervención N+1, Sesión 1
     */
    public function finalizarIntervencion(Request $request)
    {
        try {
            DB::connection('sqlsrv')->beginTransaction();

            $cita = Cita::findOrFail($request->cita_id);

            // Verificar si ya existe un registro de finalización para esta cita
            $yaFinalizado = \App\Models\CitasFinalizado::where('cita_id', $cita->id)->exists();

            if ($yaFinalizado) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta intervención ya ha sido finalizada'
                ], 422);
            }

            // 1. Marcar cita actual como Atendido (2)
            // Usar Query Builder directamente para evitar problemas con SQL Server y timestamps
            DB::connection('sqlsrv')
                ->table('citas')
                ->where('id', $cita->id)
                ->update([
                    'estado' => 2,
                    'updated_at' => DB::raw("GETDATE()")
                ]);

            // 2. Crear registro de finalización en citas_finalizados
            // Esto marca que la intervención se finalizó en esta cita
            // Usar Query Builder para evitar problemas con SQL Server y timestamps
            DB::connection('sqlsrv')
                ->table('citas_finalizados')
                ->insert([
                    'cita_id' => $cita->id,
                    'paciente_id' => $cita->paciente_id,
                    'observa' => $request->observacion ?? 'Intervención finalizada',
                    'fecha' => DB::raw("GETDATE()"),
                    'user_id' => $request->user()->id
                ]);

            DB::connection('sqlsrv')->commit();

            return response()->json([
                'success' => true,
                'message' => 'Intervención finalizada exitosamente. La próxima cita será una nueva intervención.'
            ]);

        } catch (\Exception $e) {
            DB::connection('sqlsrv')->rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al finalizar intervención',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancelar Cita
     * POST /api/protocolos/cancelar
     */
    public function cancelar(Request $request)
    {
        try {
            DB::connection('sqlsrv')->beginTransaction();

            $cita = Cita::findOrFail($request->cita_id);

            // Usar Query Builder directamente para evitar problemas con SQL Server y timestamps
            DB::connection('sqlsrv')
                ->table('citas')
                ->where('id', $cita->id)
                ->update([
                    'estado' => 4,
                    'updated_at' => DB::raw("GETDATE()")
                ]);

            DB::connection('sqlsrv')->commit();

            return response()->json([
                'success' => true,
                'message' => 'Cita cancelada exitosamente'
            ]);

        } catch (\Exception $e) {
            DB::connection('sqlsrv')->rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al cancelar cita',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marcar como No se Presentó
     * POST /api/protocolos/no_presento
     */
    public function noPresento(Request $request)
    {
        try {
            DB::connection('sqlsrv')->beginTransaction();

            $cita = Cita::findOrFail($request->cita_id);

            // Usar Query Builder directamente para evitar problemas con SQL Server y timestamps
            DB::connection('sqlsrv')
                ->table('citas')
                ->where('id', $cita->id)
                ->update([
                    'estado' => 3,
                    'updated_at' => DB::raw("GETDATE()")
                ]);

            DB::connection('sqlsrv')->commit();

            return response()->json([
                'success' => true,
                'message' => 'Cita marcada como No se presentó'
            ]);

        } catch (\Exception $e) {
            DB::connection('sqlsrv')->rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar cita',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Obtener especialistas para derivación (CENATE)
     * GET /api/protocolos/especialistas-derivacion
     */
    /**
     * Obtener especialistas para derivación
     * GET /api/protocolos/especialistas-derivacion
     */
    public function getEspecialistasDerivacion(Request $request)
    {
        try {
            $citaId = $request->query('cita_id');

            if (!$citaId) {
                 // Default to CENATE if no cita_id provided (or handle differently)
                 $especialistas = \App\Models\Usuario::whereIn('perfil_id', [6, 7])
                    ->select('id', 'nombre_completo')
                    ->get();
                return response()->json(['success' => true, 'data' => $especialistas]);
            }

            $cita = Cita::with('paciente')->find($citaId);
            if (!$cita || !$cita->paciente) {
                return response()->json(['success' => false, 'message' => 'Cita o paciente no encontrado'], 404);
            }

            $paciente = $cita->paciente;
            $cmp = $paciente->cmp;
            $dni = $paciente->nombre_usuario;

            // Verificar si está en plaza remunerada (ESSALUD)
            $esRemunerado = DB::connection('sqlsrv')->table('serumista_remunerados')
                ->where('CMP', $cmp)
                ->orWhere('NumeroDocumento', $dni)
                ->exists();

            // Verificar si está en equivalentes (MINSA)
            $esEquivalente = DB::connection('sqlsrv')->table('serumista_equivalentes_remunerados')
                ->where('CMP', $cmp)
                ->orWhere('NumeroDocumento', $dni)
                ->exists();

    // Lógica de asignación:
            // 1. Si está en remunerados -> ESSALUD (CENATE)
            // 2. Si está en equivalentes -> MINSA (Psicólogo)
            // 3. Si no está en ninguno -> Por defecto MINSA (Psicólogo)

            // NOTA: Los perfiles CENATE (6, 7) han sido dados de baja.
            // Asumimos que todos los especialistas son ahora "Psicologo" (ID 4).
            // La distinción se mantiene en la etiqueta del frontend vía 'es_remunerado'.

            $perfiles = [4]; // Psicologo

            if ($esRemunerado) {
                $isEssalud = true;
            } elseif ($esEquivalente) {
                $isEssalud = false;
            } else {
                $isEssalud = false;
            }

            $especialistas = \App\Models\Usuario::whereIn('perfil_id', $perfiles)
                ->select('id', 'nombre_completo')
                ->where('estado', 1) // Ensure active users
                ->get();

            return response()->json([
                'success' => true,
                'data' => $especialistas,
                'es_remunerado' => $isEssalud // true = ESSALUD, false = MINSA
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener especialistas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener derivación existente
     * GET /api/protocolos/derivacion/{cita_id}
     */
    public function getDerivacion($citaId)
    {
        try {
            $derivacion = \App\Models\Derivado::where('cita_id', $citaId)->first();

            return response()->json([
                'success' => true,
                'data' => $derivacion
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener derivación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Derivar paciente
     * POST /api/protocolos/derivar
     */
    public function derivar(Request $request, \App\Services\NotificationService $notificationService)
    {
        try {
            $validator = Validator::make($request->all(), [
                'cita_id' => 'required|integer',
                'paciente_id' => 'required|integer',
                'especialista_id' => 'required|integer', // cenate_id
                'observacion' => 'nullable|string',
                'accion' => 'required|in:I,U', // Insert or Update
                'derivacion_id' => 'nullable|integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar Riesgo Alto
            if (!$this->checkHighRisk($request->paciente_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El paciente no cumple con los criterios de Riesgo Alto para ser derivado.'
                ], 403);
            }

            DB::connection('sqlsrv')->beginTransaction();

            if ($request->accion == 'U') {
                // Actualizar derivación existente usando Query Builder
                DB::connection('sqlsrv')
                    ->table('derivados')
                    ->where('id', $request->derivacion_id)
                    ->update([
                        'cenate_id' => $request->especialista_id,
                        'observa' => $request->observacion
                    ]);

                $derivacion = \App\Models\Derivado::find($request->derivacion_id);
                $message = 'Derivación actualizada exitosamente';
            } else {
                // Insertar nueva derivación usando Query Builder para evitar problemas con SQL Server
                $derivacionId = DB::connection('sqlsrv')
                    ->table('derivados')
                    ->insertGetId([
                        'cita_id' => $request->cita_id,
                        'paciente_id' => $request->paciente_id,
                        'cenate_id' => $request->especialista_id,
                        'observa' => $request->observacion,
                        'fecha' => DB::raw("GETDATE()"),
                        'tipo' => 'M' // M = Manual (derivado desde atención)
                    ]);

                $derivacion = \App\Models\Derivado::find($derivacionId);
                $message = 'Paciente derivado exitosamente';

                // Actualizar estado de la cita a Derivado (5)
                // Usar Query Builder directamente para evitar problemas con SQL Server y timestamps
                DB::connection('sqlsrv')
                    ->table('citas')
                    ->where('id', $request->cita_id)
                    ->update([
                        'estado' => 5,
                        'updated_at' => DB::raw("GETDATE()")
                    ]);

                // Enviar correo
                $notificationService->enviarNotificacionDerivacion($derivacion);
            }

            DB::connection('sqlsrv')->commit();

            return response()->json([
                'success' => true,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            DB::connection('sqlsrv')->rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al derivar',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar si el paciente tiene Riesgo Alto en alguna evaluación
     */
    private function checkHighRisk($userId)
    {
        // PHQ9
        $phq = DB::connection('sqlsrv')->table('phq9_responses')
            ->where('user_id', $userId)
            ->where('riesgo', 'Riesgo alto')
            ->orderByDesc('id_encuesta')
            ->exists();
        if ($phq) return true;

        // GAD
        $gad = DB::connection('sqlsrv')->table('gad_responses')
            ->where('user_id', $userId)
            ->where('riesgo', 'Riesgo alto')
            ->orderByDesc('id_encuesta')
            ->exists();
        if ($gad) return true;

        // MBI
        $mbi = DB::connection('sqlsrv')->table('mbi_responses')
            ->where('user_id', $userId)
            ->where(function($q) {
                $q->where('riesgoCE', 'Presencia de burnout')
                  ->orWhere('riesgoDP', 'Presencia de burnout')
                  ->orWhere('riesgoRP', 'Presencia de burnout');
            })
            ->orderByDesc('id_encuesta')
            ->exists();
        if ($mbi) return true;

        // AUDIT
        $audit = DB::connection('sqlsrv')->table('audit_responses')
            ->where('user_id', $userId)
            ->whereIn('riesgo', ['Consumo problemático', 'Dependencia', 'Riesgo'])
            ->orderByDesc('id_encuesta')
            ->exists();
        if ($audit) return true;

        // ASQ
        $asq = DB::connection('sqlsrv')->table('asq5_responses')
            ->where('user_id', $userId)
            ->where('resultado', '!=', 'Sin riesgo')
            ->orderByDesc('id')
            ->exists();
        if ($asq) return true;

        return false;
    }

    /**
     * Calcular Intervención y Sesión para una cita basado en regla de negocio
     * Regla: 1 Intervención = hasta 4 sesiones, pero puede finalizarse en cualquier momento
     * Cuando se finaliza, la siguiente cita es: Intervención N+1, Sesión 1
     */
    private function enrichCitaWithInterventionSession($cita)
    {
        $calculo = $this->calcularIntervencionSesion($cita->paciente_id, $cita->fecha, $cita->hora_inicio);

        $cita->numero_intervencion = $calculo['numero_intervencion'];
        $cita->numero_sesion = $calculo['numero_sesion'];
        $cita->numero_cita_global = $calculo['numero_cita_global'];

        return $cita;
    }

    /**
     * Método centralizado para calcular Intervención y Sesión
     * Considera las finalizaciones de intervención (tabla citas_finalizados)
     *
     * @param int $pacienteId ID del paciente
     * @param string|null $fecha Fecha de la cita (null para calcular la próxima)
     * @param string|null $horaInicio Hora de inicio de la cita (null para calcular la próxima)
     * @return array ['numero_intervencion', 'numero_sesion', 'numero_cita_global']
     */
    private function calcularIntervencionSesion($pacienteId, $fecha = null, $horaInicio = null)
    {
        // 1. Si estamos calculando para una cita ESPECÍFICA, necesitamos su ID
        $citaActualId = null;
        $estaCitaFinalizada = false;
        if ($fecha !== null && $horaInicio !== null) {
            $citaActual = Cita::where('paciente_id', $pacienteId)
                ->where('fecha', $fecha)
                ->where('hora_inicio', $horaInicio)
                ->first();

            if ($citaActual) {
                $citaActualId = $citaActual->id;
                // Verificar si esta cita específica está finalizada
                $estaCitaFinalizada = DB::connection('sqlsrv')
                    ->table('citas_finalizados')
                    ->where('cita_id', $citaActualId)
                    ->exists();
            }
        }

        // 2. Obtener todas las citas finalizadas del paciente ANTERIORES a la cita actual
        //    (ordenadas cronológicamente)
        $queryCitasFinalizadas = DB::connection('sqlsrv')
            ->table('citas_finalizados as cf')
            ->join('citas as c', 'c.id', '=', 'cf.cita_id')
            ->where('cf.paciente_id', $pacienteId)
            ->select('c.id', 'c.fecha', 'c.hora_inicio')
            ->orderBy('c.fecha')
            ->orderBy('c.hora_inicio');

        // Si estamos calculando una cita específica, solo contar finalizaciones ANTERIORES
        // NO incluir la finalización de la cita actual si está finalizada
        if ($fecha !== null && $horaInicio !== null) {
            $queryCitasFinalizadas->where(function ($q) use ($fecha, $horaInicio) {
                $q->where('c.fecha', '<', $fecha)
                  ->orWhere(function ($q2) use ($fecha, $horaInicio) {
                      $q2->where('c.fecha', '=', $fecha)
                         ->where('c.hora_inicio', '<', $horaInicio);
                  });
            });
        }

        $citasFinalizadas = $queryCitasFinalizadas->get();

        // 3. Determinar en qué intervención estamos
        // Cada finalización marca el fin de una intervención, entonces la siguiente es N+1
        $numero_intervencion = $citasFinalizadas->count() + 1;

        // Encontrar la última cita finalizada ANTERIOR a esta cita
        $ultima_cita_finalizada = $citasFinalizadas->last();

        // 4. Contar citas en la intervención actual
        $query = Cita::where('paciente_id', $pacienteId)
            ->whereIn('estado', [1, 2]); // Solo agendadas y atendidas

        if ($ultima_cita_finalizada) {
            // Contar solo las citas posteriores a la última finalización
            $query->where(function ($q) use ($ultima_cita_finalizada) {
                $q->where('fecha', '>', $ultima_cita_finalizada->fecha)
                  ->orWhere(function ($q2) use ($ultima_cita_finalizada) {
                      $q2->where('fecha', '=', $ultima_cita_finalizada->fecha)
                         ->where('hora_inicio', '>', $ultima_cita_finalizada->hora_inicio);
                  });
            });
        }

        // Si estamos calculando para una cita específica, incluir solo hasta esa cita
        if ($fecha !== null && $horaInicio !== null) {
            $query->where(function ($q) use ($fecha, $horaInicio) {
                $q->where('fecha', '<', $fecha)
                  ->orWhere(function ($q2) use ($fecha, $horaInicio) {
                      $q2->where('fecha', '=', $fecha)
                         ->where('hora_inicio', '<=', $horaInicio);
                  });
            });
        }

        $citas_en_intervencion_actual = $query->count();

        // 5. El número de sesión
        if ($fecha === null) {
            // Calculando la PRÓXIMA cita
            $numero_sesion = $citas_en_intervencion_actual + 1;
        } else {
            // Calculando una cita específica existente
            // El número de sesión es la posición de esta cita en la intervención actual
            $numero_sesion = $citas_en_intervencion_actual > 0 ? $citas_en_intervencion_actual : 1;
        }

        // 6. Calcular el número global (posición total)
        if ($fecha !== null) {
            $numero_cita_global = Cita::where('paciente_id', $pacienteId)
                ->whereIn('estado', [1, 2])
                ->where(function ($q) use ($fecha, $horaInicio) {
                    $q->where('fecha', '<', $fecha)
                      ->orWhere(function ($q2) use ($fecha, $horaInicio) {
                          $q2->where('fecha', '=', $fecha)
                             ->where('hora_inicio', '<=', $horaInicio);
                      });
                })
                ->count();
        } else {
            // Calculando la próxima
            $numero_cita_global = Cita::where('paciente_id', $pacienteId)
                ->whereIn('estado', [1, 2])
                ->count() + 1;
        }

        return [
            'numero_intervencion' => $numero_intervencion,
            'numero_sesion' => $numero_sesion,
            'numero_cita_global' => $numero_cita_global
        ];
    }

    /**
     * Generar PDF del protocolo de intervención
     * GET /api/protocolos/pdf/{paciente_id}
     */
    public function generarPdf($paciente_id)
    {
        try {
            // Obtener datos de la sesión
            $sesion = SesionUno::where('paciente_id', $paciente_id)->first();

            if (!$sesion) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontraron datos de la sesión'
                ], 404);
            }

            // Obtener datos del paciente
            $paciente = DB::table('usuarios')
                ->where('id', $paciente_id)
                ->select('id', DB::raw("CONCAT(nombre, ' ', apellido) as nombre_completo"), 'cmp as dni')
                ->first();

            $data = [
                'sesion' => $sesion->toArray(),
                'paciente' => $paciente
            ];

            $pdf = Pdf::loadView('pdf.protocolo_intervencion', $data);
            $pdf->setPaper('A4', 'portrait');

            return $pdf->download("protocolo_intervencion_{$paciente_id}.pdf");
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el PDF: ' . $e->getMessage()
            ], 500);
        }
    }
}
