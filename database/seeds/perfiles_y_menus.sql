-- =====================================================
-- SCRIPT DE CONFIGURACIÓN DE PERFILES Y MENÚS
-- Sistema de Gestión - Colegio Médico del Perú
-- =====================================================
-- IMPORTANTE: Este script es idempotente (se puede ejecutar múltiples veces)
-- Se debe ejecutar en cada deploy para asegurar la configuración correcta
-- =====================================================

PRINT '========================================';
PRINT 'INICIANDO CONFIGURACIÓN DE PERFILES Y MENÚS';
PRINT '========================================';
PRINT '';

-- =====================================================
-- 1. DAR DE BAJA MENÚS ANTIGUOS
-- =====================================================

PRINT '1. Dando de baja menús antiguos...';

-- Desactivar todos los menús existentes (no eliminar por integridad referencial)
UPDATE menus
SET estado = 0
WHERE estado = 1;

PRINT '   - Menús antiguos desactivados';
PRINT '';

-- =====================================================
-- 2. VERIFICAR Y CREAR PERFILES FALTANTES
-- =====================================================

PRINT '2. Configurando perfiles...';

-- Verificar/Crear perfil Administrador
IF NOT EXISTS (SELECT 1 FROM perfiles WHERE id = 1)
BEGIN
    SET IDENTITY_INSERT perfiles ON;
    INSERT INTO perfiles (id, nombre_perfil, descripcion, permiso_ver, permiso_editar, permiso_eliminar, estado)
    VALUES (1, 'Administrador', 'Perfil con acceso completo al sistema', 1, 1, 1, 1);
    SET IDENTITY_INSERT perfiles OFF;
    PRINT '   - Perfil Administrador creado';
END
ELSE
BEGIN
    UPDATE perfiles
    SET nombre_perfil = 'Administrador',
        descripcion = 'Perfil con acceso completo al sistema',
        estado = 1
    WHERE id = 1;
    PRINT '   - Perfil Administrador actualizado';
END

-- Perfil MINSA
IF NOT EXISTS (SELECT 1 FROM perfiles WHERE nombre_perfil = 'MINSA')
BEGIN
    INSERT INTO perfiles (nombre_perfil, descripcion, permiso_ver, permiso_editar, permiso_eliminar, estado)
    VALUES ('MINSA', 'Perfil para usuarios del Ministerio de Salud', 1, 0, 0, 1);
    PRINT '   - Perfil MINSA creado';
END
ELSE
BEGIN
    UPDATE perfiles
    SET descripcion = 'Perfil para usuarios del Ministerio de Salud',
        estado = 1
    WHERE nombre_perfil = 'MINSA';
    PRINT '   - Perfil MINSA actualizado';
END

-- Perfil ESSALUD
IF NOT EXISTS (SELECT 1 FROM perfiles WHERE nombre_perfil = 'ESSALUD')
BEGIN
    INSERT INTO perfiles (nombre_perfil, descripcion, permiso_ver, permiso_editar, permiso_eliminar, estado)
    VALUES ('ESSALUD', 'Perfil para usuarios de EsSalud', 1, 0, 0, 1);
    PRINT '   - Perfil ESSALUD creado';
END
ELSE
BEGIN
    UPDATE perfiles
    SET descripcion = 'Perfil para usuarios de EsSalud',
        estado = 1
    WHERE nombre_perfil = 'ESSALUD';
    PRINT '   - Perfil ESSALUD actualizado';
END

-- Perfil Psicólogo
IF NOT EXISTS (SELECT 1 FROM perfiles WHERE nombre_perfil = 'Psicólogo')
BEGIN
    INSERT INTO perfiles (nombre_perfil, descripcion, permiso_ver, permiso_editar, permiso_eliminar, estado)
    VALUES ('Psicólogo', 'Perfil para psicólogos que atienden pacientes', 1, 1, 0, 1);
    PRINT '   - Perfil Psicólogo creado';
