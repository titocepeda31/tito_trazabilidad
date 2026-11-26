// La aprobación remota autoriza completar la venta que el cajero ya confirmó.
 // La venta solo se declara realizada tras recibir confirmación de POST /sales.
export function approvedRemoteSalePayload(remotePending,status,{pendingSale=false,lastSale=false,attempted=false}={}){
  if(!remotePending||status!=='approved'||pendingSale||lastSale||attempted)return null;
  const id=Number(remotePending.id);
  const sale=remotePending.sale;
  if(!Number.isSafeInteger(id)||id<=0||!sale||typeof sale.request_id!=='string'||!Array.isArray(sale.items))return null;
  // Conservamos request_id, método de pago y detalle exactos para idempotencia.
  return {...sale,discount_request_id:id};
}
