import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const md = readFileSync(resolve(here, '../../../../MODULO_INVENTARIO_JADEONE.md'), 'utf8');

function section(start, end) {
  const i = md.indexOf(start);
  if (i < 0) throw new Error(`No está la sección ${start}`);
  const j = md.indexOf(end, i + start.length);
  if (j < 0) throw new Error(`No está el cierre ${end}`);
  return md.slice(i, j);
}

function tables(text) {
  const rows = [];
  let header = null;
  for (const line of text.split(/\r?\n/)) {
    if (!line.startsWith('|')) {
      header = null;
      continue;
    }
    const cells = line.trim().replace(/^\||\|$/g, '').split('|').map((c) =>
      c.replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim()
    );
    if (cells.every((c) => /^[-: ]*$/.test(c))) continue;
    if (!header) {
      header = cells;
      continue;
    }
    while (cells.length < header.length) cells.push('');
    const row = {};
    header.forEach((key, idx) => { row[key] = cells[idx]; });
    rows.push(row);
  }
  return rows;
}

const ESTAR_P = new Set(['5', '6', '13']);
const PERSONAL_P = new Set(['17', '20', '22', '23']);
const ESTAR_U = new Set(['N01', 'N03G', 'N04', 'N07', 'E01', 'E06', 'N40', 'N21', 'N22']);
const PERSONAL_U = new Set([
  'N01', 'N0304', 'N03G', 'N03P', 'N03R', 'N04', 'N05', 'N07', 'N08',
  'E01', 'E0301', 'E0302', 'E0303', 'E0304', 'E06', 'E10', 'E12',
  'N40', 'N09', 'N14', 'N15', 'N18', 'N21', 'N22', 'N23', 'N24',
  'N37', 'N1001', 'N16',
]);

const prendas = [];
for (const row of tables(section('### Prendas', '### Constantes duplicadas'))) {
  const nombre = (row['Nombre'] || '').trim();
  if (!nombre) continue;
  const codigo = (row['Código'] || '').trim();
  const estado = (row['Estado'] || '').toLowerCase().startsWith('vigen') ? 1 : 0;
  const regla = ESTAR_P.has(codigo) ? 'estar_medico' : PERSONAL_P.has(codigo) ? 'personal' : 'cama';
  prendas.push({ codigo, nombre, estado, regla });
}

const unidades = [];
for (const row of tables(section('### Unidades vigentes', '### Prendas'))) {
  const codigo = (row['Código'] || '').trim();
  if (!codigo) continue;
  unidades.push({
    codigo,
    nombre: (row['Unidad'] || '').trim(),
    camas: Number(row['Camas'] || 0),
    camas_medico: Number(row['Camas médico'] || 0),
    personal: Number(row['Personal'] || 0),
    aplica_estar_medico: ESTAR_U.has(codigo),
    aplica_personal: PERSONAL_U.has(codigo),
  });
}

const ganadoras = new Map();
for (const row of tables(section('### Constantes vigentes', '## 6. Documento'))) {
  const origen = Number(row['ID detalle']);
  const key = `${(row['Código'] || '').trim()}|${(row['Código unidad'] || '').trim()}`;
  const actual = ganadoras.get(key);
  if (!actual || origen > actual.origen_id) {
    const valor = Number(String(row['Constante'] || '0').replace(',', '.'));
    ganadoras.set(key, {
      prenda_codigo: (row['Código'] || '').trim(),
      unidad_codigo: (row['Código unidad'] || '').trim(),
      constante: valor.toFixed(2),
      origen_id: origen,
    });
  }
}

const constantes = [...ganadoras.values()].sort((a, b) => a.origen_id - b.origen_id);
const cortina = constantes.find((c) => c.prenda_codigo === '36' && c.unidad_codigo === 'N01');
const bata = constantes.find((c) => c.prenda_codigo === '17' && c.unidad_codigo === 'F08');
const sabana = constantes.find((c) => c.prenda_codigo === '13' && c.unidad_codigo === 'F12');
if (!cortina || cortina.constante !== '1.20' || cortina.origen_id !== 705) throw new Error(JSON.stringify(cortina));
if (!bata || bata.constante !== '2.00' || bata.origen_id !== 615) throw new Error(JSON.stringify(bata));
if (!sabana || sabana.constante !== '0.30' || sabana.origen_id !== 664) throw new Error(JSON.stringify(sabana));
if (prendas.filter((p) => p.regla === 'estar_medico').length !== 3) throw new Error('reglas estar');
if (prendas.filter((p) => p.regla === 'personal').length !== 4) throw new Error('reglas personal');
if (unidades.filter((u) => u.aplica_estar_medico).length !== 9) throw new Error('flags estar');
if (unidades.filter((u) => u.aplica_personal).length !== 29) throw new Error('flags personal');

writeFileSync(resolve(here, 'ropa_hospitalaria_catalogo.json'), JSON.stringify({ prendas, unidades, constantes }, null, 2));
console.log(`prendas=${prendas.length} unidades=${unidades.length} constantes=${constantes.length}`);
