import {pesos,qtyFormat} from './api.js';

const safeText=value=>String(value??'').replace(/[\u0000-\u001f\u007f]/g,' ').trim();
const quoteLines=({quote,items},businessName)=>[
  safeText(businessName||'Negocio'),
  'COTIZACIÓN #'+quote.id,
  'Cliente: '+safeText(quote.customer_name),
  'Contacto: '+safeText(quote.customer_contact||'No indicado'),
  'Vigencia: '+safeText(quote.valid_until),
  '',
  ...items.map(i=>safeText(i.product_name_snapshot)+' — '+qtyFormat(i.quantity)+' '+safeText(i.sale_unit)+' x '+pesos(i.unit_price_clp)+' = '+pesos(i.subtotal_clp)),
  '',
  'TOTAL: '+pesos(quote.total_clp),
  quote.notes?'Observaciones: '+safeText(quote.notes):'',
  'Esta cotización no es un documento tributario del SII.'
].filter(x=>x!==null);

export function openQuoteEmail(data,businessName,to=''){
  const subject='Cotización #'+data.quote.id+' — '+safeText(businessName||'Tito POS');
  const body='Hola,\n\nTe envío la cotización solicitada:\n\n'+quoteLines(data,businessName).join('\n')+'\n\nSaludos.';
  const mailto='mailto:'+encodeURIComponent(to.trim())+'?subject='+encodeURIComponent(subject)+'&body='+encodeURIComponent(body);
  window.location.href=mailto;
}

/** PDF descargable y compartible en Android (el destinatario confirma el envío). */
export async function createQuotePdf(data,businessName){
  const {jsPDF}=await import('jspdf');
  const doc=new jsPDF({unit:'mm',format:'a4',compress:true});
  const {quote,items}=data;
  const margin=16;
  const width=178;
  let y=22;
  const nextPage=(height=8)=>{
    if(y+height>279){doc.addPage();y=20;}
  };
  const write=(raw,size=10,bold=false,color=[38,57,65])=>{
    const text=safeText(raw);
    if(!text)return;
    doc.setFont('helvetica',bold?'bold':'normal');
    doc.setFontSize(size);
    doc.setTextColor(...color);
    const lines=doc.splitTextToSize(text,width);
    const height=lines.length*(size*.43)+3;
    nextPage(height);
    doc.text(lines,margin,y);
    y+=height;
  };
  write(safeText(businessName||'Tito POS'),16,true,[11,120,91]);
  write('COTIZACIÓN #'+quote.id,17,true,[26,42,53]);
  write('Cliente: '+quote.customer_name);
  if(quote.customer_contact)write('Contacto: '+quote.customer_contact);
  write('Fecha: '+safeText(quote.created_at).slice(0,10)+'   |   Válida hasta: '+quote.valid_until);
  y+=5;
  doc.setDrawColor(204,220,225);doc.line(margin,y,194,y);y+=8;
  for(const item of items){
    const label=safeText(item.product_name_snapshot);
    write(label,11,true);
    write(qtyFormat(item.quantity)+' '+safeText(item.sale_unit)+' x '+pesos(item.unit_price_clp)+' = '+pesos(item.subtotal_clp),10);
    y+=3;
  }
  nextPage(18);
  doc.setDrawColor(204,220,225);doc.line(margin,y,194,y);y+=8;
  write('TOTAL COTIZADO: '+pesos(quote.total_clp),15,true,[11,120,91]);
  if(quote.notes){y+=4;write('Observaciones: '+quote.notes);}
  y+=7;
  write('Cotización comercial. No constituye boleta, factura ni documento tributario del SII.',8,false,[96,107,116]);
  return doc.output('blob');
}
export async function shareQuotePdf(data,businessName){
  const blob=await createQuotePdf(data,businessName);
  const name='cotizacion-'+data.quote.id+'.pdf';
  const file=new File([blob],name,{type:'application/pdf'});
  if(navigator.share&&(!navigator.canShare||navigator.canShare({files:[file]}))){
    try{
      await navigator.share({files:[file],title:'Cotización #'+data.quote.id,text:'Cotización de '+safeText(businessName||'Tito POS')});
      return 'shared';
    }catch(error){
      if(error?.name==='AbortError')return 'cancelled';
      // Si el navegador no soporta adjuntos, se guarda el PDF.
    }
  }
  const url=URL.createObjectURL(blob);
  const a=document.createElement('a');
  a.href=url;a.download=name;document.body.appendChild(a);a.click();a.remove();
  window.setTimeout(()=>URL.revokeObjectURL(url),2500);
  return 'downloaded';
}
