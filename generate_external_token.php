<?php

/**
 * Script para generar tokens de acceso externo al dashboard
 *
 * Uso:
 * php generate_external_token.php --nombre="Mi Aplicación" --consejo=1 --duracion=365
 *
 * Parámetros:
 * --nombre: Nombre de la aplicación que usará el token (requerido)
 * --consejo: ID del consejo regional (opcional, null = todos los consejos)
 * --duracion: Duración en días antes de expirar (opcional, por defecto 365)
 * --descripcion: Descripción del token (opcional)
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Str;

// Parsear argumentos de línea de comandos
$options = getopt('', ['nombre:', 'consejo::', 'duracion::', 'descripcion::']);

if (!isset($options['nombre'])) {
    echo "❌ Error: El parámetro --nombre es requerido\n";
    echo "\nUso:\n";
    echo "php generate_external_token.php --nombre=\"Mi Aplicación\" --consejo=1 --duracion=365\n\n";
    echo "Parámetros:\n";
    echo "  --nombre       Nombre de la aplicación (requerido)\n";
    echo "  --consejo      ID del consejo regional (opcional, por defecto: todos)\n";
    echo "  --duracion     Duración en días (opcional, por defecto: 365)\n";
    echo "  --descripcion  Descripción del token (opcional)\n";
    exit(1);
}

$nombreAplicacion = $options['nombre'];
$consejoRegionalId = isset($options['consejo']) ? (int)$options['consejo'] : null;
$duracionDias = isset($options['duracion']) ? (int)$options['duracion'] : 365;
$descripcion = isset($options['descripcion']) ? $options['descripcion'] : null;

// Generar token único
$token = Str::random(40);

// Fechas
$fechaCreacion = date('Y-m-d H:i:s');
$fechaExpiracion = date('Y-m-d H:i:s', strtotime("+{$duracionDias} days"));

// Mostrar información del token generado
echo "\n╔════════════════════════════════════════════════════════════════════╗\n";
echo "║              TOKEN DE ACCESO EXTERNO GENERADO                      ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";
echo "Token:                 {$token}\n";
echo "Aplicación:            {$nombreAplicacion}\n";
echo "Consejo Regional:      " . ($consejoRegionalId ? "ID: {$consejoRegionalId}" : "Todos") . "\n";
echo "Fecha Creación:        {$fechaCreacion}\n";
echo "Fecha Expiración:      {$fechaExpiracion}\n";
echo "Duración:              {$duracionDias} días\n";
if ($descripcion) {
    echo "Descripción:           {$descripcion}\n";
}
echo "\n";

// Generar SQL para insertar el token
$sqlDescripcion = $descripcion ? "'{$descripcion}'" : "NULL";
$sqlConsejoId = $consejoRegionalId ? $consejoRegionalId : "NULL";

$sql = "INSERT INTO external_tokens (token, nombre_aplicacion, descripcion, consejo_regional_id, estado, fecha_creacion, fecha_expiracion, ultimo_uso)
VALUES (
    '{$token}',
    '{$nombreAplicacion}',
    {$sqlDescripcion},
    {$sqlConsejoId},
    1,
    '{$fechaCreacion}',
    '{$fechaExpiracion}',
    NULL
);";

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║              SQL PARA INSERTAR EN LA BASE DE DATOS                ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";
echo $sql . "\n\n";

// Generar URL de ejemplo
$urlEjemplo = "http://localhost:5173/external/dashboard?token={$token}&id_cr=" . ($consejoRegionalId ?: "1");

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║                         URL DE EJEMPLO                             ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";
echo $urlEjemplo . "\n\n";

// Generar archivo con la información
$filename = "token_" . date('Ymd_His') . ".txt";
$content = "TOKEN DE ACCESO EXTERNO - SISTEMA RESPIRA\n";
$content .= "=========================================\n\n";
$content .= "Token:              {$token}\n";
$content .= "Aplicación:         {$nombreAplicacion}\n";
$content .= "Consejo Regional:   " . ($consejoRegionalId ? "ID: {$consejoRegionalId}" : "Todos") . "\n";
$content .= "Fecha Creación:     {$fechaCreacion}\n";
$content .= "Fecha Expiración:   {$fechaExpiracion}\n";
$content .= "Duración:           {$duracionDias} días\n";
if ($descripcion) {
    $content .= "Descripción:        {$descripcion}\n";
}
$content .= "\n\nSQL:\n{$sql}\n";
$content .= "\n\nURL de ejemplo:\n{$urlEjemplo}\n";

file_put_contents($filename, $content);
echo "✓ Información guardada en: {$filename}\n\n";

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║                      PASOS SIGUIENTES                              ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";
echo "1. Ejecutar el SQL en la base de datos\n";
echo "2. Probar el token usando la URL de ejemplo\n";
echo "3. Integrar el token en la aplicación externa\n\n";
echo "⚠️  IMPORTANTE: Guarda el token en un lugar seguro. No se puede recuperar después.\n\n";

