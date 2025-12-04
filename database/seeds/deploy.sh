#!/bin/bash

# =====================================================
# Script de Deploy - Configuración de Menús y Permisos
# Sistema de Gestión - Colegio Médico del Perú
# =====================================================

echo ""
echo "========================================"
echo "DEPLOY: Configuración de Menús y Permisos"
echo "========================================"
echo ""

# Solicitar credenciales
read -p "Servidor SQL Server [172.17.16.16]: " SERVER
SERVER=${SERVER:-172.17.16.16}

read -p "Nombre de la Base de Datos: " DATABASE
if [ -z "$DATABASE" ]; then
    echo "ERROR: Debe especificar el nombre de la base de datos"
    exit 1
fi

read -p "Usuario SQL: " USERNAME
if [ -z "$USERNAME" ]; then
    echo "ERROR: Debe especificar el usuario"
    exit 1
fi

read -sp "Contraseña: " PASSWORD
echo ""
if [ -z "$PASSWORD" ]; then
    echo "ERROR: Debe especificar la contraseña"
    exit 1
fi

echo ""
echo "Conectando a: $SERVER"
echo "Base de datos: $DATABASE"
echo "Usuario: $USERNAME"
echo ""

# Ejecutar el script SQL
sqlcmd -S $SERVER -U $USERNAME -P $PASSWORD -d $DATABASE -i perfiles_y_menus.sql

if [ $? -eq 0 ]; then
    echo ""
    echo "========================================"
    echo "DEPLOY COMPLETADO EXITOSAMENTE"
    echo "========================================"
    echo ""
else
    echo ""
    echo "========================================"
    echo "ERROR EN EL DEPLOY"
    echo "========================================"
    echo "Código de error: $?"
    echo ""
    echo "Revisar:"
    echo "- Credenciales de acceso"
    echo "- Conectividad al servidor"
    echo "- Permisos del usuario"
    echo ""
    exit 1
fi