END
ELSE
BEGIN
    UPDATE perfiles
    SET descripcion = 'Perfil para psicólogos que atienden pacientes',
        estado = 1
    WHERE nombre_perfil = 'Psicólogo';
    PRINT '   - Perfil Psicólogo actualizado';
END

-- Perfil Enrolador
IF NOT EXISTS (SELECT 1 FROM perfiles WHERE nombre_perfil = 'Enrolador')
BEGIN
    INSERT INTO perfiles (nombre_perfil, descripcion, permiso_ver, permiso_editar, permiso_eliminar, estado)
    VALUES ('Enrolador', 'Perfil para personal que realiza enrolamiento', 1, 1, 0, 1);
    PRINT '   - Perfil Enrolador creado';
END
ELSE
BEGIN
    UPDATE perfiles
    SET descripcion = 'Perfil para personal que realiza enrolamiento',
        estado = 1
    WHERE nombre_perfil = 'Enrolador';
    PRINT '   - Perfil Enrolador actualizado';
END

PRINT '';

-- =====================================================
-- 3. INSERTAR/ACTUALIZAR MENÚS DEL SISTEMA
-- =====================================================

PRINT '3. Configurando menús del sistema...';

-- Tabla temporal con los menús correctos del sistema
DECLARE @MenusConfig TABLE (
    url VARCHAR(100),
    nombre_menu VARCHAR(100),
    icono VARCHAR(50),
    orden INT
);

INSERT INTO @MenusConfig (url, nombre_menu, icono, orden) VALUES
('/dashboard', 'Inicio', 'Home', 1),
('/tamizaje', 'Resultado de tamizaje', 'FileText', 2),
('/citas', 'Programar turno - Ver citas', 'Calendar', 3),
('/citas-riesgo', 'Citas (Riesgo moderado)', 'AlertCircle', 4),
('/protocolo', 'Protocolo de atención (Riesgo Moderado)', 'ClipboardList', 5),
('/derivaciones', 'Derivaciones', 'Send', 6),
('/configuracion', 'Configuración', 'Settings', 7),
('/perfiles', 'Gestión de Perfiles', 'Shield', 8);

-- Actualizar o insertar cada menú
DECLARE @url VARCHAR(100), @nombre VARCHAR(100), @icono VARCHAR(50), @orden INT;

DECLARE menu_cursor CURSOR FOR
SELECT url, nombre_menu, icono, orden FROM @MenusConfig;

OPEN menu_cursor;
FETCH NEXT FROM menu_cursor INTO @url, @nombre, @icono, @orden;

WHILE @@FETCH_STATUS = 0
BEGIN
    IF EXISTS (SELECT 1 FROM menus WHERE url = @url)
    BEGIN
        -- Actualizar menú existente
        UPDATE menus
        SET nombre_menu = @nombre,
            icono = @icono,
            orden = @orden,
            estado = 1
        WHERE url = @url;

        PRINT '   - Menú actualizado: ' + @nombre;
    END
    ELSE
    BEGIN
        -- Insertar nuevo menú
        INSERT INTO menus (nombre_menu, url, icono, estado, orden)
        VALUES (@nombre, @url, @icono, 1, @orden);

        PRINT '   - Menú creado: ' + @nombre;
    END

    FETCH NEXT FROM menu_cursor INTO @url, @nombre, @icono, @orden;
END

CLOSE menu_cursor;
DEALLOCATE menu_cursor;

PRINT '';

-- =====================================================
-- 4. CONFIGURAR PERMISOS PARA PERFIL ENROLADOR
-- =====================================================

PRINT '4. Configurando permisos para Enrolador...';

DECLARE @enrolador_id INT;
SELECT @enrolador_id = id FROM perfiles WHERE nombre_perfil = 'Enrolador';

