# CLP y UF — fase 1 (no operativa)
La UF es una unidad de cuenta. El valor diario se registra por fecha exacta y empresa; las correcciones generan nuevas versiones, nunca sobrescriben el historial. La migración 038 añade preferencias y valores auditados, sin convertir ni modificar los precios CLP existentes. El panel de administración permite configurar la preferencia, registrar manualmente un valor con referencia de origen y simular una conversión. No activa aún ventas UF ni consulta automática. La vista previa usa hasta 4 decimales UF, 2 decimales en el valor diario y redondeo al peso half-up, con límite del catálogo actual. Si no existe valor para la fecha seleccionada, se rechaza la conversión.

## Siguiente fase, bloqueante para habilitar operaciones UF
- Fuente oficial automática (Banco Central), caché por fecha, validación y auditoría; nunca usar el valor de otro día silenciosamente.
- Precios de catálogo y compras: unidad y monto decimal original, base fiscal y clasificación IVA. Mantener CLP legado.
- Importación Excel/CSV: unidad por fila o lote, auditoría e idempotencia; probar mezcla CLP/UF.
- Cotizaciones: unidad explícita, monto original por línea, equivalencia referencial, política de fecha fijada al aceptar; conservar revisiones y PDF.
- Ventas directas y conversión de cotizaciones: cálculo servidor, fecha y versión UF persistidas atómicamente por operación, precio final CLP para caja y DTE. Validar descuentos, reservas, abonos y despacho parcial.
- Crédito: acordar si saldo fijo CLP o reajustable según contrato. No activar reajustes automáticos.
- Devoluciones, garantías, notas de crédito: usar importes históricos de la venta, no UF del día; DTE nacional ordinario en CLP, sin emisión hasta conciliación.
- Dashboard: totales CLP, nunca sumar directamente UF con pesos.
- Pruebas de aislamiento multiempresa, permisos, falta de tasa, redondeos, reintentos, migración y regresión CLP.
