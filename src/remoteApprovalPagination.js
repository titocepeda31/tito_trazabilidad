const TIME_ZONE='America/Santiago';

export function approvalLocalDay(utcValue){
  if(!utcValue)return '';
  const raw=String(utcValue).trim();
  const iso=raw.includes('T')?raw:raw.replace(' ','T');
  const date=new Date(/Z$|[+-]\d{2}:?\d{2}$/.test(iso)?iso:iso+'Z');
  if(!Number.isFinite(date.getTime()))return '';
  return new Intl.DateTimeFormat('sv-SE',{timeZone:TIME_ZONE,year:'numeric',month:'2-digit',day:'2-digit'}).format(date);
}

export function approvalLocalDateTime(utcValue){
  if(!utcValue)return '';
  const raw=String(utcValue).trim();
  const iso=raw.includes('T')?raw:raw.replace(' ','T');
  const date=new Date(/Z$|[+-]\d{2}:?\d{2}$/.test(iso)?iso:iso+'Z');
  if(!Number.isFinite(date.getTime()))return '';
  return new Intl.DateTimeFormat('es-CL',{timeZone:TIME_ZONE,day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',hour12:false}).format(date);
}

export function paginateLegacyApprovals(requests,{page=1,perPage=10,status='all',from='',to=''}={}){
  const eligible=requests.filter(r=>(status==='all'||r.status===status)&&
      (!from||approvalLocalDay(r.created_at)>=from)&&
      (!to||approvalLocalDay(r.created_at)<=to));
  const pages=Math.max(1,Math.ceil(eligible.length/perPage));
  const current=Math.min(Math.max(1,page),pages);
  return {requests:eligible.slice((current-1)*perPage,current*perPage),total:eligible.length,page:current,pages,per_page:perPage,legacy:true};
}
