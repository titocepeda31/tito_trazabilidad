# Administración de empresas — actualización 003

1. Respaldar la base independiente. Ejecutar database/003_business_access.sql **antes** de publicar el backend nuevo; la autenticación comercial requiere esta tabla y falla de forma segura si falta.
2. Actualizar desde remoto y desplegar HEAD en cPanel. Comprobar Vercel.
3. Desde /?platform=1 editar datos comerciales/tributarios/contacto. Los cambios de RUT son únicamente correcciones de la misma entidad legal; no se debe reutilizar una empresa para otro contribuyente. Se conserva el ID y no se modifica el historial transaccional. Las vistas que usan datos actuales mostrarán el nombre actualizado.
4. Crear una empresa de prueba, iniciar sesión en otro navegador y desactivarla indicando motivo. Verificar que el nuevo login falle con 403 y que el token anterior no permita GET /me, /products, etc. Confirmar que la empresa original conserva acceso.
5. Reactivar y verificar que los tokens anteriores siguen inválidos, los usuarios habilitados pueden ingresar, y los usuarios deshabilitados individualmente siguen sin acceso. Revisar business_access_events y que no se hayan borrado productos ni ventas.
6. Las solicitudes autenticadas antes de una suspensión pueden terminar. No se altera el estado de caja ni se anulan operaciones. Cerrar turnos antes de suspender una empresa cuando sea posible. No se garantiza aislamiento integral hasta probar las rutas y datos reales.

La edición requiere un RUT válido. La unicidad se valida también en la ficha comercial, serializando modificaciones por GET_LOCK. El panel no permite eliminar empresas. La administración de acceso sigue separada; no se incorpora un selector de empresa.
