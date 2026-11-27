# Búsqueda y política de devoluciones

- Nueva devolución e Historial son vistas separadas.
- El buscador consulta las ventas directas vigentes de la empresa (número interno de venta, nombre de cliente registrado, nombre de producto, SKU, código de barras o serie exacta). La serie resuelve su última venta sin reversión y se preselecciona al elegir el resultado. Las ventas a crédito conservan su flujo separado.
- Se muestran días calendario desde la venta en America/Santiago. No son un vencimiento legal: en caso de entrega posterior, comprobar la recepción. Sin plazo comercial fijo ni bloqueo automático; la empresa decide conforme a su política informada.
- Garantía legal en Chile: por regla general, seis meses desde recepción del producto cuando procede; reparación, cambio o devolución del dinero a elección del consumidor. La política comercial no limita este derecho. Retracto: normalmente 10 días en ventas a distancia y otros casos legales, sujeto a las reglas aplicables. El flujo específico de retracto y el seguimiento completo de reparaciones/cambios quedan pendientes; no confundirlos con garantía por falla.
- El sistema registra devoluciones y reembolsos; no emite ni anula documentos tributarios ni efectúa pagos. No se busca por folio tributario porque aún no existe un vínculo verificado con ese dato.
- Requiere migración 013 existente y despliegue de backend y frontend; no agrega migración.
- Pruebas pendientes en ambiente real: serie revendida, serie devuelta, códigos ambiguos, venta antigua, aislamiento por empresa, devolución comercial/garantía, historial y reembolso.