IF @enrolador_id IS NOT NULL
BEGIN
    -- Limpiar permisos existentes del enrolador
    DELETE FROM permisos_perfil_menu WHERE perfil_id = @enrolador_id;

    -- Asignar permisos al enrolador según especificaciones
    INSERT INTO permisos_perfil_menu (perfil_id, menu_id, permiso_ver, permiso_editar, permiso_eliminar)
    SELECT @enrolador_id, id, 1, 0, 0 FROM menus WHERE url = '/dashboard' AND estado = 1;

    INSERT INTO permisos_perfil_menu (perfil_id, menu_id, permiso_ver, permiso_editar, permiso_eliminar)
    SELECT @enrolador_id, id, 1, 0, 0 FROM menus WHERE url = '/tamizaje' AND estado = 1;

    INSERT INTO permisos_perfil_menu (perfil_id, menu_id, permiso_ver, permiso_editar, permiso_eliminar)
    SELECT @enrolador_id, id, 1, 1, 0 FROM menus WHERE url = '/citas' AND estado = 1;

    INSERT INTO permisos_perfil_menu (perfil_id, menu_id, permiso_ver, permiso_editar, permiso_eliminar)
    SELECT @enrolador_id, id, 1, 0, 0 FROM menus WHERE url = '/citas-riesgo' AND estado = 1;

    INSERT INTO permisos_perfil_menu (perfil_id, menu_id, permiso_ver, permiso_editar, permiso_eliminar)
    SELECT @enrolador_id, id, 1, 1, 0 FROM menus WHERE url = '/protocolo' AND estado = 1;

    PRINT '   - Permisos configurados correctamente';
END
ELSE
BEGIN
    PRINT '   - ERROR: Perfil Enrolador no encontrado';
END

PRINT '';

-- =====================================================
-- 5. CONFIGURAR PERMISOS PARA PERFIL PSICÓLOGO
-- =====================================================

PRINT '5. Configurando permisos para Psicólogo...';

DECLARE @psicologo_id INT;
SELECT @psicologo_id = id FROM perfiles WHERE nombre_perfil = 'Psicólogo';

IF @psicologo_id IS NOT NULL
BEGIN
    -- Limpiar permisos existentes del psicólogo
    DELETE FROM permisos_perfil_menu WHERE perfil_id = @psicologo_id;

    -- Asignar permisos al psicólogo según especificaciones
    INSERT INTO permisos_perfil_menu (perfil_id, menu_id, permiso_ver, permiso_editar, permiso_eliminar)
    SELECT @psicologo_id, id, 1, 0, 0 FROM menus WHERE url = '/dashboard' AND estado = 1;

    INSERT INTO permisos_perfil_menu (perfil_id, menu_id, permiso_ver, permiso_editar, permiso_eliminar)
    SELECT @psicologo_id, id, 1, 1, 0 FROM menus WHERE url = '/citas' AND estado = 1;

    INSERT INTO permisos_perfil_menu (perfil_id, menu_id, permiso_ver, permiso_editar, permiso_eliminar)
    SELECT @psicologo_id, id, 1, 1, 0 FROM menus WHERE url = '/protocolo' AND estado = 1;

    PRINT '   - Permisos configurados correctamente';
    PRINT '   - NOTA: El sistema filtra automáticamente sus citas';
END
ELSE
BEGIN
    PRINT '   - ERROR: Perfil Psicólogo no encontrado';
END

PRINT '';

-- =====================================================
-- 6. CONFIGURAR PERMISOS PARA PERFIL MINSA
-- =====================================================

PRINT '6. Configurando permisos para MINSA...';

DECLARE @minsa_id INT;
SELECT @minsa_id = id FROM perfiles WHERE nombre_perfil = 'MINSA';

IF @minsa_id IS NOT NULL
BEGIN
    -- Limpiar permisos existentes de MINSA
    DELETE FROM permisos_perfil_menu WHERE perfil_id = @minsa_id;

    -- Asignar permisos a MINSA - Solo Derivaciones
    INSERT INTO permisos_perfil_menu (perfil_id, menu_id, permiso_ver, permiso_editar, permiso_eliminar)
    SELECT @minsa_id, id, 1, 1, 0 FROM menus WHERE url = '/derivaciones' AND estado = 1;

    PRINT '   - Permisos configurados correctamente';
    PRINT '   - NOTA: Solo puede acceder al tab MINSA';
