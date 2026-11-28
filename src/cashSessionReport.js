const str=value=>String(value??'').replace(/[\u0000-\u001f\u007f]/g,' ').trim();
const amount=value=>new Intl.NumberFormat('es-CL',{style:'currency',currency:'CLP',maximumFractionDigits:0}).format(Number(value)||0);
export function buildCashReport({session,movements=[]}){
  if(!session||!Number.isInteger(Number(session.id))||Number(session.id)<1)throw new Error('Rendición inválida.');
  return {
    id:Number(session.id),status:session.status,
    drawer:str(session.drawer_name),code:str(session.drawer_code),
    branch:str(session.warehouse_name),cashier:str(session.cashier_email),
    opened:str(session.opened_at)+' UTC',closed:session.closed_at?str(session.closed_at)+' UTC':'Abierta',
    opening:Number(session.opening_clp),cashSales:Number(session.cash_sales_clp),
    creditReceived:Number(session.credit_received_cash_clp||0)+Number(session.quote_advance_cash_clp||0),
    customerRefunds:Number(session.customer_refunds_cash_clp||0),
    creditRefunds:Number(session.credit_refunds_cash_clp||0)+Number(session.quote_advance_refund_cash_clp||0),
    withdrawals:Number(session.withdrawals_clp),expected:Number(session.status==='closed'?session.closing_expected_clp:session.current_expected_clp),
    counted:session.status==='closed'?Number(session.closing_counted_clp):null,
    difference:session.status==='closed'?Number(session.closing_difference_clp):null,
    reason:str(session.closing_reason||''),paymentMethods:session.sales_by_method||[],
    creditPaymentsByMethod:session.credit_payments_by_method||[],
    customerRefundsByMethod:session.customer_refunds_by_method||[],
    creditRefundsByMethod:session.credit_refunds_by_method||[],
    moves:movements.slice(0,200),note:'RENDICIÓN INTERNA - NO ES DOCUMENTO TRIBUTARIO NI CONSTANCIA BANCARIA.'
  };
}
export async function createCashReportPdf(data,{autoPrint=false}={}){
  const {jsPDF}=await import('jspdf');
  const r=buildCashReport(data),probe=new jsPDF({unit:'mm',format:[80,300]});
  const rows=[];
  function add(value,opts={}){
    const size=opts.size||9,bold=!!opts.bold;
    probe.setFont('helvetica',bold?'bold':'normal');probe.setFontSize(size);
    const lines=probe.splitTextToSize(str(value)||' ',68);
    const lh=Math.max(3.6,size*.46);
    rows.push({lines,size,bold,lh,space:opts.space||0,center:!!opts.center});
  }
  add('TITO POS · RENDICIÓN DE CAJA',{size:11,bold:true,center:true,space:3});
  add('Turno #'+r.id+' · '+(r.status==='closed'?'CERRADO':'ABIERTO'),{center:true,bold:true});
  add('---------------------------------');
  add('Sucursal: '+r.branch);add('Gaveta: '+r.drawer+' ('+r.code+')');
  add('Cajero: '+r.cashier);add('Apertura: '+r.opened);add('Cierre: '+r.closed);
  add('---------------------------------');
  add('Fondo inicial: '+amount(r.opening));
  add('Ventas en efectivo: +'+amount(r.cashSales));
  add('Abonos de fiados: +'+amount(r.creditReceived));
  add('Reembolsos por productos: -'+amount(r.customerRefunds));
  add('Reembolsos de crédito: -'+amount(r.creditRefunds));
  add('Recaudaciones: -'+amount(r.withdrawals));
  add('EFECTIVO ESPERADO: '+amount(r.expected),{size:11,bold:true,space:3});
  if(r.status==='closed'){
    add('EFECTIVO CONTADO: '+amount(r.counted),{bold:true});
    add('DIFERENCIA: '+amount(r.difference),{bold:true,size:11,space:2});
    if(r.reason)add('Motivo: '+r.reason);
  }
  add('---------------------------------');
  add('VENTAS REGISTRADAS POR MEDIO',{bold:true});
  for(const p of r.paymentMethods)add(str(p.payment_method)+' · '+p.n+': '+amount(p.total));
  add('ABONOS DE FIADOS POR MEDIO',{bold:true,space:1});
  for(const p of r.creditPaymentsByMethod)add(str(p.method)+' · '+p.n+': '+amount(p.total));
  add('DEVOLUCIONES DE PRODUCTOS',{bold:true,space:1});
  for(const p of r.customerRefundsByMethod)add(str(p.method)+' · '+p.n+': -'+amount(p.total));
  add('REEMBOLSOS DE CRÉDITO',{bold:true,space:1});
  for(const p of r.creditRefundsByMethod)add(str(p.method)+' · '+p.n+': -'+amount(p.total));
  add('---------------------------------');
  add('Movimientos de dinero (hasta 200)',{bold:true});
  for(const m of r.moves){
    const label=m.movement_type==='cash_sale'?'Venta #'+m.source_sale_id:
      m.movement_type==='credit_payment'?'Abono #'+m.source_credit_payment_id:
      m.movement_type==='customer_refund'?'Devolución #'+m.source_customer_return_id:
      m.movement_type==='credit_refund'?'Reembolso crédito #'+m.source_credit_refund_id:m.movement_type==='quote_advance'?'Anticipo #'+m.source_quote_advance_id:m.movement_type==='quote_advance_refund'?'Reembolso anticipo #'+m.source_quote_advance_id:'Retiro / ingreso';
    add(label+': '+amount(m.amount_delta_clp),{size:8});
  }
  add('---------------------------------');
  add(r.note,{size:8,bold:true,center:true,space:2});
  add('Los fiados no pagados no suman efectivo. Tarjetas y transferencias requieren conciliación bancaria independiente.',{size:8,center:true});
  let height=14+rows.reduce((sum,x)=>sum+x.lines.length*x.lh+x.space,0);
  if(height>1800)throw new Error('Rendición demasiado larga para una página.');
  const doc=new jsPDF({unit:'mm',format:[80,Math.max(100,Math.ceil(height))],compress:true});
  let y=6;
  for(const r of rows){
    y+=r.space;doc.setFont('helvetica',r.bold?'bold':'normal');doc.setFontSize(r.size);
    for(const line of r.lines){y+=r.lh;doc.text(line,r.center?40:6,y,{align:r.center?'center':'left'});}
  }
  if(autoPrint)doc.autoPrint();
  return doc.output('blob');
}
export async function printCashReport(data,tab){
  const blob=await createCashReportPdf(data,{autoPrint:true});
  const url=URL.createObjectURL(blob);
  if(tab&&!tab.closed){tab.location.replace(url);window.setTimeout(()=>URL.revokeObjectURL(url),120000);return;}
  const a=document.createElement('a');a.href=url;a.download='rendicion-caja-'+Number(data.session.id)+'.pdf';
  document.body.appendChild(a);a.click();a.remove();window.setTimeout(()=>URL.revokeObjectURL(url),120000);
}
