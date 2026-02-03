<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Protocolo de Atención Psicológica</title>
<style>
    @page {
        size: A4;
        margin: 15mm 12mm 15mm 12mm;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: DejaVu Sans, Arial, sans-serif;
        font-size: 9px;
        color: #333;
        line-height: 1.3;
        background: #fff;
    }

    /* Header institucional */
    .header {
        background: #752568;
        color: #fff;
        padding: 12px 15px;
        border-radius: 6px;
        margin-bottom: 12px;
    }
    .header-title {
        font-size: 14px;
        font-weight: 700;
        margin-bottom: 3px;
        letter-spacing: 0.3px;
    }
    .header-subtitle {
        font-size: 9px;
        opacity: 0.85;
    }
    .header-info {
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid rgba(255,255,255,0.2);
        font-size: 9px;
    }
    .header-info-row {
        display: inline-block;
        margin-right: 25px;
    }
    .header-label { opacity: 0.8; }
    .header-value { font-weight: 600; }

    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 10px;
        font-size: 8px;
        font-weight: 600;
        background: #28a745;
        color: #fff;
        margin-left: 10px;
    }

    /* Secciones */
    .section {
        margin-bottom: 10px;
        page-break-inside: avoid;
    }
    .section-title {
        background: #752568;
        color: #fff;
        padding: 6px 10px;
        font-size: 10px;
        font-weight: 700;
        border-radius: 4px;
        margin-bottom: 8px;
    }

    /* Subsecciones */
    .subsection {
        background: #f8f4f7;
        border: 1px solid #e8dce6;
        border-radius: 4px;
        padding: 8px;
        margin-bottom: 8px;
    }
    .subsection-title {
        color: #752568;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        margin-bottom: 6px;
        padding-bottom: 4px;
        border-bottom: 1px solid #d4c4d1;
    }

    /* Campos en grid */
    .fields-grid {
        width: 100%;
    }
    .field-row {
        margin-bottom: 5px;
    }
    .field {
        background: #fff;
        border: 1px solid #e0d6de;
        border-radius: 3px;
        padding: 5px 8px;
        margin-bottom: 4px;
    }
    .field-label {
        color: #752568;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
        margin-bottom: 2px;
        display: block;
    }
    .field-value {
        color: #333;
        font-size: 9px;
        line-height: 1.4;
        word-wrap: break-word;
    }
    .field-empty {
        color: #999;
        font-style: italic;
    }

    /* Campos de texto largo */
    .textarea-field {
        background: #fff;
        border: 1px solid #e0d6de;
        border-radius: 3px;
        padding: 8px;
        min-height: 40px;
    }
    .textarea-label {
        color: #752568;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
        margin-bottom: 4px;
        display: block;
    }
    .textarea-value {
        color: #333;
        font-size: 9px;
        line-height: 1.5;
    }

    /* Tabla para campos en columnas */
    .fields-table {
        width: 100%;
        border-collapse: collapse;
    }
    .fields-table td {
        vertical-align: top;
        padding: 2px;
    }
    .fields-table .col-half {
        width: 50%;
    }
    .fields-table .col-full {
        width: 100%;
    }

    /* Separador de página */
    .page-break {
        page-break-before: always;
    }

    /* Sesiones 2-4 compactas */
    .session-card {
        background: #fafafa;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        padding: 8px;
        margin-bottom: 8px;
    }
    .session-header {
        background: #752568;
        color: #fff;
        padding: 5px 8px;
        font-size: 10px;
        font-weight: 700;
        border-radius: 3px;
        margin: -8px -8px 8px -8px;
    }

    /* Footer */
    .footer {
        position: fixed;
        bottom: 5mm;
        left: 12mm;
        right: 12mm;
        text-align: center;
        font-size: 7px;
        color: #888;
        border-top: 1px solid #ddd;
        padding-top: 4px;
    }
</style>
</head>
<body>

<!-- Header Institucional -->
<div class="header">
    <div class="header-title">
        Protocolo de Atención Psicológica
        <span class="badge">Intervención Finalizada</span>
    </div>
    <div class="header-subtitle">Colegio Médico del Perú - Programa SERUMS</div>
    <div class="header-info">
        <span class="header-info-row">
            <span class="header-label">Paciente:</span>
            <span class="header-value">{{ $paciente->nombre_completo ?? 'N/A' }}</span>
        </span>
        <span class="header-info-row">
            <span class="header-label">CMP:</span>
            <span class="header-value">{{ $paciente->dni ?? 'N/A' }}</span>
        </span>
        <span class="header-info-row">
            <span class="header-label">Fecha:</span>
            <span class="header-value">{{ date('d/m/Y') }}</span>
        </span>
    </div>
