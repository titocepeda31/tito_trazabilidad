# Administración multiempresa — piloto

1. Respaldar maderas7_ttrazabilidad e importar database/002_platform.sql desde phpMyAdmin, después de 001_organization.sql.
2. En el config.php privado de /home/maderas7/tito-trazabilidad-privado/api/private/ agregar la clave platform_admin_key, generada con un gestor de contraseñas (recomendado 64 caracteres aleatorios; mínimo 40). Nunca versionar ni compartir esta clave. Sin ella el panel queda deshabilitado. Al rotarla se invalidan las sesiones de plataforma.
3. Actualizar y desplegar en cPanel y Vercel.
4. Abrir https://titotrazabilidad.vercel.app/?platform=1. Usar la clave privada, no las credenciales de usuario comercial.
5. Consultar empresas. Crear una empresa de prueba con RUT válido no registrado y un correo de administrador único global. Si falta el índice único global de users.email o la estructura real no admite la inserción, no se crea la empresa; revisar esquema, no quitar la validación.
6. Entrar desde el acceso comercial con ese administrador. Verificar que no vea productos, ventas, clientes, bodegas ni usuarios de otra empresa. Probar endpoints con IDs ajenos y distintos roles antes de usar datos de clientes reales.
7. La empresa arranca con dos ubicaciones vacías por compatibilidad del POS. No se copian datos comerciales, usuarios, terminales, productos ni sucursales de otra empresa.

La sesión de plataforma dura 30 minutos, se guarda separada de las sesiones comerciales y no autoriza rutas de operación. No se amplían privilegios de usuarios existentes. La clave de plataforma debe ser distinta de las contraseñas comerciales. No hay selector de empresa, acceso global a ventas, permisos por sucursal, ni administración de estado de empresa en esta entrega. No afirmar aislamiento integral hasta completar pruebas cruzadas.
