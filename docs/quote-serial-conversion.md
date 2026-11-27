# Conversión directa de cotizaciones con números de serie — fase 3

No requiere SQL nuevo: utiliza migraciones 009–013 (unidades, venta serializada, reservas y reversas), 015–018 (cliente, ventas y cotizaciones). Requiere que estén aplicadas junto con sus prerrequisitos. No ejecutar las migraciones nuevamente si ya están aplicadas.

Administrador abre una cotización aceptada vigente y prepara venta en página completa. Bodega, medio de pago y series son seleccionables; cliente, productos, cantidades y precios quedan fijos. Se permiten cotizaciones normales, serializadas y mixtas, en una venta íntegra (no parciales). La lista de series muestra existencia física, no reserva series; las reservas vigentes se descuentan de la disponibilidad por cantidad al confirmar. Máximo 500 series por línea, según la función existente.

La API de conversión valida cliente activo de la empresa, caja y bodega, estado/vigencia, producto activo y unidad, cantidad exacta de series, disponibilidad física y libre, conciliación de inventario y series ajenas. Utiliza serialSaleRecord, serialReconcile, serialRequireFree y titoSellSerialLocationStock existentes, guarda serial_sale_snapshots antes de las líneas serializadas y conserva quote_items como única fuente de precios. Todo el registro de venta, stock, series, caja y evento de conversión es transaccional. Una cotización solo puede tener una venta. Reintento idéntico devuelve la venta existente; si cambian series, pago o bodega, rechaza. Los eventos antiguos sin hash mantienen compatibilidad para reintento normal.

Pruebas pendientes:
- normal, serializada, mixta: verificar precios pactados aunque catálogo cambie, cliente, stock, series, caja, vínculo y eventos;
- series faltantes, sobrantes, repetidas, fuera de bodega, vendidas, ajenas al producto y stock libre insuficiente por reservas; comprobar rollback íntegro;
- cambios concurrentes, misma cotización dos veces, reintento tras pérdida de respuesta, cambio de pago/bodega/series luego de convertir;
- cotización vencida, sin aceptación, cliente inactivo o ajeno, permisos/caja, producto inactivo o unidad modificada;
- devolución/anulación y reventa de unidades que proceden de una cotización.
No se ejecutaron las pruebas funcionales en cPanel/MariaDB. Reserva desde cotización, despacho asociado y conversión parcial siguen pendientes. No se emiten DTE.