</div>

<!-- SESIÓN 1 -->
<div class="section">
    <div class="section-title">SESIÓN 1 - ENTREVISTA CONTEXTUAL</div>

    <!-- Relaciones -->
    <div class="subsection">
        <div class="subsection-title">Relaciones</div>
        <table class="fields-table">
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Dónde realiza su trabajo rural?</span>
                        <span class="field-value {{ empty($sesion['donde_vives']) ? 'field-empty' : '' }}">{{ $sesion['donde_vives'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Vive solo o con alguien?</span>
                        <span class="field-value {{ empty($sesion['con_quien']) ? 'field-empty' : '' }}">{{ $sesion['con_quien'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Cuánto tiempo ha estado ahí?</span>
                        <span class="field-value {{ empty($sesion['tiempo_en_casa']) ? 'field-empty' : '' }}">{{ $sesion['tiempo_en_casa'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Están las cosas bien en casa?</span>
                        <span class="field-value {{ empty($sesion['bien_en_casa']) ? 'field-empty' : '' }}">{{ $sesion['bien_en_casa'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <div class="field">
                        <span class="field-label">¿Tienes relaciones afectuosas con tu familia y amigos?</span>
                        <span class="field-value {{ empty($sesion['relaciones_afectuosas']) ? 'field-empty' : '' }}">{{ $sesion['relaciones_afectuosas'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Bienestar Profesional -->
    <div class="subsection">
        <div class="subsection-title">Bienestar Profesional</div>
        <table class="fields-table">
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Te sientes cómodo en tu trabajo?</span>
                        <span class="field-value {{ empty($sesion['comodo_en_trabajo']) ? 'field-empty' : '' }}">{{ $sesion['comodo_en_trabajo'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Estás estudiando además de trabajar?</span>
                        <span class="field-value {{ empty($sesion['estudiando']) ? 'field-empty' : '' }}">{{ $sesion['estudiando'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Aspectos que generan estrés?</span>
                        <span class="field-value {{ empty($sesion['estres_en_trabajo']) ? 'field-empty' : '' }}">{{ $sesion['estres_en_trabajo'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Qué es lo que más te preocupa?</span>
                        <span class="field-value {{ empty($sesion['preocupaciones']) ? 'field-empty' : '' }}">{{ $sesion['preocupaciones'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Salud -->
    <div class="subsection">
        <div class="subsection-title">Salud</div>
        <table class="fields-table">
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Usas tabaco, alcohol, drogas?</span>
                        <span class="field-value {{ empty($sesion['usa_productos']) ? 'field-empty' : '' }}">{{ $sesion['usa_productos'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Practicas ejercicios regularmente?</span>
                        <span class="field-value {{ empty($sesion['ejercicio_regular']) ? 'field-empty' : '' }}">{{ $sesion['ejercicio_regular'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <div class="field">
                        <span class="field-label">¿Comes bien?, ¿duermes bien?</span>
                        <span class="field-value {{ empty($sesion['comes_duermes_bien']) ? 'field-empty' : '' }}">{{ $sesion['comes_duermes_bien'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Ocio -->
    <div class="subsection">
        <div class="subsection-title">Ocio</div>
        <table class="fields-table">
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Qué haces para relajarte?</span>
                        <span class="field-value {{ empty($sesion['que_haces_relajarte']) ? 'field-empty' : '' }}">{{ $sesion['que_haces_relajarte'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Cómo te conectas con tu comunidad?</span>
                        <span class="field-value {{ empty($sesion['conectarte_comunidad']) ? 'field-empty' : '' }}">{{ $sesion['conectarte_comunidad'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>
</div>

<!-- Contexto del Problema -->
<div class="section">
    <div class="section-title">CONTEXTO DEL PROBLEMA</div>

    <div class="subsection">
        <div class="subsection-title">Problema y Tiempo</div>
        <table class="fields-table">
            <tr>
                <td colspan="2">
                    <div class="field">
                        <span class="field-label">¿Qué problema te motiva a solicitar esta sesión?</span>
                        <span class="field-value {{ empty($sesion['problema_motiva']) ? 'field-empty' : '' }}">{{ $sesion['problema_motiva'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Cuándo empezó? ¿Con qué frecuencia?</span>
                        <span class="field-value {{ empty($sesion['tiempo_empezo']) ? 'field-empty' : '' }}">{{ $sesion['tiempo_empezo'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Qué pasa antes/después del problema?</span>
                        <span class="field-value {{ empty($sesion['tiempo_notado']) ? 'field-empty' : '' }}">{{ $sesion['tiempo_notado'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Por qué es un problema ahora?</span>
                        <span class="field-value {{ empty($sesion['tiempo_problema']) ? 'field-empty' : '' }}">{{ $sesion['tiempo_problema'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Qué factores desencadenaron la situación actual?</span>
                        @if(isset($disparadores_text) && !empty($disparadores_text) && is_array($disparadores_text))
                            <span class="field-value">{{ implode('; ', $disparadores_text) }}</span>
                        @else
                            <span class="field-value {{ empty($sesion['disparadores_existe']) ? 'field-empty' : '' }}">{{ $sesion['disparadores_existe'] ?? 'Sin respuesta' }}</span>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="subsection">
        <div class="subsection-title">Trayectoria y Severidad</div>
        <table class="fields-table">
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Cómo ha sido a lo largo del tiempo?</span>
                        <span class="field-value {{ empty($sesion['trayectoria_problema']) ? 'field-empty' : '' }}">{{ $sesion['trayectoria_problema'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Momentos más/menos preocupantes?</span>
                        <span class="field-value {{ empty($sesion['trayectoria_habido']) ? 'field-empty' : '' }}">{{ $sesion['trayectoria_habido'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Recientemente ha mejorado/empeorado?</span>
                        <span class="field-value {{ empty($sesion['trayectoria_reciente']) ? 'field-empty' : '' }}">{{ $sesion['trayectoria_reciente'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">Severidad (1-10)</span>
                        <span class="field-value {{ empty($sesion['severidad_grande']) ? 'field-empty' : '' }}">{{ $sesion['severidad_grande'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>
</div>

<div class="page-break"></div>

<!-- Desesperanza Creativa -->
<div class="section">
    <div class="section-title">DESESPERANZA CREATIVA</div>

    <div class="subsection">
        <div class="subsection-title">Intentos de Solución y Consecuencias</div>
        <table class="fields-table">
            <tr>
                <td colspan="2">
                    <div class="field">
                        <span class="field-label">¿Qué has intentado para solucionar el problema?</span>
                        <span class="field-value {{ empty($sesion['intentos_solucion']) ? 'field-empty' : '' }}">{{ $sesion['intentos_solucion'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Cómo ha funcionado a corto plazo?</span>
                        <span class="field-value {{ empty($sesion['costes_funcion']) ? 'field-empty' : '' }}">{{ $sesion['costes_funcion'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Cómo ha funcionado a largo plazo?</span>
                        <span class="field-value {{ empty($sesion['costes_plazo']) ? 'field-empty' : '' }}">{{ $sesion['costes_plazo'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Qué estás perdiendo?</span>
                        <span class="field-value {{ empty($sesion['costes_problema']) ? 'field-empty' : '' }}">{{ $sesion['costes_problema'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">¿Cuánto tiempo dedicas al problema?</span>
                        <span class="field-value {{ empty($sesion['costes_pensando']) ? 'field-empty' : '' }}">{{ $sesion['costes_pensando'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>
</div>

<!-- Conceptualización -->
<div class="section">
    <div class="section-title">CONCEPTUALIZACIÓN DEL PROBLEMA</div>

    <div class="subsection">
        <div class="subsection-title">Pilares de FACT</div>
        <table class="fields-table">
            <tr>
                <td style="width: 33%;">
                    <div class="field">
                        <span class="field-label">Apertura</span>
                        <span class="field-value {{ empty($sesion['apertura']) ? 'field-empty' : '' }}">{{ $sesion['apertura'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td style="width: 33%;">
                    <div class="field">
                        <span class="field-label">Consciencia</span>
                        <span class="field-value {{ empty($sesion['consciencia']) ? 'field-empty' : '' }}">{{ $sesion['consciencia'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td style="width: 34%;">
                    <div class="field">
                        <span class="field-label">Hacer lo que importa</span>
                        <span class="field-value {{ empty($sesion['hacer_importa']) ? 'field-empty' : '' }}">{{ $sesion['hacer_importa'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>
</div>

<!-- Objetivos e Intervención Sesión 1 -->
<div class="section">
    <div class="section-title">OBJETIVOS E INTERVENCIÓN - SESIÓN 1</div>

    <div class="subsection">
        <div class="textarea-field">
            <span class="textarea-label">Establecimiento de Objetivos</span>
            <span class="textarea-value {{ empty($sesion['establecimiento']) ? 'field-empty' : '' }}">{{ $sesion['establecimiento'] ?? 'Sin respuesta' }}</span>
        </div>
        <div class="textarea-field" style="margin-top: 6px;">
            <span class="textarea-label">Intervención Breve</span>
            <span class="textarea-value {{ empty($sesion['intervencion']) ? 'field-empty' : '' }}">{{ $sesion['intervencion'] ?? 'Sin respuesta' }}</span>
        </div>
        <div class="textarea-field" style="margin-top: 6px;">
            <span class="textarea-label">Recomendaciones</span>
            <span class="textarea-value {{ empty($sesion['recomendacionsesionuno']) ? 'field-empty' : '' }}">{{ $sesion['recomendacionsesionuno'] ?? 'Sin respuesta' }}</span>
        </div>
    </div>
</div>

<!-- SESIONES 2, 3 y 4 -->
<div class="section">
    <div class="section-title">SESIONES DE SEGUIMIENTO</div>

    <!-- Sesión 2 -->
    <div class="session-card">
        <div class="session-header">Sesión 2</div>
        <table class="fields-table">
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">Revisión del Plan</span>
                        <span class="field-value {{ empty($sesion['sesiondos_revision']) ? 'field-empty' : '' }}">{{ $sesion['sesiondos_revision'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">Intervención Breve</span>
                        <span class="field-value {{ empty($sesion['sesiondos_intervencion']) ? 'field-empty' : '' }}">{{ $sesion['sesiondos_intervencion'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">Progreso</span>
                        <span class="field-value {{ empty($sesion['sesiondos_progreso']) ? 'field-empty' : '' }}">{{ $sesion['sesiondos_progreso'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">Recomendaciones</span>
                        <span class="field-value {{ empty($sesion['recomendacionsesiondos']) ? 'field-empty' : '' }}">{{ $sesion['recomendacionsesiondos'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Sesión 3 -->
    <div class="session-card">
        <div class="session-header">Sesión 3</div>
        <table class="fields-table">
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">Revisión del Plan</span>
                        <span class="field-value {{ empty($sesion['sesiontres_revision']) ? 'field-empty' : '' }}">{{ $sesion['sesiontres_revision'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">Intervención Breve</span>
                        <span class="field-value {{ empty($sesion['sesiontres_intervencion']) ? 'field-empty' : '' }}">{{ $sesion['sesiontres_intervencion'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">Progreso</span>
                        <span class="field-value {{ empty($sesion['sesiontres_progreso']) ? 'field-empty' : '' }}">{{ $sesion['sesiontres_progreso'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">Recomendaciones</span>
                        <span class="field-value {{ empty($sesion['recomendacionsesiontres']) ? 'field-empty' : '' }}">{{ $sesion['recomendacionsesiontres'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Sesión 4 -->
    <div class="session-card">
        <div class="session-header">Sesión 4 - Cierre</div>
        <table class="fields-table">
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">Revisión del Plan</span>
                        <span class="field-value {{ empty($sesion['sesioncuatro_revision']) ? 'field-empty' : '' }}">{{ $sesion['sesioncuatro_revision'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">Intervención Breve</span>
                        <span class="field-value {{ empty($sesion['sesioncuatro_intervencion']) ? 'field-empty' : '' }}">{{ $sesion['sesioncuatro_intervencion'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">Progreso</span>
                        <span class="field-value {{ empty($sesion['sesioncuatro_progreso']) ? 'field-empty' : '' }}">{{ $sesion['sesioncuatro_progreso'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
                <td class="col-half">
                    <div class="field">
                        <span class="field-label">Recomendaciones</span>
                        <span class="field-value {{ empty($sesion['recomendacionsesioncuatro']) ? 'field-empty' : '' }}">{{ $sesion['recomendacionsesioncuatro'] ?? 'Sin respuesta' }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>
</div>

<div class="footer">
    Documento generado el {{ date('d/m/Y H:i') }} | Colegio Médico del Perú - Programa SERUMS | Confidencial
</div>

</body>
</html>
