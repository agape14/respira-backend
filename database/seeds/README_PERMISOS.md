# Configuración de Perfiles y Permisos

## Descripción

Este directorio contiene el script SQL para configurar los perfiles de usuario y sus permisos de acceso al sistema.

## Perfiles Configurados

### 1. Administrador
- **Acceso**: Completo a todo el sistema
- **Permisos**: Ver, Editar, Eliminar en todos los menús

### 2. Enrolador
- **Acceso Limitado**:
  - ✅ Inicio (Dashboard)
  - ✅ Resultado de tamizaje (solo ver)
  - ✅ Programar turno - Ver citas (ver y editar)
  - ✅ Citas (Riesgo moderado) (solo ver)
  - ✅ Protocolo de atención (solo reprogramar, agendar y cancelar cita)

### 3. Psicólogo
- **Acceso Limitado**:
  - ✅ Inicio (Dashboard)
  - ✅ Citas (solo ver SUS citas)
  - ✅ Protocolo de atención (solo ver SUS citas)
- **Nota**: El sistema filtra automáticamente para mostrar solo las citas asignadas al psicólogo

### 4. MINSA
- **Acceso Único**:
  - ✅ Derivaciones (solo tab MINSA)
- **Nota**: Solo puede ver y gestionar derivaciones de MINSA

### 5. ESSALUD
- **Acceso Único**:
  - ✅ Derivaciones (solo tab ESSALUD)
- **Nota**: Solo puede ver y gestionar derivaciones de ESSALUD

## Instrucciones de Instalación

### ⚠️ IMPORTANTE: Ejecutar en cada Deploy

Este script **DEBE ejecutarse en cada deploy** al servidor para asegurar que los menús y permisos estén correctamente configurados.

### Opción 1: Usando Scripts de Deploy (RECOMENDADO)

#### Windows (PowerShell/CMD):
```bash
cd backend/database/seeds
deploy.bat
```

#### Linux/Mac:
```bash
cd backend/database/seeds
chmod +x deploy.sh
./deploy.sh
```

Los scripts te pedirán:
- Servidor SQL Server (default: 172.17.16.16)
- Nombre de la base de datos
- Usuario SQL
- Contraseña

### Opción 2: Usando SQL Server Management Studio

1. Abrir **SQL Server Management Studio (SSMS)**
2. Conectarse al servidor: `172.17.16.16`
3. Seleccionar la base de datos del proyecto
4. Abrir el archivo `perfiles_y_menus.sql`
5. Ejecutar el script completo (F5)

### Opción 3: Usando sqlcmd (Terminal)

```bash
sqlcmd -S 172.17.16.16 -U usuario -P contraseña -d nombre_bd -i perfiles_y_menus.sql
```

### Lo que hace el script:

1. ✅ **Desactiva menús antiguos** (no los elimina, solo marca estado=0)
2. ✅ **Crea/actualiza perfiles**: Administrador, Enrolador, Psicólogo, MINSA, ESSALUD
3. ✅ **Inserta/actualiza los 8 menús del sistema**:
   - Inicio
   - Resultado de tamizaje
   - Programar turno - Ver citas
   - Citas (Riesgo moderado)
   - Protocolo de atención
   - Derivaciones
   - Configuración
   - Gestión de Perfiles
4. ✅ **Configura permisos** según las reglas de negocio
5. ✅ **Muestra un resumen** de la configuración

### Script Idempotente

El script es **idempotente** (se puede ejecutar múltiples veces):
- Si los registros existen, los actualiza
- Si no existen, los crea
- No duplica datos
- Es seguro ejecutarlo en cada deploy

### Paso 2: Verificar la Configuración

#### Verificar menús activos:
```sql
SELECT id, nombre_menu, url, orden, estado 
FROM menus 
WHERE estado = 1 
ORDER BY orden;
```
**Esperado:** 8 menús activos

#### Verificar perfiles:
```sql
SELECT id, nombre_perfil, estado 
FROM perfiles 
WHERE estado = 1;
```
**Esperado:** 5 perfiles (Administrador, Enrolador, Psicólogo, MINSA, ESSALUD)

#### Verificar permisos:
```sql
SELECT 
    p.nombre_perfil,
    m.nombre_menu,
    ppm.permiso_ver,
    ppm.permiso_editar,
    ppm.permiso_eliminar
FROM perfiles p
INNER JOIN permisos_perfil_menu ppm ON p.id = ppm.perfil_id
INNER JOIN menus m ON ppm.menu_id = m.id
WHERE m.estado = 1
ORDER BY p.nombre_perfil, m.orden;
```

#### Verificar que no hay menús antiguos activos:
```sql
SELECT * FROM menus WHERE estado = 1 AND url NOT IN (
    '/dashboard', '/tamizaje', '/citas', '/citas-riesgo', 
    '/protocolo', '/derivaciones', '/configuracion', '/perfiles'
);
```
**Esperado:** 0 registros

