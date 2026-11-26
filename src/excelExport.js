/**
 * Exportación XLSX nativa. Evita CSV delimitado/charset ambiguo en Excel móvil.
 * Los códigos se guardan como texto para respetar ceros iniciales.
 */
export async function saveExcel(name, columns, rows, sheetName='Inventario'){
  const packageModule=await import('exceljs');
  const ExcelJS=packageModule.default||packageModule;
  const workbook=new ExcelJS.Workbook();
  workbook.creator='Tito POS';
  workbook.created=new Date();
  const sheet=workbook.addWorksheet(sheetName,{views:[{state:'frozen',ySplit:1}]});
  sheet.columns=columns.map(c=>({header:c.title,key:c.key,width:c.width||20}));
  for(const row of rows){
    const normalized={};
    for(const c of columns){
      const value=row[c.key];
      normalized[c.key]=c.type==='number'?(value===''||value===null||value===undefined?null:Number(value)):String(value??'');
    }
    sheet.addRow(normalized);
  }
  const first=sheet.getRow(1);
  first.height=24;
  first.font={name:'Calibri',size:11,bold:true,color:{argb:'FFFFFFFF'}};
  first.fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF116C5D'}};
  first.alignment={vertical:'middle'};
  sheet.autoFilter={from:{row:1,column:1},to:{row:1,column:columns.length}};
  for(const [idx,c] of columns.entries()){
    const col=sheet.getColumn(idx+1);
    if(c.type==='number')col.numFmt=c.decimal?'#,##0.000':'#,##0';
    else col.numFmt='@';
    col.alignment={vertical:'middle',horizontal:c.type==='number'?'right':'left'};
  }
  const buffer=await workbook.xlsx.writeBuffer();
  const blob=new Blob([buffer],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
  const url=URL.createObjectURL(blob);
  const link=document.createElement('a');
  link.href=url;link.download=name;document.body.appendChild(link);link.click();link.remove();
  window.setTimeout(()=>URL.revokeObjectURL(url),2500);
}
