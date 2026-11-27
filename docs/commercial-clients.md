# Ficha comercial de clientes — fase inicial
Aplicar migración 015 en MariaDB después de 014, con respaldo. Reutiliza habitual_clients sin cambiar IDs ni relaciones. Agrega tipo de persona/empresa, RUT chileno normalizado y validado por módulo 11 (opcional, único por empresa), giro, dirección, comuna, contacto y correo de facturación. Los campos nuevos de clientes anteriores quedan vacíos hasta completarlos.
Vista comercial administrativa con búsqueda por nombre/RUT/contacto/teléfono/correo, estado y paginación de servidor. El crédito heredado conserva rutas, datos y permisos; queda en pestaña aparte. Crear cliente comercial no habilita crédito.
Pendiente: vínculo con todas las ventas y cotizaciones, historial por cliente, permisos comerciales de otros roles, contactos múltiples, DTE y condiciones de pago. No inferir RUT ni asociar registros históricos por coincidencia de nombres.
Pruebas: migración sobre copia, clientes antiguos y créditos conservados, crear persona/empresa, RUT válido e inválido/duplicado con formatos distintos, RUT nulo, aislamiento entre empresas, búsqueda, edición/estado, consulta de crédito heredado y ventas serializadas. No probadas aún en cPanel.

## Convención de interfaz
Crear y editar clientes usa una vista completa dentro del módulo, con formulario por secciones, regresar/cancelar/guardar y confirmación breve para descartar cambios. No usar modales para acciones extensas; reservarlos para confirmaciones cortas. Mantener navegación, filtros y página del listado al regresar. La gestión de crédito heredado queda fuera de este rediseño inicial.
