'use strict';
// Edicao de tabelas, colunas e relacionamentos. Usa state, render, call, run e board do app.js.

let selected = null;
let usageData = []; // usos no codigo (preenchido pelo tools.js)
const panel = document.getElementById('panel');
const TYPES = ['integer', 'bigint', 'smallint', 'varchar', 'char', 'text', 'decimal', 'double', 'boolean', 'date', 'timestamp', 'uuid', 'json'];
const ACTIONS = [['no_action', 'nada'], ['cascade', 'apagar junto'], ['restrict', 'impedir'], ['set_null', 'deixar vazio']];
const CARDS = [['many_to_one', 'muitos para um'], ['one_to_many', 'um para muitos'], ['one_to_one', 'um para um']];

const uid = (p) => p + '_' + Array.from(crypto.getRandomValues(new Uint8Array(6)), (b) => b.toString(16).padStart(2, '0')).join('');
const cur = () => state.project && state.project.tables.find((t) => t.id === selected);
const cname = (t, id) => ((t && t.columns.find((c) => c.id === id)) || {}).name || '?';
const opts = (list, sel) => list.map(([v, l]) => `<option value="${v}"${v === sel ? ' selected' : ''}>${esc(l)}</option>`).join('');
const colOptions = (t) => t ? opts([...t.columns].sort((a, b) => b.primaryKey - a.primaryKey).map((c) => [c.id, c.name])) : '';

function uniqueName(list, base) {
  let i = list.length + 1;
  while (list.some((x) => x.name === `${base}_${i}`)) i++;
  return `${base}_${i}`;
}

async function commit() {
  try { state = await call('save', { project: state.project }); render(); }
  catch (e) { alert('Erro: ' + e.message); run('load'); }
}

// selecionar so troca o destaque e o painel: o mapa nao e reconstruido (evita "pulos" ao clicar)
function select(id) {
  selected = id;
  board.querySelectorAll('.table').forEach((el) => el.classList.toggle('sel', el.dataset.id === selected));
  drawPanel();
}

const baseRender = render;
render = function () {
  baseRender();
  select(cur() ? selected : null);
};

function drawPanel() {
  const t = cur();
  if (!t) { // o painel fica sempre visivel, assim o mapa nunca muda de lugar ao selecionar
    const list = state.project.tables.map((x) => `<button type="button" class="ghost pick" data-act="select" data-id="${x.id}">${esc(x.name)}</button>`).join('');
    panel.innerHTML = `<h2>Tabelas</h2>${list ? `<div class="picks">${list}</div>` : ''}<p class="hint">Clique numa tabela do mapa para editá-la e arraste para movê-la. Segure Espaço e arraste o fundo para navegar pelo mapa.</p>`;
    return;
  }
  const p = state.project, beginner = p.mode === 'beginner';
  const uses = usageData.filter((u) => u.table === t.id);
  const useBadge = (c) => {
    const n = uses.filter((u) => u.column === c.id).length;
    return n ? `<span class="uses" title="Lugares do código que usam esta coluna">${n} uso${n > 1 ? 's' : ''} no código</span>` : '';
  };
  const usageHtml = uses.length
    ? `<details><summary>${uses.length} lugar${uses.length > 1 ? 'es' : ''} no código</summary><ul class="impact">${uses.slice(0, 40).map((u) => `<li><code>${esc(u.file)}:${u.line}</code> ${esc(u.op)}</li>`).join('')}</ul></details>`
    : '<p class="hint">Nenhum uso encontrado, ou o código ainda não foi analisado (botão Analisar código).</p>';
  const L = beginner ? ['identificador único', 'obrigatório', 'valor único'] : ['PK', 'NOT NULL', 'UNIQUE'];

  const cols = t.columns.map((c) => {
    const types = TYPES.includes(c.type) ? TYPES : [...TYPES, c.type];
    const len = ['varchar', 'char'].includes(c.type)
      ? `<input type="number" min="1" data-f="length" data-col="${c.id}" value="${c.length ?? ''}" aria-label="tamanho">` : '<span></span>';
    const flag = (f, label, on) => `<label><input type="checkbox" data-f="${f}" data-col="${c.id}"${on ? ' checked' : ''}>${label}</label>`;
    return `<div class="col">
      <input type="text" data-f="name" data-col="${c.id}" value="${esc(c.name)}" aria-label="nome da coluna">
      <select data-f="type" data-col="${c.id}" aria-label="tipo">${opts(types.map((x) => [x, x]), c.type)}</select>
      ${len}
      <button type="button" class="ghost" data-act="del-col" data-col="${c.id}" aria-label="apagar coluna">✕</button>
      <div class="flags">${flag('primaryKey', L[0], c.primaryKey)}${flag('required', L[1], !c.nullable)}${flag('unique', L[2], c.unique)}${useBadge(c)}</div>
    </div>`;
  }).join('');

  const rels = p.relationships.filter((r) => r.fromTable === t.id || r.toTable === t.id).map((r) => {
    const a = p.tables.find((x) => x.id === r.fromTable), b = p.tables.find((x) => x.id === r.toTable);
    return `<div class="rel"><span>${esc(a.name)}.${esc(cname(a, r.fromColumns[0]))} → ${esc(b.name)}.${esc(cname(b, r.toColumns[0]))}</span>
      <button type="button" class="ghost" data-act="del-rel" data-rel="${r.id}" aria-label="apagar relacionamento">✕</button></div>`;
  }).join('');

  const form = t.columns.length ? `<div class="form">
      <label>Coluna desta tabela<select id="rel-from">${colOptions(t)}</select></label>
      <label>Aponta para a tabela<select id="rel-to-table">${opts(p.tables.map((x) => [x.id, x.name]))}</select></label>
      <label>Coluna de destino<select id="rel-to-col">${colOptions(p.tables[0])}</select></label>
      <label>Tipo<select id="rel-card">${opts(CARDS)}</select></label>
      <label>Ao apagar o registro de destino<select id="rel-del">${opts(ACTIONS)}</select></label>
      <button type="button" data-act="add-rel">Adicionar relacionamento</button></div>` : '';

  panel.innerHTML = `<h2>Tabela</h2>
    <input type="text" data-f="tname" value="${esc(t.name)}" aria-label="nome da tabela">
    <h3>Colunas</h3>
    ${beginner ? '<p class="hint">Identificador único é o que diferencia uma linha das outras, como o número de um paciente.</p>' : ''}
    ${cols}
    <button type="button" data-act="add-col">Adicionar coluna</button>
    <h3>Relacionamentos</h3>
    ${beginner ? '<p class="hint">Um relacionamento liga uma coluna desta tabela ao identificador de outra tabela.</p>' : ''}
    ${rels || '<p class="hint">Nenhum ainda.</p>'}
    ${form}
    <h3>Uso no código</h3>
    ${usageHtml}
    <button type="button" class="danger" data-act="del-table">Apagar tabela</button>`;
}

