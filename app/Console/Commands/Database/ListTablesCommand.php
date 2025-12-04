<?php

namespace App\Console\Commands\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ListTablesCommand extends Command
{
    protected $signature = 'db:tables {--details : Mostrar detalles de cada tabla}';
    protected $description = 'Lista todas las tablas de SQL Server con información adicional';

    public function handle()
    {
        $this->info('📊 Tablas en la Base de Datos SQL Server');
        $this->newLine();

        try {
            $connection = config('database.default');
            $database = config("database.connections.{$connection}.database");

            $this->info("🔗 Conexión: {$connection}");
            $this->info("💾 Base de datos: {$database}");
            $this->newLine();

            $tables = $this->getTables();

            if (empty($tables)) {
                $this->error('❌ No se encontraron tablas.');
                return 1;
            }

            if ($this->option('details')) {
                $this->showTablesWithDetails($tables);
            } else {
                $this->showSimpleTableList($tables);
            }

            $this->newLine();
            $this->info("✅ Total de tablas: " . count($tables));

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

    private function showSimpleTableList(array $tables): void
    {
        $data = array_map(fn($i, $t) => [$i+1, $t], array_keys($tables), $tables);
        $this->table(['#', 'Tabla'], $data);
        $this->info('💡 Usa --details para ver más información de cada tabla');
    }

    private function showTablesWithDetails(array $tables): void
    {
        $data = [];

        foreach ($tables as $table) {
            $columnCount = DB::select("SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?", [$table]);
            $rowCount = $this->getRowCount($table);

            $data[] = [
                'tabla' => $table,
                'columnas' => $columnCount[0]->count,
                'registros' => $rowCount,
            ];
        }

        $this->table(['Tabla', 'Columnas', 'Registros'], $data);
    }

    private function getRowCount(string $table): string
    {
        try {
            $result = DB::select("SELECT COUNT(*) as count FROM [{$table}]");
            return number_format($result[0]->count);
        } catch (\Exception $e) {
            return 'N/A';
        }
    }
}
