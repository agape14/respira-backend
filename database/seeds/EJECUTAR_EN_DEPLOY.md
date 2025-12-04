# 🚀 INSTRUCCIONES PARA EJECUTAR EN EL DEPLOY

## ⚠️ IMPORTANTE
Este script **DEBE ejecutarse** en cada deploy al servidor para asegurar que:
- Los menús antiguos se desactiven
- Los nuevos menús del sistema estén activos
- Los permisos estén correctamente configurados

---

## 📋 PASOS PARA EJECUTAR EN EL SERVIDOR

### Opción 1: Usando SQL Server Management Studio (RECOMENDADO)

1. **Conectarse al servidor**
   ```
   Servidor: 172.17.16.16
   Base de datos: [nombre_base_datos]
   Autenticación: SQL Server
   ```

2. **Abrir el script**
   - Archivo → Abrir → Archivo
   - Seleccionar: `backend/database/seeds/perfiles_y_menus.sql`

3. **Verificar la base de datos**
   - Asegurarse de estar conectado a la base de datos correcta
   - Verificar en el dropdown superior de SSMS

4. **Ejecutar el script**
   - Presionar F5 o clic en "Ejecutar"
   - El script mostrará el progreso en la ventana de mensajes

5. **Verificar resultados**
   - Al final verás un resumen con:
     - Perfiles activos y menús asignados
     - Menús activos del sistema
     - Confirmación de éxito

---

### Opción 2: Usando sqlcmd (Terminal/PowerShell)

```powershell
# Conectarse y ejecutar el script
sqlcmd -S 172.17.16.16 -U usuario -P contraseña -d nombre_bd -i backend\database\seeds\perfiles_y_menus.sql

# O si estás en el servidor directamente
sqlcmd -S localhost -E -d nombre_bd -i backend\database\seeds\perfiles_y_menus.sql
```

---

## ✅ VERIFICACIÓN POST-DEPLOY

### 1. Verificar Menús Activos
```sql
SELECT * FROM menus WHERE estado = 1 ORDER BY orden;
```

**Resultado esperado:** 8 menús activos
- Inicio
- Resultado de tamizaje
- Programar turno - Ver citas
- Citas (Riesgo moderado)
- Protocolo de atención (Riesgo Moderado)
- Derivaciones
- Configuración
- Gestión de Perfiles

### 2. Verificar Perfiles Configurados
```sql
SELECT * FROM perfiles WHERE estado = 1;
```

**Resultado esperado:** 5 perfiles
- Administrador
- Enrolador
- Psicólogo
- MINSA
- ESSALUD

### 3. Verificar Permisos Administrador
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
WHERE p.nombre_perfil = 'Administrador'
ORDER BY m.orden;
```

**Resultado esperado:** 8 filas con todos los permisos en 1

### 4. Probar en el Frontend
1. Iniciar sesión como Administrador
2. Verificar que aparezcan todos los menús en el sidebar
3. Iniciar sesión como Enrolador
4. Verificar que solo vea los menús permitidos
5. Iniciar sesión como MINSA
6. Verificar que solo vea "Derivaciones" y solo el tab MINSA

---

## 🔄 IDEMPOTENCIA

Este script es **idempotente**, lo que significa que:
- ✅ Se puede ejecutar múltiples veces sin causar errores
- ✅ Si ya existen los menús, los actualiza
- ✅ Si no existen, los crea
- ✅ No duplica registros
- ✅ No pierde configuraciones existentes

Por lo tanto, es **SEGURO** ejecutarlo en cada deploy.

---

## 📝 QUÉ HACE EL SCRIPT

### Paso 1: Desactiva menús antiguos
```sql
UPDATE menus SET estado = 0 WHERE estado = 1;
```
- Los menús antiguos no se eliminan (integridad referencial)
- Se marcan como inactivos (estado = 0)
- No aparecerán en el sistema

### Paso 2: Configura perfiles
- Crea o actualiza: Administrador, Enrolador, Psicólogo, MINSA, ESSALUD
- Asegura que el Administrador tenga ID = 1

### Paso 3: Inserta/Actualiza menús del sistema
- Inserta los 8 menús correctos
- Si ya existen (por URL), los actualiza
- Los marca como activos (estado = 1)

### Paso 4-8: Configura permisos
- Limpia permisos antiguos de cada perfil
- Asigna los permisos según las especificaciones del negocio
- Administrador: Acceso completo
- Enrolador: Acceso limitado
- Psicólogo: Solo sus citas
- MINSA/ESSALUD: Solo derivaciones

---

## 🆘 SOLUCIÓN DE PROBLEMAS

### Error: "No se puede establecer conexión"
```
Solución: Verificar que el servidor esté accesible
- Hacer ping a 172.17.16.16
- Verificar firewall
- Verificar credenciales
```

### Error: "Permiso denegado"
```
Solución: El usuario necesita permisos de:
- SELECT en todas las tablas
- INSERT, UPDATE en: menus, perfiles, permisos_perfil_menu
- DELETE en: permisos_perfil_menu
```

### Error: "No existe la tabla menus"
```
Solución: Ejecutar primero las migraciones de Laravel
cd backend
php artisan migrate
```

### Los menús no aparecen en el frontend
```
Solución:
1. Verificar que el script se ejecutó correctamente
2. Limpiar caché del navegador
3. Cerrar sesión y volver a iniciar sesión
4. Verificar en SSMS que estado = 1
```

---

## 📞 CONTACTO

Si tienes problemas durante el deploy:
1. Revisar los logs del script (ventana de mensajes en SSMS)
2. Ejecutar las consultas de verificación
3. Contactar al equipo de desarrollo con:
   - Logs del script
   - Resultados de las consultas de verificación
   - Mensajes de error específicos

---

## 🔒 SEGURIDAD

- ✅ El script NO elimina datos existentes
- ✅ Solo desactiva menús antiguos (no los borra)
- ✅ No modifica datos de usuarios
- ✅ No afecta citas ni pacientes
- ✅ Solo actualiza configuración de menús y permisos

---

**Última actualización:** Diciembre 2025  
**Versión:** 1.0

