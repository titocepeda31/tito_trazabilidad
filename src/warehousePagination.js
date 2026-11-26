/**
 * Paginación de los registros ya cargados desde la API.
 * No sustituye una paginación real de servidor para historiales mayores.
 */
export function paginateRows(rows,page,pageSize){
  const size=Number.isInteger(pageSize)&&pageSize>0?pageSize:10;
  const total=rows.length;
  const pages=Math.max(1,Math.ceil(total/size));
  const requested=Number.isInteger(page)?page:1;
  const current=Math.min(pages,Math.max(1,requested));
  const from=(current-1)*size;
  return {
    rows:rows.slice(from,from+size),
    total,
    page:current,
    pages,
    first:total===0?0:from+1,
    last:Math.min(total,from+size)
  };
}
