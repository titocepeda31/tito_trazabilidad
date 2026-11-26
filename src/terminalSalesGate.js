/** Protección de ventas en la interfaz: nunca asumir Local 1 sin terminal validado.
 * La barrera de seguridad del servidor se habilita por separado con la
 * política "Exigir caja identificada" una vez autorizados los equipos.
 */
export function terminalVerified(status) {
  return status?.feature_ready===true && status?.terminal!=null &&
    Number(status.terminal.warehouse_id)>0;
}

/** Solo las mutaciones que pueden generar una venta o reservar autorización
 * monetaria requieren comprobación nueva antes de enviar la operación.
 * Otras pantallas (inventario, historial y activación QR) siguen accesibles.
 */
export function mustVerifyTerminalBeforeMutation(path,method='GET') {
  if(String(method).toUpperCase()!=='POST')return false;
  return path==='/sales'||path==='/credit/sales'||
    /^\/quotes\/[1-9]\d*\/convert$/.test(path)||
    /^\/credit\/accounts\/[1-9]\d*\/(payments|refunds)$/.test(path)||
    /^\/returns\/[1-9]\d*\/confirm-refund$/.test(path)||
    path==='/sales/discount-approve'||path==='/sales/delegated-discount-approve';
}

/** Solo una sesión de efectivo ABIERTA, propia y de la misma sucursal
 * identificada por la API autoriza operaciones de venta desde el navegador.
 */
export function cashSessionVerified(cash,status){
  if(!terminalVerified(status)||cash?.session?.status!=='open')return false;
  const branch=Number(status.terminal.warehouse_id);
  return Number(cash.warehouse_id)===branch &&
    Number(cash.session.warehouse_id)===branch &&
    Number(cash.session.id)>0;
}
