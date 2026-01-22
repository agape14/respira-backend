<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Models\Turno;
use App\Models\Programacionturno;
use App\Models\Usuario;
use App\Models\SesionUno;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class CitaController extends Controller
{
    /**
     * Listar turnos disponibles con filtros
     * GET /api/turnos
     */
    public function index(Request $request)
    {
        try {
            $query = DB::connection('sqlsrv')
                ->table('turnos as t')
                ->leftJoin('citas as c', function($join) {
                    $join->on('t.id', '=', 'c.turno_id')
                         ->where('c.estado', '!=', 3); // 3 = Cancelado (numeric value)
                })
                ->leftJoin('usuarios as u', 't.medico_id', '=', 'u.id')
                ->leftJoin('usuarios as p', 'c.paciente_id', '=', 'p.id')
                ->select(
                    't.id',
                    't.medico_id',
                    't.fecha',
                    't.dia',
                    't.hora_inicio',
                    't.hora_fin',
                    'u.nombre_completo as terapeuta',
                    'p.nombre_completo as paciente',
                    'c.id as cita_id',
                    'c.video_enlace',
                    DB::raw('CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END as agendado'),
                    DB::raw('DATEDIFF(MINUTE, t.hora_inicio, t.hora_fin) as duracion')
                );

            // Filtros
            if ($request->filled('medico_id')) {
                $query->where('t.medico_id', $request->medico_id);
            }

            if ($request->filled('dia')) {
                // Convert day name to number if needed
                $diasSemana = [
                    'Domingo' => 0, 'domingo' => 0,
                    'Lunes' => 1, 'lunes' => 1,
                    'Martes' => 2, 'martes' => 2,
                    'Miércoles' => 3, 'miércoles' => 3, 'Miercoles' => 3, 'miercoles' => 3,
                    'Jueves' => 4, 'jueves' => 4,
                    'Viernes' => 5, 'viernes' => 5,
                    'Sábado' => 6, 'sábado' => 6, 'Sabado' => 6, 'sabado' => 6
                ];

                $diaValue = $request->dia;
                if (isset($diasSemana[$diaValue])) {
                    $diaValue = $diasSemana[$diaValue];
                }

                $query->where('t.dia', $diaValue);
            }

            if ($request->filled('duracion')) {
                $query->whereRaw('DATEDIFF(MINUTE, t.hora_inicio, t.hora_fin) = ?', [$request->duracion]);
            }

            if ($request->filled('estado')) {
                if ($request->estado === 'agendado') {
                    $query->whereNotNull('c.id');
                } elseif ($request->estado === 'disponible') {
                    $query->whereNull('c.id');
                }
            }

            if ($request->filled('fecha_inicio') && $request->filled('fecha_fin')) {
                $query->whereBetween('t.fecha', [$request->fecha_inicio, $request->fecha_fin]);
            }

            // Ordenar
            $query->orderBy('t.fecha', 'desc')
                  ->orderBy('t.hora_inicio', 'asc');

            // Paginación
            $perPage = $request->get('per_page', 10);
            $turnos = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $turnos->items(),
                'total' => $turnos->total(),
                'per_page' => $turnos->perPage(),
                'current_page' => $turnos->currentPage(),
                'last_page' => $turnos->lastPage()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los turnos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Alias for index - Listar turnos
     * GET /api/turnos
     */
    public function turnos(Request $request)
    {
        return $this->index($request);
    }

    /**
     * Programar turnos de terapia
     * POST /api/turnos/programar
     */
    public function programar(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'medico_id' => 'required|integer|exists:sqlsrv.usuarios,id',
                'tiempo_sesion' => 'required|integer|min:15|max:120',
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
                'dias_horarios' => 'required|array|min:1',
                'dias_horarios.*.dia' => 'required|string|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
                'dias_horarios.*.horarios' => 'required|array|min:1',
                'dias_horarios.*.horarios.*.hora_inicio' => 'required|date_format:H:i',
                'dias_horarios.*.horarios.*.hora_fin' => 'required|date_format:H:i|after:dias_horarios.*.horarios.*.hora_inicio',
            ], [
                'medico_id.required' => 'Debe seleccionar un terapeuta',
                'medico_id.exists' => 'El terapeuta seleccionado no existe',
                'tiempo_sesion.required' => 'Debe especificar el tiempo de sesión',
                'fecha_inicio.required' => 'Debe especificar la fecha de inicio',
                'fecha_fin.required' => 'Debe especificar la fecha de fin',
                'fecha_fin.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la fecha de inicio',
                'dias_horarios.required' => 'Debe seleccionar al menos un día',
                'dias_horarios.min' => 'Debe seleccionar al menos un día',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::connection('sqlsrv')->beginTransaction();

            // Generar turnos individuales directamente (como en legacy)
            $turnosCreados = $this->generarTurnos(
                $request->medico_id,
                $request->fecha_inicio,
                $request->fecha_fin,
                $request->tiempo_sesion,
                $request->dias_horarios,
                $request->user() ? $request->user()->id : null
            );

            DB::connection('sqlsrv')->commit();

            return response()->json([
                'success' => true,
                'message' => 'Turnos programados exitosamente',
                'data' => [
                    'turnos_creados' => $turnosCreados
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::connection('sqlsrv')->rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al programar turnos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generar turnos individuales a partir de la programación
     */
    private function generarTurnos($medicoId, $fechaInicio, $fechaFin, $tiempoSesion, $diasHorarios, $userId)
    {
        $turnosCreados = 0;
        $inicio = Carbon::parse($fechaInicio);
        $fin = Carbon::parse($fechaFin);

        // Cast tiempo_sesion to integer
        $tiempoSesion = (int) $tiempoSesion;

        // Mapeo de días en español a números (0 = Domingo, 6 = Sábado)
        $diasSemana = [
            'Domingo' => 0,
            'Lunes' => 1,
            'Martes' => 2,
            'Miércoles' => 3,
            'Jueves' => 4,
            'Viernes' => 5,
            'Sábado' => 6
        ];

        // Crear un array de los días seleccionados
        $diasSeleccionados = [];
        foreach ($diasHorarios as $diaHorario) {
            $diaIndex = $diasSemana[$diaHorario['dia']];
            if (!isset($diasSeleccionados[$diaIndex])) {
                $diasSeleccionados[$diaIndex] = [];
            }
            $diasSeleccionados[$diaIndex] = array_merge($diasSeleccionados[$diaIndex], $diaHorario['horarios']);
        }

        // Iterar sobre cada día en el rango de fechas
        for ($fecha = clone $inicio; $fecha->lte($fin); $fecha->addDay()) {
            $numeroDia = $fecha->dayOfWeek;

            // Si este día está en los días seleccionados
            if (isset($diasSeleccionados[$numeroDia])) {
                $horarios = $diasSeleccionados[$numeroDia];

                foreach ($horarios as $horario) {
                    $horaInicio = Carbon::parse($horario['hora_inicio']);
                    $horaFin = Carbon::parse($horario['hora_fin']);

                    // Generar turnos según el tiempo de sesión
                    $horaActual = clone $horaInicio;
                    while ($horaActual->lt($horaFin)) {
                        $siguienteHora = (clone $horaActual)->addMinutes($tiempoSesion);

                        if ($siguienteHora->lte($horaFin)) {
                            // Check if shift already exists to prevent duplicates
                            $exists = Turno::where('medico_id', $medicoId)
                                ->where('fecha', $fecha->format('Y-m-d'))
                                ->where(function ($query) use ($horaActual, $siguienteHora) {
                                    $query->where('hora_inicio', '<', $siguienteHora->format('H:i:s'))
                                          ->where('hora_fin', '>', $horaActual->format('H:i:s'));
                                })
                                ->exists();

                            if (!$exists) {
                                Turno::create([
                                    'medico_id' => $medicoId,
                                    'fecha' => $fecha->format('Y-m-d'),
                                    'dia' => $numeroDia, // Store day number (0-6) instead of name
                                    'hora_inicio' => $horaActual->format('H:i:s'),
                                    'hora_fin' => $siguienteHora->format('H:i:s'),
                                    'user_id' => $userId
                                ]);

                                $turnosCreados++;
                            }
                        }

                        $horaActual = $siguienteHora;
                    }
                }
            }
        }

        return $turnosCreados;
    }

    /**
     * Obtener datos para vista de calendario
     * GET /api/turnos/calendario
     */
    public function calendario(Request $request)
    {
        try {
            $year = $request->get('year', now_lima()->year);
            $month = $request->get('month', now_lima()->month);
            $medicoId = $request->get('medico_id');
            $pacienteId = $request->get('paciente_id');

            $primerDia = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Lima')->startOfMonth();
            $ultimoDia = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Lima')->endOfMonth();
            $fechaHoy = now_lima()->format('Y-m-d');

            $query = DB::connection('sqlsrv')
                ->table('turnos as t')
                ->leftJoin('citas as c', function($join) {
                    $join->on('t.id', '=', 'c.turno_id')
                         ->where('c.estado', '!=', 3); // 3 = Cancelado (numeric)
                })
                ->leftJoin('usuarios as u', 't.medico_id', '=', 'u.id')
                ->leftJoin('usuarios as p', 'c.paciente_id', '=', 'p.id')
                ->select(
                    't.id',
                    't.medico_id',
                    't.fecha',
                    't.hora_inicio',
                    't.hora_fin',
                    'u.nombre_completo as terapeuta',
                    'c.paciente_id',
                    'p.nombre_completo as paciente_nombre',
                    'c.video_enlace',
                    DB::raw('CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END as agendado')
                )
                ->whereBetween('t.fecha', [$primerDia->format('Y-m-d'), $ultimoDia->format('Y-m-d')]);

            if ($medicoId && $medicoId !== 'todos') {
                $query->where('t.medico_id', $medicoId);
            }

            if ($pacienteId && $pacienteId !== 'todos') {
                $query->where('c.paciente_id', $pacienteId);
            }

            $turnos = $query->orderBy('t.fecha')
                           ->orderBy('t.hora_inicio')
                           ->get();

            // Agrupar por fecha
            $turnosPorFecha = $turnos->groupBy('fecha');

            // Estadísticas
            $turnosHoy = $turnos->where('fecha', $fechaHoy);
            $estadisticas = [
                'total' => $turnos->count(),
                'agendados' => $turnos->where('agendado', 1)->count(),
                'disponibles' => $turnos->where('agendado', 0)->count(),
                'dia_actual' => $turnosHoy->count(),
                'dia_actual_agendados' => $turnosHoy->where('agendado', 1)->count(),
                'dia_actual_disponibles' => $turnosHoy->where('agendado', 0)->count()
            ];

            return response()->json([
                'success' => true,
                'data' => $turnosPorFecha,
                'estadisticas' => $estadisticas
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener datos del calendario',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener lista de terapeutas (psicólogos)
     */
    public function terapeutas()
    {
        try {
            // Lógica portada de legacy (saludmental):
            // PERFILMEDICOS = 4,5,10 (4=Especialista Psicólogo antiguo, 5=Especialista General, 10=Psicólogo nuevo)
            // Devuelve todos los terapeutas activos (con o sin turnos)
            $terapeutas = Usuario::whereIn('perfil_id', [4, 5, 10])
                ->where('estado', 1) // Solo usuarios activos
                ->select('id', 'nombre_completo')
                ->distinct()
                ->orderBy('nombre_completo')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $terapeutas
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener terapeutas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un turno
     * DELETE /api/turnos/{id}
     */
    public function destroy($id)
    {
        try {
            $turno = Turno::findOrFail($id);

            // Verificar si el turno tiene una cita agendada
            $tieneCita = Cita::where('turno_id', $id)
                ->where('estado', '!=', 3) // 3 = Cancelado (numeric value)
                ->exists();

            if ($tieneCita) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar un turno que tiene una cita agendada'
                ], 422);
            }

            $turno->delete();

            return response()->json([
                'success' => true,
                'message' => 'Turno eliminado exitosamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el turno',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener conteo de programaciones disponibles para eliminar
     * GET /api/turnos/contar-disponibles
     */
    public function contarDisponibles(Request $request)
    {
        try {
            $year = $request->get('year');
            $month = $request->get('month');
            $medicoId = $request->get('medico_id');

            $query = DB::connection('sqlsrv')
                ->table('turnos as t')
                ->leftJoin('citas as c', function($join) {
                    $join->on('t.id', '=', 'c.turno_id')
                         ->where('c.estado', '!=', 3); // 3 = Cancelado
                })
                ->whereNull('c.id'); // Solo turnos sin citas agendadas

            // Aplicar filtros si se proporcionan
            if ($year && $month) {
                $primerDia = Carbon::create($year, $month, 1)->startOfMonth();
                $ultimoDia = Carbon::create($year, $month, 1)->endOfMonth();
                $query->whereBetween('t.fecha', [$primerDia->format('Y-m-d'), $ultimoDia->format('Y-m-d')]);
            }

            if ($medicoId && $medicoId !== 'todos') {
                $query->where('t.medico_id', $medicoId);
            }

            $conteo = $query->count();

            return response()->json([
                'success' => true,
                'conteo' => $conteo
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al contar programaciones',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar todas las programaciones (turnos sin citas agendadas)
     * DELETE /api/turnos/eliminar-todos
     */
    public function eliminarTodos(Request $request)
    {
        try {
            $year = $request->get('year');
            $month = $request->get('month');
            $medicoId = $request->get('medico_id');

            $query = DB::connection('sqlsrv')
                ->table('turnos as t')
                ->leftJoin('citas as c', function($join) {
                    $join->on('t.id', '=', 'c.turno_id')
                         ->where('c.estado', '!=', 3); // 3 = Cancelado
                })
                ->whereNull('c.id'); // Solo turnos sin citas agendadas

            // Aplicar filtros si se proporcionan
            if ($year && $month) {
                $primerDia = Carbon::create($year, $month, 1)->startOfMonth();
                $ultimoDia = Carbon::create($year, $month, 1)->endOfMonth();
                $query->whereBetween('t.fecha', [$primerDia->format('Y-m-d'), $ultimoDia->format('Y-m-d')]);
            }

            if ($medicoId && $medicoId !== 'todos') {
                $query->where('t.medico_id', $medicoId);
            }

            // Obtener IDs de turnos a eliminar
            $turnosAEliminar = $query->pluck('t.id');

            if ($turnosAEliminar->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No hay programaciones disponibles para eliminar',
                    'eliminados' => 0
                ]);
            }

            // Eliminar los turnos
            $eliminados = DB::connection('sqlsrv')
                ->table('turnos')
                ->whereIn('id', $turnosAEliminar)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => "Se eliminaron {$eliminados} programaciones exitosamente",
                'eliminados' => $eliminados
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar las programaciones',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar citas agendadas
     * GET /api/citas
     */
    public function citas(Request $request)
    {
        try {
            $user = $request->user();

            $query = DB::connection('sqlsrv')
                ->table('citas as c')
                ->join('turnos as t', 'c.turno_id', '=', 't.id')
                ->join('usuarios as medico', 't.medico_id', '=', 'medico.id')
                ->join('usuarios as paciente', 'c.paciente_id', '=', 'paciente.id')
                ->select(
                    'c.id',
                    'c.fecha',
                    'c.hora_inicio',
                    'c.hora_fin',
                    'c.estado',
                    'c.video_enlace',
                    'medico.nombre_completo as terapeuta',
                    'paciente.nombre_completo as paciente',
                    'paciente.cmp as paciente_cmp'
                );

            // Filtro automático por perfil: Si es psicólogo, solo ver sus citas
            // Perfiles de psicólogos: verificar en la tabla perfiles el nombre_perfil que contenga "Psicólogo" o "Psicologo"
            if ($user && $user->perfil) {
                $nombrePerfil = strtolower($user->perfil->nombre_perfil ?? '');
                // Si el perfil contiene "psicologo" o "psicólogo", filtrar por sus citas
                if (strpos($nombrePerfil, 'psicologo') !== false || strpos($nombrePerfil, 'psicólogo') !== false) {
                    $query->where('t.medico_id', $user->id);
                }
            }

            // Filtros
            if ($request->filled('medico_id')) {
                $query->where('t.medico_id', $request->medico_id);
            }

            if ($request->filled('paciente_id')) {
                $query->where('c.paciente_id', $request->paciente_id);
            }

            if ($request->filled('estado')) {
                $query->where('c.estado', $request->estado);
            }

            if ($request->filled('fecha_inicio') && $request->filled('fecha_fin')) {
                $query->whereBetween('c.fecha', [$request->fecha_inicio, $request->fecha_fin]);
            }

            $citas = $query->orderBy('c.fecha', 'desc')
                          ->orderBy('c.hora_inicio', 'asc')
                          ->paginate($request->get('per_page', 15));

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
                'message' => 'Error al obtener las citas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Agendar una cita en un turno disponible
     * POST /api/citas/agendar
     */
    /**
     * Agendar una cita en un turno disponible
     * POST /api/citas/agendar
     */
    public function agendar(Request $request, \App\Services\MicrosoftGraphService $graphService, \App\Services\NotificationService $notificationService)
    {
        try {
            $validator = Validator::make($request->all(), [
                'turno_id' => 'required|integer|exists:sqlsrv.turnos,id',
                'paciente_id' => 'required|integer|exists:sqlsrv.usuarios,id',
                'video_enlace' => 'nullable|url',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Obtener información del turno
            $turno = Turno::findOrFail($request->turno_id);

            // Verificar que el turno no esté ocupado
            $citaExistente = Cita::where('turno_id', $request->turno_id)
                ->where('estado', '!=', 3) // 3 = Cancelado (numeric)
                ->first();

            if ($citaExistente) {
                return response()->json([
                    'success' => false,
                    'message' => 'El turno ya está ocupado'
                ], 422);
            }

            // Verificar que el paciente no tenga otra cita en ese horario (solapamiento)
            $citaSolapada = Cita::where('paciente_id', $request->paciente_id)
                ->where('fecha', $turno->fecha)
                ->where('estado', '!=', 3) // Ignorar canceladas
                ->where(function ($query) use ($turno) {
                    $query->where('hora_inicio', '<', $turno->hora_fin)
                          ->where('hora_fin', '>', $turno->hora_inicio);
                })
                ->first();

            if ($citaSolapada) {
                return response()->json([
                    'success' => false,
                    'message' => 'El paciente ya tiene una cita agendada en este horario'
                ], 422);
            }

            // Crear la cita - Formatear correctamente los datos para SQL Server
            \Illuminate\Support\Facades\Log::info('CitaController::agendar - Iniciando creación de cita', [
                'turno_id' => $request->turno_id,
                'paciente_id' => $request->paciente_id,
                'turno_fecha_raw' => $turno->fecha,
                'turno_hora_inicio_raw' => $turno->hora_inicio,
                'turno_hora_fin_raw' => $turno->hora_fin,
                'turno_hora_inicio_type' => gettype($turno->hora_inicio),
                'turno_hora_fin_type' => gettype($turno->hora_fin),
            ]);

            $fechaFormateada = Carbon::parse($turno->fecha)->format('Y-m-d');

            // Formatear horas: extraer solo HH:MM:SS (sin microsegundos)
            $horaInicioFormateada = is_string($turno->hora_inicio)
                ? substr($turno->hora_inicio, 0, 8)
                : Carbon::parse($turno->hora_inicio)->format('H:i:s');

            $horaFinFormateada = is_string($turno->hora_fin)
                ? substr($turno->hora_fin, 0, 8)
                : Carbon::parse($turno->hora_fin)->format('H:i:s');

            // Obtener timestamp actual formateado correctamente para SQL Server
            $now = Carbon::now('America/Lima')->format('Y-m-d H:i:s');

            \Illuminate\Support\Facades\Log::info('CitaController::agendar - Valores formateados', [
                'fecha_formateada' => $fechaFormateada,
                'hora_inicio_formateada' => $horaInicioFormateada,
                'hora_fin_formateada' => $horaFinFormateada,
                'now' => $now,
                'medico_id' => $turno->medico_id,
                'user_id' => $request->user()->id,
            ]);

            // Usar SQL directo con parámetros preparados y CONVERT para forzar tipos correctos
            // SQL Server necesita que los valores estén explícitamente convertidos
            try {
                \Illuminate\Support\Facades\Log::info('CitaController::agendar - Creando cita con SQL directo y CONVERT');

                // Usar SQL directo con CONVERT para asegurar que los valores se inserten correctamente
                // CONVERT(tipo, valor, formato) - formato 23 para DATE, 108 para TIME, 120 para DATETIME
                $sql = "
                    INSERT INTO [citas]
                    ([paciente_id], [medico_id], [turno_id], [fecha], [hora_inicio], [hora_fin], [video_enlace], [estado], [user_id], [created_at], [updated_at])
                    OUTPUT INSERTED.id
                    VALUES (?, ?, ?, CONVERT(DATE, ?, 23), CONVERT(TIME, ?, 108), CONVERT(TIME, ?, 108), ?, ?, ?, CONVERT(DATETIME, ?, 120), CONVERT(DATETIME, ?, 120))
                ";

                $result = DB::connection('sqlsrv')->select($sql, [
                    (int)$request->paciente_id,
                    (int)$turno->medico_id,
                    (int)$request->turno_id,
                    $fechaFormateada, // '2026-01-14'
                    $horaInicioFormateada, // '08:00:00'
                    $horaFinFormateada, // '09:00:00'
                    $request->video_enlace,
                    1, // estado
                    (int)$request->user()->id,
                    $now, // '2026-01-14 16:31:58'
                    $now, // '2026-01-14 16:31:58'
                ]);

                $citaId = $result[0]->id ?? null;

                if (!$citaId) {
                    \Illuminate\Support\Facades\Log::error('CitaController::agendar - No se pudo obtener el ID de la cita');
                    throw new \Exception('No se pudo obtener el ID de la cita insertada');
                }

                \Illuminate\Support\Facades\Log::info('CitaController::agendar - Cita creada exitosamente con SQL directo', [
                    'cita_id' => $citaId,
                ]);

                // Cargar la cita usando Eloquent para tener acceso a las relaciones
                $cita = Cita::find($citaId);

            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('CitaController::agendar - Error en INSERT', [
                    'error_message' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                    'error_trace' => $e->getTraceAsString(),
                    'valores_intentados' => [
                        'paciente_id' => $request->paciente_id,
                        'medico_id' => $turno->medico_id,
                        'turno_id' => $request->turno_id,
                        'fecha' => $fechaFormateada,
                        'hora_inicio' => $horaInicioFormateada,
                        'hora_fin' => $horaFinFormateada,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ]);
                throw $e;
            }

            // La cita ya está disponible en $cita desde el try block
            // Recargar para asegurar que tenga todas las relaciones disponibles
            $cita->refresh();

            // Generar reunión de Teams si no se proporcionó un enlace manual
            if (empty($cita->video_enlace)) {
                try {
                    $paciente = Usuario::find($request->paciente_id);
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

                // Intentar crear reunión (usando el método que funcionó en las pruebas)
                $teamsLink = $graphService->createMeeting($subject, $startDateTime, $endDateTime);

                    if ($teamsLink) {
                        // Actualizar usando SQL directo con CONVERT para evitar problemas con updated_at
                        $now = Carbon::now('America/Lima')->format('Y-m-d H:i:s');

                        \Illuminate\Support\Facades\Log::info("CitaController::agendar - Actualizando video_enlace", [
                            'cita_id' => $cita->id,
                            'teams_link' => $teamsLink,
                            'now' => $now,
                        ]);

                        try {
                            $rowsAffected = DB::connection('sqlsrv')->update(
                                "UPDATE [citas] SET [video_enlace] = ?, [updated_at] = CONVERT(DATETIME, ?, 120) WHERE [id] = ?",
                                [$teamsLink, $now, $cita->id]
                            );

                            \Illuminate\Support\Facades\Log::info("CitaController::agendar - UPDATE ejecutado", [
                                'cita_id' => $cita->id,
                                'rows_affected' => $rowsAffected,
                            ]);

                            // Verificar que se actualizó correctamente
                            $citaActualizada = DB::connection('sqlsrv')->table('citas')
                                ->where('id', $cita->id)
                                ->select('video_enlace', 'updated_at')
                                ->first();

                            \Illuminate\Support\Facades\Log::info("CitaController::agendar - Verificación después de UPDATE", [
                                'cita_id' => $cita->id,
                                'video_enlace_en_db' => $citaActualizada->video_enlace ?? 'NULL',
                                'updated_at_en_db' => $citaActualizada->updated_at ?? 'NULL',
                            ]);

                        } catch (\Exception $updateException) {
                            \Illuminate\Support\Facades\Log::error("CitaController::agendar - Error en UPDATE de video_enlace", [
                                'cita_id' => $cita->id,
                                'error' => $updateException->getMessage(),
                                'trace' => $updateException->getTraceAsString(),
                            ]);
                            throw $updateException;
                        }

                        // Actualizar el objeto en memoria
                        $cita->video_enlace = $teamsLink;
                        $cita->updated_at = Carbon::parse($now);

                        \Illuminate\Support\Facades\Log::info("Enlace Teams generado para cita ID {$cita->id}: {$teamsLink}");

                        // Enviar notificación por correo
                        $notificationService->enviarNotificacionCita($cita);
                    } else {
                        \Illuminate\Support\Facades\Log::warning("No se pudo generar enlace Teams para cita ID {$cita->id}");
                    }
                } catch (\Exception $e) {
                    // Log error but don't fail the request
                    \Illuminate\Support\Facades\Log::error('Error generando reunión Teams en agendar: ' . $e->getMessage());
                }
            } else {
                // Si se proporcionó enlace manual, también enviar correo
                $notificationService->enviarNotificacionCita($cita);
            }

            return response()->json([
                'success' => true,
                'message' => 'Cita agendada exitosamente',
                'data' => $cita
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al agendar la cita',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener citas de pacientes con Riesgo Moderado
     */
    public function indexRiesgoModerado()
    {
        $citas = Cita::with(['paciente', 'medico', 'turno'])
            ->whereHas('paciente', function ($query) {
                $query->whereHas('phq9Responses', function ($q) {
                    $q->where('riesgo', 'like', '%Moderado%');
                })->orWhereHas('gadResponses', function ($q) {
                    $q->where('riesgo', 'like', '%Moderado%');
                });
            })
            ->orderBy('fecha', 'asc')
            ->orderBy('hora_inicio', 'asc')
            ->get();

        return response()->json($citas);
    }

    /**
     * Obtener lista de pacientes con Riesgo Moderado
     *
     * Regla de negocio:
     * - NO listar pacientes cuya última cita esté finalizada o derivada
     * - VOLVER a listarlos solo si tienen un nuevo resultado de tamizaje posterior
     */
    public function pacientesRiesgoModerado()
    {
        try {
            // Incluir pacientes con riesgo Leve, Moderado o Alto
            $pacientes = Usuario::where(function ($query) {
                    $query->whereHas('phq9Responses', function ($q) {
                        $q->where(function ($q2) {
                            $q2->where('riesgo', 'like', '%leve%')
                               ->orWhere('riesgo', 'like', '%Moderado%')
                               ->orWhere('riesgo', 'like', '%alto%');
                        });
                    })
                    ->orWhereHas('gadResponses', function ($q) {
                        $q->where(function ($q2) {
                            $q2->where('riesgo', 'like', '%leve%')
                               ->orWhere('riesgo', 'like', '%Moderado%')
                               ->orWhere('riesgo', 'like', '%alto%');
                        });
                    });
                })
                ->select('id', 'nombre_completo', 'cmp')
                ->orderBy('nombre_completo')
                ->get();

            // Filtrar pacientes según regla de negocio
            $pacientesFiltrados = $pacientes->filter(function ($paciente) {
                // Obtener la última cita del paciente
                $ultimaCita = Cita::where('paciente_id', $paciente->id)
                    ->orderBy('fecha', 'desc')
                    ->orderBy('hora_inicio', 'desc')
                    ->first();

                if (!$ultimaCita) {
                    // No tiene citas, puede agendar
                    return true;
                }

                // Verificar si la última cita está finalizada o derivada
                $estaFinalizada = \App\Models\CitasFinalizado::where('cita_id', $ultimaCita->id)->exists();
                $estaDerivada = \App\Models\Derivado::where('cita_id', $ultimaCita->id)->exists();

                if (!$estaFinalizada && !$estaDerivada) {
                    // No está finalizada ni derivada, puede agendar
                    return true;
                }

                // Está finalizada o derivada: verificar si hay nuevo tamizaje después
                // Convertir fecha a string para comparación SQL
                $fechaUltimaCita = $ultimaCita->fecha instanceof \Carbon\Carbon
                    ? $ultimaCita->fecha->format('Y-m-d')
                    : $ultimaCita->fecha;

                // Buscar evaluación más reciente posterior a la última cita
                $tieneNuevoTamizaje =
                    // PHQ9
                    DB::connection('sqlsrv')->table('phq9_responses')
                        ->where('user_id', $paciente->id)
                        ->where('fecha', '>', $fechaUltimaCita)
                        ->exists()
                    ||
                    // GAD
                    DB::connection('sqlsrv')->table('gad_responses')
                        ->where('user_id', $paciente->id)
                        ->where('fecha', '>', $fechaUltimaCita)
                        ->exists()
                    ||
                    // MBI
                    DB::connection('sqlsrv')->table('mbi_responses')
                        ->where('user_id', $paciente->id)
                        ->where('fecha', '>', $fechaUltimaCita)
                        ->exists()
                    ||
                    // AUDIT
                    DB::connection('sqlsrv')->table('audit_responses')
                        ->where('user_id', $paciente->id)
                        ->where('fecha', '>', $fechaUltimaCita)
                        ->exists();

                // Solo listar si tiene nuevo tamizaje
                return $tieneNuevoTamizaje;
            });

            return response()->json([
                'success' => true,
                'data' => $pacientesFiltrados->values()
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
     * Obtener número de intervención y sesión para un paciente
     * GET /api/citas/intervencion-sesion/{paciente_id}
     *
     * Regla de negocio:
     * - 1 Intervención puede tener hasta 4 sesiones
     * - Una intervención puede finalizarse en cualquier sesión (1-4)
     * - Al finalizar, la siguiente cita será: Intervención N+1, Sesión 1
     */
    public function obtenerIntervencionSesion($pacienteId)
    {
        try {
            // 1. Contar cuántas intervenciones ha finalizado el paciente
            $intervenciones_finalizadas = DB::connection('sqlsrv')
                ->table('citas_finalizados')
                ->where('paciente_id', $pacienteId)
                ->distinct()
                ->count('id');

            // 2. El número de intervención para la PRÓXIMA cita es: finalizadas + 1
            $numeroIntervencion = $intervenciones_finalizadas + 1;

            // 3. Encontrar la última cita finalizada (si existe)
            $ultima_cita_finalizada = DB::connection('sqlsrv')
                ->table('citas_finalizados as cf')
                ->join('citas as c', 'c.id', '=', 'cf.cita_id')
                ->where('cf.paciente_id', $pacienteId)
                ->select('c.fecha', 'c.hora_inicio', 'c.id')
                ->orderByDesc('c.fecha')
                ->orderByDesc('c.hora_inicio')
                ->first();

            // 4. Contar citas desde la última finalización (o desde el inicio)
            $query = Cita::where('paciente_id', $pacienteId)
                ->whereIn('estado', [1, 2]); // Solo pendientes y atendidas

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

            $citas_en_intervencion_actual = $query->count();

            // 5. Verificar si hay alguna cita pendiente (estado 1)
            $citaPendiente = Cita::where('paciente_id', $pacienteId)
                ->where('estado', 1)
                ->orderBy('fecha', 'asc')
                ->orderBy('hora_inicio', 'asc')
                ->first();

            // 6. Calcular el número de intervención y sesión
            // Si hay cita pendiente: mostrar el número de ESA cita (no la siguiente)
            // Si NO hay cita pendiente: mostrar el número de la PRÓXIMA cita
            $numeroSesion = null;
            $numeroIntervencionCalculado = $numeroIntervencion;

            if ($citaPendiente) {
                // Hay cita pendiente: calcular el número de ESA cita específica
                $citasAntesDeEsta = Cita::where('paciente_id', $pacienteId)
                    ->whereIn('estado', [1, 2])
                    ->where(function ($q) use ($citaPendiente, $ultima_cita_finalizada) {
                        if ($ultima_cita_finalizada) {
                            // Contar desde la última finalización
                            $q->where(function ($q2) use ($ultima_cita_finalizada) {
                                $q2->where('fecha', '>', $ultima_cita_finalizada->fecha)
                                   ->orWhere(function ($q3) use ($ultima_cita_finalizada) {
                                       $q3->where('fecha', '=', $ultima_cita_finalizada->fecha)
                                          ->where('hora_inicio', '>', $ultima_cita_finalizada->hora_inicio);
                                   });
                            });
                        }
                        // Y que sea anterior o igual a la cita pendiente
                        $q->where(function ($q2) use ($citaPendiente) {
                            $q2->where('fecha', '<', $citaPendiente->fecha)
                               ->orWhere(function ($q3) use ($citaPendiente) {
                                   $q3->where('fecha', '=', $citaPendiente->fecha)
                                      ->where('hora_inicio', '<=', $citaPendiente->hora_inicio);
                               });
                        });
                    })
                    ->count();

                $numeroSesion = $citasAntesDeEsta;
            } else {
                // No hay cita pendiente: calcular la próxima
                $numeroSesion = $citas_en_intervencion_actual + 1;
            }

            // 7. Validar límite de sesiones (máximo 4 por intervención)
            $limite_alcanzado = false;
            $debe_finalizar = false;
            $mensaje_validacion = null;

            if ($numeroSesion > 4) {
                // Si la sesión calculada es mayor a 4, significa que debe finalizar la intervención
                $limite_alcanzado = true;
                $debe_finalizar = true;
                $mensaje_validacion = "La intervención actual ya tiene 4 sesiones. Debe finalizar la intervención antes de agendar una nueva cita. La próxima será: Intervención " . ($numeroIntervencionCalculado + 1) . ", Sesión 1";

                // No mostrar número de sesión inválido
                $numeroSesion = null;
                $numeroIntervencionCalculado = null;
            }

            $numeroIntervencion = $numeroIntervencionCalculado;

            // 8. Obtener la sesión activa (última sesión con fecha_inicio dentro de los últimos 3 meses)
            $fechaLimite = now_lima()->subMonths(3)->format('Y-m-d');
            $sesionActiva = DB::connection('sqlsrv')
                ->table('sesionUno')
                ->where('paciente_id', $pacienteId)
                ->where('fecha_inicio', '>=', $fechaLimite)
                ->orderBy('fecha_inicio', 'desc')
                ->first();

            // 9. Total de citas del paciente
            $totalCitas = Cita::where('paciente_id', $pacienteId)
                ->whereIn('estado', [1, 2])
                ->count();

            // 10. Total de sesiones registradas
            $totalSesiones = DB::connection('sqlsrv')
                ->table('sesionUno')
                ->where('paciente_id', $pacienteId)
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'numero_intervencion' => $numeroIntervencion,
                    'numero_sesion' => $numeroSesion,
                    'sesion_activa' => $sesionActiva ? $sesionActiva->id : null,
                    'total_citas' => $totalCitas,
                    'total_sesiones' => $totalSesiones,
                    'intervenciones_finalizadas' => $intervenciones_finalizadas,
                    'cita_pendiente' => $citaPendiente ? true : false,
                    'cita_pendiente_info' => $citaPendiente,
                    'limite_alcanzado' => $limite_alcanzado,
                    'debe_finalizar' => $debe_finalizar,
                    'mensaje_validacion' => $mensaje_validacion
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al calcular intervención y sesión',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Probar generación de enlace Teams
     * GET /api/test-teams-link
     */
    public function testTeamsLink(Request $request, \App\Services\MicrosoftGraphService $graphService)
    {
        try {
            $subject = $request->input('subject', 'Prueba de Cita Teams');

            // Usar fecha/hora actual + 1 hora por defecto
            $startDateTime = $request->input('start_time', now_lima()->addHour()->format('Y-m-d\TH:i:s'));
            $endDateTime = $request->input('end_time', now_lima()->addHours(2)->format('Y-m-d\TH:i:s'));

            // Asegurar formato UTC si no viene así
            if (!str_ends_with($startDateTime, 'Z')) $startDateTime .= 'Z';
            if (!str_ends_with($endDateTime, 'Z')) $endDateTime .= 'Z';

            $results = [];

            // Intento 1: Crear evento en calendario (requiere Calendars.ReadWrite)
            $calendarLink = $graphService->createMeeting($subject, $startDateTime, $endDateTime);
            $results['calendar_method'] = [
                'success' => !empty($calendarLink),
                'link' => $calendarLink,
                'note' => empty($calendarLink) ? 'Revise logs para error 403/400' : 'OK'
            ];

            // Intento 2: Crear reunión online directa (requiere OnlineMeetings.ReadWrite.All)
            $directLink = $graphService->createOnlineMeeting($subject, $startDateTime, $endDateTime);
            $results['direct_method'] = [
                'success' => !empty($directLink),
                'link' => $directLink,
                'note' => empty($directLink) ? 'Revise logs para error' : 'OK'
            ];

            $success = !empty($calendarLink) || !empty($directLink);
            $finalLink = $calendarLink ?? $directLink;

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Enlace generado exitosamente',
                    'video_enlace' => $finalLink,
                    'methods_tested' => $results,
                    'debug_info' => [
                        'subject' => $subject,
                        'start' => $startDateTime,
                        'end' => $endDateTime
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo generar el enlace con ningún método.',
                    'methods_tested' => $results,
                    'debug_info' => [
                        'subject' => $subject,
                        'start' => $startDateTime,
                        'end' => $endDateTime
                    ]
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Excepción al generar enlace',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Endpoint de prueba para envío de correos
     * GET /api/test-email
     */
    public function testEmail(Request $request)
    {
        try {
            $emailDestino = env('MAIL_REDIRECT_TO') ?? 'ti05@cmp.org.pe';

            $data = [
                'nombrePaciente' => 'Paciente de Prueba',
                'fecha' => date('Y-m-d'),
                'horaInicio' => '10:00',
                'horaFin' => '11:00',
                'nombreTerapeuta' => 'Terapeuta de Prueba',
                'videoEnlace' => 'https://teams.microsoft.com/test-link'
            ];

            \Illuminate\Support\Facades\Mail::to($emailDestino)->send(new \App\Mail\CitaProgramadaMailable($data));

            return response()->json([
                'success' => true,
                'message' => "Correo de prueba enviado a {$emailDestino}",
                'config' => [
                    'mailer' => env('MAIL_MAILER'),
                    'host' => env('MAIL_HOST'),
                    'port' => env('MAIL_PORT'),
                    'username' => env('MAIL_USERNAME'),
                    'encryption' => env('MAIL_ENCRYPTION'),
                    'from_address' => env('MAIL_FROM_ADDRESS'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar correo',
                'error' => $e->getMessage(),
                'config' => [
                    'host' => env('MAIL_HOST'),
                    'port' => env('MAIL_PORT'),
                    'encryption' => env('MAIL_ENCRYPTION'),
                ]
            ], 500);
        }
    }

    /**
     * Actualizar el enlace de reunión virtual de una cita
     * PUT/PATCH /api/citas/{cita_id}/video-enlace
     */
    public function actualizarVideoEnlace(Request $request, $citaId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'video_enlace' => 'required|url',
            ], [
                'video_enlace.required' => 'El enlace de la reunión virtual es obligatorio',
                'video_enlace.url' => 'El enlace debe ser una URL válida',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Buscar la cita
            $cita = Cita::find($citaId);

            if (!$cita) {
                return response()->json([
                    'success' => false,
                    'message' => 'La cita no existe'
                ], 404);
            }

            // Verificar que la cita tenga un turno asignado (esté agendada)
            $turno = Turno::find($cita->turno_id);
            if (!$turno) {
                return response()->json([
                    'success' => false,
                    'message' => 'La cita no tiene un turno asignado'
                ], 422);
            }

            // Actualizar usando SQL directo para evitar problemas con updated_at
            $now = Carbon::now('America/Lima')->format('Y-m-d H:i:s');
            $videoEnlace = $request->video_enlace;

            \Illuminate\Support\Facades\Log::info("CitaController::actualizarVideoEnlace - Actualizando enlace", [
                'cita_id' => $citaId,
                'video_enlace' => $videoEnlace,
                'now' => $now,
            ]);

            try {
                $rowsAffected = DB::connection('sqlsrv')->update(
                    "UPDATE [citas] SET [video_enlace] = ?, [updated_at] = CONVERT(DATETIME, ?, 120) WHERE [id] = ?",
                    [$videoEnlace, $now, $citaId]
                );

                if ($rowsAffected === 0) {
                    \Illuminate\Support\Facades\Log::warning("CitaController::actualizarVideoEnlace - No se actualizó ninguna fila", [
                        'cita_id' => $citaId,
                    ]);
                }

                \Illuminate\Support\Facades\Log::info("CitaController::actualizarVideoEnlace - Enlace actualizado exitosamente", [
                    'cita_id' => $citaId,
                    'rows_affected' => $rowsAffected,
                ]);

            } catch (\Exception $updateException) {
                \Illuminate\Support\Facades\Log::error("CitaController::actualizarVideoEnlace - Error en UPDATE", [
                    'cita_id' => $citaId,
                    'error' => $updateException->getMessage(),
                    'trace' => $updateException->getTraceAsString(),
                ]);
                throw $updateException;
            }

            // Recargar la cita para obtener los datos actualizados
            $cita->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Enlace de reunión virtual actualizado exitosamente',
                'data' => [
                    'cita_id' => $cita->id,
                    'video_enlace' => $cita->video_enlace,
                ]
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error al actualizar enlace de reunión virtual: ' . $e->getMessage(), [
                'cita_id' => $citaId,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el enlace de reunión virtual',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