board.addEventListener('click', (e) => {
  const el = e.target.closest('.table');
  select(el ? el.dataset.id : null);
});

document.getElementById('new-table').addEventListener('click', async () => {
  const p = state.project;
  const t = { id: uid('tbl'), name: uniqueName(p.tables, 'table'), columns: [{ id: uid('col'), name: 'id', type: 'integer', nullable: false, primaryKey: true, autoIncrement: true }], indexes: [] };
  p.tables.push(t);
  selected = t.id;
  state.layout[t.id] = { x: board.scrollLeft + 60, y: board.scrollTop + 60 };
  await saveLayout();
  commit();
});

panel.addEventListener('change', (e) => {
  const el = e.target, t = cur(), p = state.project;
  if (el.id === 'rel-to-table') {
    document.getElementById('rel-to-col').innerHTML = colOptions(p.tables.find((x) => x.id === el.value));
    return;
  }
  const f = el.dataset.f;
  if (!t || !f) return;
  if (f === 'tname') {
    const v = el.value.trim();
    if (!v || p.tables.some((x) => x.id !== t.id && x.name.toLowerCase() === v.toLowerCase())) { alert('Dê à tabela um nome que ainda não exista.'); return render(); }
    t.name = v;
  } else {
    const c = t.columns.find((x) => x.id === el.dataset.col);
    const v = el.value.trim();
    if (f === 'name') {
      if (!v || t.columns.some((x) => x.id !== c.id && x.name.toLowerCase() === v.toLowerCase())) { alert('Dê à coluna um nome que ainda não exista nesta tabela.'); return render(); }
      c.name = v;
    } else if (f === 'type') {
      Object.assign(c, { type: v, length: null, precision: null, scale: null, rawType: null });
      if (['varchar', 'char'].includes(v)) c.length = 255;
      if (v === 'decimal') Object.assign(c, { precision: 10, scale: 2 });
    } else if (f === 'length') {
      c.length = v ? parseInt(v, 10) : null;
    } else if (f === 'required') {
      c.nullable = !el.checked;
    } else {
      c[f] = el.checked;
      if (f === 'primaryKey' && el.checked) c.nullable = false;
    }
  }
  commit();
});

panel.addEventListener('click', (e) => {
  const b = e.target.closest('[data-act]');
  if (!b) return;
  const t = cur(), p = state.project, a = b.dataset.act;
  if (a === 'select') { select(b.dataset.id); return; }
  if (a === 'add-col') {
    t.columns.push({ id: uid('col'), name: uniqueName(t.columns, 'column'), type: 'varchar', length: 255 });
  } else if (a === 'del-col') {
    const id = b.dataset.col;
    const used = p.relationships.some((r) => r.fromColumns.includes(id) || r.toColumns.includes(id));
    if (used && !confirm('Esta coluna participa de relacionamentos, que também serão apagados. Continuar?')) return;
    t.columns = t.columns.filter((c) => c.id !== id);
    p.relationships = p.relationships.filter((r) => !r.fromColumns.includes(id) && !r.toColumns.includes(id));
    t.indexes.forEach((i) => { i.columns = i.columns.filter((x) => x !== id); });
    t.indexes = t.indexes.filter((i) => i.columns.length || i.expression);
  } else if (a === 'del-rel') {
    p.relationships = p.relationships.filter((r) => r.id !== b.dataset.rel);
  } else if (a === 'add-rel') {
    const v = (id) => document.getElementById(id).value;
    const to = p.tables.find((x) => x.id === v('rel-to-table'));
    if (!v('rel-from') || !to || !v('rel-to-col')) { alert('Escolha a coluna de origem, a tabela e a coluna de destino.'); return; }
    p.relationships.push({ id: uid('ref'), fromTable: t.id, fromColumns: [v('rel-from')], toTable: to.id, toColumns: [v('rel-to-col')], cardinality: v('rel-card'), onDelete: v('rel-del'), onUpdate: 'no_action' });
  } else if (a === 'del-table') {
    if (!confirm(`Apagar a tabela ${t.name} e os relacionamentos dela?`)) return;
    p.tables = p.tables.filter((x) => x.id !== t.id);
    p.relationships = p.relationships.filter((r) => r.fromTable !== t.id && r.toTable !== t.id);
    p.groups.forEach((g) => { g.tables = g.tables.filter((x) => x !== t.id); });
    delete state.layout[t.id];
    selected = null;
  }
  commit();
});
