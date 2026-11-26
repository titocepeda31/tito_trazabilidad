# Empresas y sucursales — fase 1

1. Respaldar exclusivamente la base maderas7_ttrazabilidad.
2. En phpMyAdmin importar database/001_organization.sql. Las tablas se crean en la base independiente. No ejecutar las migraciones antiguas de Tito POS.
3. Actualizar y desplegar HEAD en cPanel; Vercel publica el frontend desde GitHub.
4. Entrar como administrador y abrir Empresas y sucursales.
5. Completar ficha, crear sucursal y asignar bodegas reales (no hay asignaciones automáticas).
6. Verificar que inventario, caja y ventas se mantienen intactos.

Pruebas de aceptación: cajero recibe 403 en cada ruta de organización; IDs de otro negocio no son accesibles; código duplicado da 409; no se desactiva una sucursal con bodegas asociadas; un RUT incorrecto da 422; no hay cambios de stock. Verificar con dos empresas de prueba antes de ampliar alcance.

La identidad del negocio de sesión sigue siendo users.business_id; no se permite crear ni cambiar de empresa desde un administrador normal. La provisión de empresas nuevas, los permisos específicos por sucursal y el registro histórico de sucursal en transacciones quedan para la fase siguiente. No se asigna por defecto la bodega local ni se modifica la lógica de caja.
