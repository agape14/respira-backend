<?php

namespace App\Console\Commands\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GenerateModelsCommand extends Command
{
    protected $signature = 'db:generate-models {--table= : Tabla específica para generar modelo}';
    protected $description = 'Genera modelos Eloquent desde las tablas de SQL Server';

    public function handle()
    {
        $this->info('🔍 Escaneando base de datos SQL Server...');

        try {
            // Verificar conexión
            $connection = config('database.default');
            $database = config("database.connections.{$connection}.database");

            $this->info("📊 Base de datos: {$database}");
            $this->info("🔗 Conexión: {$connection}");
            $this->newLine();

            // Obtener tablas
            $tables = $this->getTables();

            if (empty($tables)) {
                $this->error('❌ No se encontraron tablas en la base de datos.');
                $this->info('💡 Asegúrate de que tu conexión SQL Server esté configurada correctamente en .env');
                return 1;
            }

            $this->info("✅ Se encontraron " . count($tables) . " tablas:");
            $this->table(['#', 'Tabla'], array_map(fn($i, $t) => [$i+1, $t], array_keys($tables), $tables));

            $specificTable = $this->option('table');

            if ($specificTable) {
                if (!in_array($specificTable, $tables)) {
                    $this->error("❌ La tabla '{$specificTable}' no existe en la base de datos.");
                    return 1;
                }
                $this->generateModel($specificTable);
            } else {
                if ($this->confirm('¿Deseas generar modelos para todas las tablas?', false)) {
                    foreach ($tables as $table) {
                        $this->generateModel($table);
                    }
                } else {
                    $this->info('💡 Usa: php artisan db:generate-models --table=nombre_tabla');
                }
            }

            $this->newLine();
            $this->info('✨ Proceso completado!');

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function getTables(): array
    {
        $tables = DB::select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        return array_column($tables, 'TABLE_NAME');
    }

    private function generateModel(string $tableName): void
    {
        $modelName = Str::studly(Str::singular($tableName));
        $modelPath = app_path("Models/{$modelName}.php");

        if (File::exists($modelPath)) {
            if (!$this->confirm("El modelo {$modelName} ya existe. ¿Sobrescribir?", false)) {
                $this->warn("⏭️  Saltando {$modelName}");
                return;
            }
        }

        // Obtener columnas de la tabla
        $columns = $this->getTableColumns($tableName);
        $fillable = array_filter($columns, fn($col) => !in_array($col, ['id', 'created_at', 'updated_at']));

        $stub = $this->getModelStub($modelName, $tableName, $fillable, $columns);

        File::put($modelPath, $stub);
        $this->info("✅ Modelo generado: {$modelName} (tabla: {$tableName})");
    }

    private function getTableColumns(string $tableName): array
    {
        $columns = DB::select("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$tableName]);
        return array_column($columns, 'COLUMN_NAME');
    }

    private function getModelStub(string $modelName, string $tableName, array $fillable, array $allColumns): string
    {
        $fillableStr = implode("',\n        '", $fillable);
        $hasTimestamps = in_array('created_at', $allColumns) && in_array('updated_at', $allColumns);
        $timestampsLine = $hasTimestamps ? 'true' : 'false';

        return <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class {$modelName} extends Model
{
    /**
     * Conexión a la base de datos
     */
    protected \$connection = 'sqlsrv';

    /**
     * Nombre de la tabla
     */
    protected \$table = '{$tableName}';

    /**
     * Indica si el modelo usa timestamps
     */
    public \$timestamps = {$timestampsLine};

    /**
     * Campos asignables en masa
     */
    protected \$fillable = [
        '{$fillableStr}'
    ];

    /**
     * Aquí puedes definir tus relaciones Eloquent
     *
     * Ejemplo:
     * public function relacion()
     * {
     *     return \$this->hasMany(OtroModelo::class);
     * }
     */
}

PHP;
    }
}