### Paso 3: Asignar Perfiles a Usuarios

Para asignar un perfil a un usuario:

```sql
-- Ejemplo: Asignar perfil MINSA al usuario con ID 123
UPDATE usuarios 
SET perfil_id = (SELECT id FROM perfiles WHERE nombre_perfil = 'MINSA')
WHERE id = 123;

-- Verificar la asignación
SELECT 
    u.id,
    u.nombre_completo,
    p.nombre_perfil
FROM usuarios u
INNER JOIN perfiles p ON u.perfil_id = p.id
WHERE u.id = 123;
```

### Paso 4: Probar en el Frontend

1. Iniciar sesión con diferentes perfiles
2. Verificar que los menús aparezcan según los permisos
3. Verificar que el filtrado automático funcione (Psicólogo, MINSA, ESSALUD)

## Funcionalidades Implementadas

### Backend (Laravel)

1. **Middleware de Permisos**: `CheckMenuPermission.php`
   - Verifica permisos de acceso a rutas
   - Administrador tiene acceso completo automáticamente

2. **Controladores**:
   - `MenuController.php`: Gestión de menús y permisos por perfil
   - `PerfilController.php`: CRUD de perfiles y gestión de permisos
   - `CitaController.php`: Filtrado automático por psicólogo
   - `ProtocoloAtencionController.php`: Filtrado automático por psicólogo

3. **Endpoints API**:
   - `GET /api/menus/by-profile`: Obtiene menús según perfil del usuario
   - `GET /api/perfiles`: Lista todos los perfiles
   - `POST /api/perfiles`: Crear nuevo perfil
   - `PUT /api/perfiles/{id}`: Actualizar perfil
   - `DELETE /api/perfiles/{id}`: Eliminar perfil
   - `GET /api/perfiles/{id}/permisos`: Obtener permisos de un perfil
   - `POST /api/perfiles/{id}/permisos`: Actualizar permisos de un perfil

### Frontend (React)

1. **Componentes Modificados**:
   - `MainLayout.jsx`: Menús dinámicos según perfil
   - `DerivacionesPage.jsx`: Tabs restringidos según perfil MINSA/ESSALUD

2. **Nuevas Páginas**:
   - `PerfilesPage.jsx`: CRUD completo de perfiles con gestión de permisos

## Reglas de Negocio

### Filtrado Automático por Psicólogo

Cuando un usuario con perfil "Psicólogo" accede a:
- **Citas**: Solo ve las citas donde él es el `medico_id`
- **Protocolo de Atención**: Solo ve los protocolos de sus citas

El filtrado se aplica automáticamente en el backend, no requiere configuración adicional.

### Restricción de Tabs en Derivaciones

- **MINSA**: Solo ve el tab "MINSA"
- **ESSALUD**: Solo ve el tab "ESSALUD"
- **Otros perfiles**: Ven ambos tabs (si tienen permiso al menú)

## Mantenimiento

### Agregar un Nuevo Menú

1. Insertar en la tabla `menus`:
```sql
INSERT INTO menus (nombre_menu, url, icono, estado, orden)
VALUES ('Nuevo Menú', '/nuevo-menu', 'IconName', 1, 9);
```

2. Asignar permisos a los perfiles necesarios:
```sql
INSERT INTO permisos_perfil_menu (perfil_id, menu_id, permiso_ver, permiso_editar, permiso_eliminar)
VALUES (1, (SELECT id FROM menus WHERE url = '/nuevo-menu'), 1, 1, 1);
```

3. Actualizar el frontend:
   - Agregar el ícono en `MainLayout.jsx` (función `getIconByUrl`)
   - Crear la página correspondiente
   - Agregar la ruta en `App.jsx`

### Modificar Permisos de un Perfil

Usar la interfaz web en `/perfiles` o ejecutar SQL:

```sql
-- Ejemplo: Dar permiso de edición al Enrolador en Derivaciones
UPDATE permisos_perfil_menu
SET permiso_editar = 1
WHERE perfil_id = (SELECT id FROM perfiles WHERE nombre_perfil = 'Enrolador')
  AND menu_id = (SELECT id FROM menus WHERE url = '/derivaciones');
```

## Notas Importantes

1. **No eliminar el perfil Administrador**: El sistema lo protege automáticamente
2. **Perfiles MINSA/ESSALUD**: Deben mantener sus nombres exactos para el filtrado de tabs
3. **Perfil Psicólogo**: Debe contener la palabra "Psicólogo" o "Psicologo" para el filtrado automático
4. **Caché**: Si los cambios no se reflejan, limpiar caché del navegador y del backend

## Soporte

Para problemas o dudas sobre la configuración de permisos, contactar al equipo de desarrollo.