END
ELSE
BEGIN
    PRINT '   - ERROR: Perfil MINSA no encontrado';
END

PRINT '';

-- =====================================================
-- 7. CONFIGURAR PERMISOS PARA PERFIL ESSALUD
-- =====================================================

PRINT '7. Configurando permisos para ESSALUD...';

DECLARE @essalud_id INT;
SELECT @essalud_id = id FROM perfiles WHERE nombre_perfil = 'ESSALUD';

IF @essalud_id IS NOT NULL
BEGIN
    -- Limpiar permisos existentes de ESSALUD
    DELETE FROM permisos_perfil_menu WHERE perfil_id = @essalud_id;

    -- Asignar permisos a ESSALUD - Solo Derivaciones
    INSERT INTO permisos_perfil_menu (perfil_id, menu_id, permiso_ver, permiso_editar, permiso_eliminar)
    SELECT @essalud_id, id, 1, 1, 0 FROM menus WHERE url = '/derivaciones' AND estado = 1;

    PRINT '   - Permisos configurados correctamente';
    PRINT '   - NOTA: Solo puede acceder al tab ESSALUD';
END
ELSE
BEGIN
    PRINT '   - ERROR: Perfil ESSALUD no encontrado';
END

PRINT '';

-- =====================================================
-- 8. CONFIGURAR PERMISOS PARA ADMINISTRADOR
-- =====================================================

PRINT '8. Configurando permisos para Administrador...';

DECLARE @admin_id INT;
SELECT @admin_id = id FROM perfiles WHERE id = 1 OR nombre_perfil = 'Administrador';

IF @admin_id IS NOT NULL
BEGIN
    -- Limpiar permisos existentes del administrador
    DELETE FROM permisos_perfil_menu WHERE perfil_id = @admin_id;

    -- Asignar todos los permisos al administrador
    INSERT INTO permisos_perfil_menu (perfil_id, menu_id, permiso_ver, permiso_editar, permiso_eliminar)
    SELECT @admin_id, id, 1, 1, 1 FROM menus WHERE estado = 1;

    PRINT '   - Permisos completos asignados';
    PRINT '   - Acceso a todos los menús del sistema';
END
ELSE
BEGIN
    PRINT '   - ERROR: Perfil Administrador no encontrado';
END

PRINT '';

-- =====================================================
-- 9. MOSTRAR RESUMEN DE CONFIGURACIÓN
-- =====================================================

PRINT '========================================';
PRINT 'RESUMEN DE CONFIGURACIÓN';
PRINT '========================================';
PRINT '';

-- Resumen de Perfiles
PRINT 'PERFILES ACTIVOS:';
SELECT
    p.id as ID,
    p.nombre_perfil as Perfil,
    COUNT(ppm.id) as Menus_Asignados,
    p.estado as Activo
FROM perfiles p
LEFT JOIN permisos_perfil_menu ppm ON p.id = ppm.perfil_id
WHERE p.estado = 1
GROUP BY p.id, p.nombre_perfil, p.estado
ORDER BY p.id;

PRINT '';
PRINT 'MENÚS ACTIVOS:';
SELECT
    m.id as ID,
    m.nombre_menu as Menu,
    m.url as URL,
    m.orden as Orden,
    COUNT(ppm.id) as Perfiles_Asignados
FROM menus m
LEFT JOIN permisos_perfil_menu ppm ON m.id = ppm.menu_id
WHERE m.estado = 1
GROUP BY m.id, m.nombre_menu, m.url, m.orden
ORDER BY m.orden;

PRINT '';
PRINT '========================================';
PRINT 'CONFIGURACIÓN COMPLETADA EXITOSAMENTE';
PRINT '========================================';
PRINT '';
PRINT 'NOTAS IMPORTANTES:';
PRINT '- Menús antiguos desactivados (estado=0)';
PRINT '- Solo los 8 menús del sistema están activos';
PRINT '- Este script es idempotente (se puede ejecutar múltiples veces)';
PRINT '- Ejecutar este script en cada deploy para asegurar consistencia';
PRINT '';

