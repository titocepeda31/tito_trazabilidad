/** Helpers de importación que no requieren dependencias. */
export const headers = ['sku', 'barcode', 'name', 'price_clp', 'stock_qty', 'sale_unit'];
const hints = {
  sku: ['sku', 'codigo', 'codigointerno', 'codproducto', 'referencia'],
  barcode: ['barcode', 'codigodebarras', 'ean', 'gtin', 'codigobarra'],
  name: ['name', 'nombre', 'producto', 'descripcion', 'nombreproducto', 'articulo'],
  price_clp: ['priceclp', 'precio', 'precioventa', 'valor', 'preciounitario'],
  stock_qty: ['stockqty', 'stock', 'existencia', 'cantidad', 'inventario'],
  sale_unit: ['saleunit', 'unidad', 'unidadmedida', 'medida']
};
const simplify = (s) => String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');
export function suggestMap(columns) {
  return Object.fromEntries(headers.map((key) => [key, columns.findIndex((c) => hints[key].includes(simplify(c)))]));
}
export function csvParse(text, sep) {
  const out = []; let row = [], field = '', quoted = false;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (quoted) {
      if (ch === '"' && text[i+1] === '"') { field += '"'; i++; }
      else if (ch === '"') quoted = false;
      else field += ch;
    } else if (ch === '"') quoted = true;
    else if (ch === sep) { row.push(field); field = ''; }
    else if (ch === '\n') { row.push(field.replace(/\r$/, '')); out.push(row); row=[]; field=''; }
    else field += ch;
  }
  if (quoted) throw new Error('CSV con comillas sin cerrar.');
  if (field !== '' || row.length) { row.push(field.replace(/\r$/, '')); out.push(row); }
  return out;
}
export function guessSeparator(text) {
  const first = String(text).split(/\r?\n/).slice(0,3).join('\n');
  return [';', ',', '\t'].sort((a,b) => (first.split(b).length - first.split(a).length))[0];
}
function empty(v) { return v === null || v === undefined || String(v).trim() === ''; }
function numberFromCell(v, integer = false) {
  if (typeof v === 'number') return Number.isFinite(v) ? v : NaN;
  let s = String(v ?? '').trim().replace(/[$\s\u00a0]/g, '');
  if (!s) return NaN;
  if (/^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(s)) s = s.replace(/\./g, '').replace(',', '.');
  else if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(s)) s = s.replace(/,/g, '');
  else if (s.includes(',') && !s.includes('.')) s = s.replace(',', '.');
  const result = Number(s);
  return Number.isFinite(result) && (!integer || Number.isInteger(result)) ? result : NaN;
}
export function validateRows(data, mapping) {
  const results = [], errors = [], skus = new Map(), barcodes = new Map();
  data.forEach((line, idx) => {
    if (line.every(empty)) return;
    const cell = (key) => mapping[key] >= 0 ? line[mapping[key]] : undefined;
    const name = String(cell('name') ?? '').trim();
    // Los códigos de barras siempre son cadenas: no convertir a notación científica.
    const sku = empty(cell('sku')) ? null : String(cell('sku')).trim();
    const barcode = empty(cell('barcode')) ? null : String(cell('barcode')).trim();
    const price = numberFromCell(cell('price_clp'), true);
    const rawStock = cell('stock_qty');
    const stock = empty(rawStock) ? null : numberFromCell(rawStock);
    const rawUnit = simplify(cell('sale_unit') || 'unidad');
    const unit = ['kg', 'kilogramo', 'kilogramos'].includes(rawUnit) ? 'kg' : ['litro','litros','l'].includes(rawUnit) ? 'litro' : rawUnit === 'unidad' || rawUnit === 'unidades' || rawUnit === 'un' ? 'unidad' : null;
    const problems = [];
    if (!sku && !barcode) problems.push('Falta SKU o código de barras');
    if (!name || name.length > 120) problems.push('Nombre obligatorio (máx. 120)');
    if ((sku && sku.length > 50) || (barcode && barcode.length > 50)) problems.push('Código demasiado largo');
    if (!Number.isInteger(price) || price < 0 || price > 1000000000) problems.push('Precio CLP inválido');
    if (!unit) problems.push('Unidad no válida');
    if (stock !== null && (!Number.isFinite(stock) || stock < 0 || stock > 1000000000 || Math.round(stock * 1000) !== stock * 1000 || (unit === 'unidad' && !Number.isInteger(stock)))) problems.push('Stock inválido');
    for (const [map, code, label] of [[skus, sku, 'SKU'],[barcodes,barcode,'código de barras']]) {
      if (code) {
        const normalized = code.toLocaleLowerCase('es-CL');
        if (map.has(normalized)) problems.push(`${label} duplicado (fila ${map.get(normalized)})`);
        else map.set(normalized, idx + 2);
      }
    }
    if (problems.length) errors.push({ row: idx+2, text: problems.join(' · ') });
    results.push({ sku, barcode, name, price_clp: price, stock_qty: stock, sale_unit: unit, rowNumber: idx+2, problems });
  });
  return { rows: results, errors };
}
