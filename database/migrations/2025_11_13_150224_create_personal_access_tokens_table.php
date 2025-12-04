<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Usar SQL directo para SQL Server con datetime2
        DB::statement("
            CREATE TABLE personal_access_tokens (
                id BIGINT IDENTITY(1,1) PRIMARY KEY,
                tokenable_type NVARCHAR(255) NOT NULL,
                tokenable_id BIGINT NOT NULL,
                name NVARCHAR(MAX) NOT NULL,
                token VARCHAR(64) UNIQUE NOT NULL,
                abilities NVARCHAR(MAX) NULL,
                last_used_at DATETIME2 NULL,
                expires_at DATETIME2 NULL,
                created_at DATETIME2 NULL,
                updated_at DATETIME2 NULL
            )
        ");

        // Crear índice para expires_at
        DB::statement("CREATE INDEX personal_access_tokens_expires_at_index ON personal_access_tokens (expires_at)");

        // Crear índice para tokenable
        DB::statement("CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON personal_access_tokens (tokenable_type, tokenable_id)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
