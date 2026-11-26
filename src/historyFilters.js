// Filtros sobre registros ya cargados. Las fechas del API se guardan en UTC.
// No presentar estos resultados como búsqueda histórica completa del servidor.
export function chileDay(value){
  if(value===null||value===undefined||value==='')return null;
  let date;
  if(value instanceof Date)date=value;
  else {
    const raw=String(value).trim().replace(' ','T');
    date=new Date(/(?:Z|[+-]\d\d:\d\d)$/i.test(raw)?raw:raw+'Z');
  }
  if(!Number.isFinite(date.valueOf()))return null;
  const parts=Object.fromEntries(new Intl.DateTimeFormat('en-US',{
    timeZone:'America/Santiago',year:'numeric',month:'2-digit',day:'2-digit'
  }).formatToParts(date).filter(p=>p.type!=='literal').map(p=>[p.type,p.value]));
  return parts.year+'-'+parts.month+'-'+parts.day;
}

export function inChilePeriod(value,period,now=new Date()){
  if(period==='all')return true;
  const day=chileDay(value),today=chileDay(now);
  if(!day||!today)return false;
  if(period==='today')return day===today;
  if(period==='month')return day.slice(0,7)===today.slice(0,7);
  if(period==='week'){
    const start=new Date(today+'T00:00:00Z');
    const daysFromMonday=(start.getUTCDay()+6)%7;
    start.setUTCDate(start.getUTCDate()-daysFromMonday);
    return day>=start.toISOString().slice(0,10)&&day<=today;
  }
  return false;
}

export function pageSlice(items,page,pageSize=20){
  const safeSize=Math.max(1,Math.trunc(pageSize)||20);
  const total=items.length;
  const pages=Math.max(1,Math.ceil(total/safeSize));
  const current=Math.max(1,Math.min(pages,Math.trunc(Number(page))||1));
  return {rows:items.slice((current-1)*safeSize,current*safeSize),page:current,pages,total};
}
