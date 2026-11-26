// Construido exclusivamente desde una venta ya registrada en la API.
// Nunca se usa el carrito local ni se emiten documentos tributarios.
const clean=value=>String(value??'').replace(/[\u0000-\u001f\u007f]/g,' ').trim();
const money=value=>new Intl.NumberFormat('es-CL',{style:'currency',currency:'CLP',maximumFractionDigits:0}).format(Number(value)||0);
const qtyFormat=value=>new Intl.NumberFormat('es-CL',{maximumFractionDigits:3}).format(Number(value)||0);
const niceDate=value=>{
  const raw=String(value??'').trim();
  if(!raw)return 'No registrada';
  const d=new Date(raw.replace(' ','T')+(raw.includes('Z')||/[+-]\d{2}:?\d{2}$/.test(raw)?'':'Z'));
  return Number.isNaN(d.getTime())?clean(raw):d.toLocaleString('es-CL',{timeZone:'America/Santiago',dateStyle:'medium',timeStyle:'short'});
};

export function saleVoucherContent({sale,items},businessName='Tito POS',{isCredit=false}={}){
  if(!sale||!Number.isInteger(Number(sale.id))||Number(sale.id)<=0||!Array.isArray(items)){
    throw new Error('No se recibieron los datos completos de la venta.');
  }
  const isVoid=sale.status==='voided';
  const discount=items.reduce((sum,i)=>{
    const q=Number(i.quantity),list=Number(i.list_price_clp),unit=Number(i.unit_price_clp);
    return sum+(Number.isFinite(q)&&Number.isFinite(list)&&Number.isFinite(unit)?Math.max(0,Math.round(q*(list-unit))):0);
  },0);
  const total=Number(sale.total_clp);
  if(!Number.isFinite(total)||total<0)throw new Error('Total de venta inválido.');
  return {
    businessName:clean(businessName||'Tito POS').slice(0,120),
    id:Number(sale.id),
    status:isVoid?'ANULADA':'REGISTRADA',
    createdAt:niceDate(sale.created_at),
    branch:clean(sale.warehouse_name||'Local principal'),
    seller:clean(sale.seller_email||'—'),
    payment:isCredit?'CRÉDITO - PAGO PENDIENTE':clean((sale.payment_method||'Sin especificar').toUpperCase()),
    voidReason:isVoid?clean(sale.void_reason||'Sin motivo indicado'):null,
    discount,total,
    lines:items.map(i=>({
      name:clean(i.product_name||'Producto #'+i.product_id),
      sku:clean(i.sku||''),
      quantity:qtyFormat(i.quantity),
      unitPrice:money(i.unit_price_clp),
      subtotal:money(i.subtotal_clp),
      discount:(Number(i.list_price_clp)>Number(i.unit_price_clp))?money(Math.max(0,Math.round(Number(i.quantity)*(Number(i.list_price_clp)-Number(i.unit_price_clp))))):null
    })),
    note:'COMPROBANTE INTERNO - NO ES BOLETA NI FACTURA DEL SII.'
  };
}

export async function createSaleVoucherPdf(data,businessName,options={}){
  const {jsPDF}=await import('jspdf');
  const voucher=saleVoucherContent(data,businessName,options);
  const mmWidth=80,contentWidth=68,margin=6;
  // Prepara una secuencia de renglones antes de definir el largo del rollo.
  const probe=new jsPDF({unit:'mm',format:[mmWidth,300],compress:true});
  const rows=[];
  const add=(text,{size=9,bold=false,align='left',spaceBefore=0,spaceAfter=0}={})=>{
    probe.setFont('helvetica',bold?'bold':'normal');
    probe.setFontSize(size);
    const lines=probe.splitTextToSize(clean(text)||' ',contentWidth);
    const lineHeight=Math.max(3.2,size*.43);
    rows.push({lines,size,bold,align,spaceBefore,spaceAfter,lineHeight,height:lines.length*lineHeight+spaceBefore+spaceAfter});
  };
  const divider=()=>add('----------------------------------',{size:8,spaceBefore:2,spaceAfter:1});
  add(voucher.businessName,{size:12,bold:true,align:'center',spaceAfter:2});
  add('COMPROBANTE DE VENTA #'+voucher.id,{size:9,bold:true,align:'center'});
  add(voucher.status,{size:9,bold:true,align:'center'});
  divider();
  add('Fecha: '+voucher.createdAt,{size:8});
  add('Sucursal: '+voucher.branch,{size:8});
  add('Atendió: '+voucher.seller,{size:8});
  add('Pago: '+voucher.payment,{size:8});
  divider();
  for(const item of voucher.lines){
    add(item.name,{size:9,bold:true,spaceBefore:2});
    if(item.sku)add('Código: '+item.sku,{size:7});
    add(item.quantity+' x '+item.unitPrice+' = '+item.subtotal,{size:8});
    if(item.discount)add('Descuento: -'+item.discount,{size:7});
  }
  divider();
  if(voucher.discount>0)add('Descuento total: -'+money(voucher.discount),{size:9});
  add('TOTAL: '+money(voucher.total),{size:12,bold:true,align:'center',spaceBefore:1,spaceAfter:2});
  if(options.isCredit)add('Pago pendiente. Este comprobante no acredita un cobro.',{size:8,bold:true});
  if(voucher.voidReason)add('VENTA ANULADA: '+voucher.voidReason,{size:8,bold:true});
  divider();
  add(voucher.note,{size:8,bold:true,align:'center'});
  add('Emitido desde Tito POS · Copia informativa',{size:7,align:'center',spaceBefore:2});

  const height=Math.max(100,Math.ceil(12+rows.reduce((sum,r)=>sum+r.height,0)));
  if(height>1800)throw new Error('La venta es demasiado extensa para un único voucher térmico.');
  const doc=new jsPDF({unit:'mm',format:[mmWidth,height],compress:true});
  let y=6;
  for(const r of rows){
    y+=r.spaceBefore;
    doc.setFont('helvetica',r.bold?'bold':'normal');
    doc.setFontSize(r.size);
    doc.setTextColor(25,30,34);
    for(const line of r.lines){
      doc.text(line,r.align==='center'?mmWidth/2:margin,y+r.lineHeight,{align:r.align});
      y+=r.lineHeight;
    }
    y+=r.spaceAfter;
  }
  if(options.autoPrint)doc.autoPrint();
  return doc.output('blob');
}

/** Abre el visor PDF imprimible; si el navegador impide ventanas, descarga. */
export async function openSaleVoucher(data,businessName,options={}){
  // El llamante debe reservar la pestaña sincrónicamente en el evento del botón.
  const blob=await createSaleVoucherPdf(data,businessName,{...options,autoPrint:!!options.autoPrint});
  const url=URL.createObjectURL(blob);
  const fileName='voucher-venta-'+Number(data.sale.id)+'.pdf';
  if(options.tab&&!options.tab.closed){
    try{
      options.tab.location.replace(url);
      window.setTimeout(()=>URL.revokeObjectURL(url),120000);
      return 'opened';
    }catch{
      try{options.tab.close();}catch{/* navegador restringido */}
    }
  }
  const a=document.createElement('a');
  a.href=url;a.download=fileName;document.body.appendChild(a);a.click();a.remove();
  window.setTimeout(()=>URL.revokeObjectURL(url),120000);
  return 'downloaded';
}
