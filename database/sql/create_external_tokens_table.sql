-- Tabla para tokens de acceso externo al dashboard
-- Esta tabla permite gestionar tokens de autenticación para aplicaciones externas

-- Crear tabla external_tokens
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'external_tokens')
BEGIN
    CREATE TABLE external_tokens (
        id INT IDENTITY(1,1) PRIMARY KEY,
        token NVARCHAR(100) NOT NULL UNIQUE,
        nombre_aplicacion NVARCHAR(255) NOT NULL,
        descripcion NVARCHAR(500) NULL,
        consejo_regional_id INT NULL, -- NULL = acceso a todos los consejos regionales
        estado TINYINT NOT NULL DEFAULT 1, -- 1=activo, 0=inactivo
        fecha_creacion DATETIME NULL,
        fecha_expiracion DATETIME NULL,
        ultimo_uso DATETIME NULL
    );

    -- Índices para optimizar consultas
    CREATE INDEX IX_external_tokens_token ON external_tokens(token);
    CREATE INDEX IX_external_tokens_estado ON external_tokens(estado);
    CREATE INDEX IX_external_tokens_consejo_regional ON external_tokens(consejo_regional_id);

    PRINT 'Tabla external_tokens creada exitosamente';
END
ELSE
BEGIN
    PRINT 'La tabla external_tokens ya existe';
END
GO

-- Insertar tokens de ejemplo (SOLO PARA DESARROLLO/PRUEBAS)
-- ⚠️ ELIMINAR ESTOS TOKENS EN PRODUCCIÓN

-- Token de prueba 1: Acceso a todos los consejos regionales
INSERT INTO external_tokens (token, nombre_aplicacion, descripcion, consejo_regional_id, estado, fecha_creacion, fecha_expiracion)
VALUES (
    'test_token_123456789',
    'Token de Pruebas',
    'Token simple para pruebas de desarrollo - Solo para ambiente local',
    NULL,
    1,
    GETDATE(),
    DATEADD(YEAR, 1, GETDATE())
);

-- Token de prueba 2: Acceso específico al consejo regional ID 1
INSERT INTO external_tokens (token, nombre_aplicacion, descripcion, consejo_regional_id, estado, fecha_creacion, fecha_expiracion)
VALUES (
    'test_token_cr1_987654321',
    'Token CR Lima',
    'Token de prueba para consejo regional de Lima',
    1,
    1,
    GETDATE(),
    DATEADD(YEAR, 1, GETDATE())
);

PRINT 'Tokens de ejemplo insertados exitosamente';
PRINT '';
PRINT '═══════════════════════════════════════════════════════════════════';
PRINT 'URLs DE PRUEBA (Frontend en http://localhost:5173):';
PRINT '═══════════════════════════════════════════════════════════════════';
PRINT '';
PRINT 'Token con acceso a todos los consejos regionales (id_cr=1):';
PRINT 'http://localhost:5173/external/dashboard?token=test_token_123456789&id_cr=1';
PRINT '';
PRINT 'Token con acceso específico al consejo regional 1:';
PRINT 'http://localhost:5173/external/dashboard?token=test_token_cr1_987654321&id_cr=1';
PRINT '';
PRINT '═══════════════════════════════════════════════════════════════════';
GO

