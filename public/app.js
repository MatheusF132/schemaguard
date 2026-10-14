'use strict';

const board = document.getElementById('board');
const svg = document.getElementById('links');
const NS = 'http://www.w3.org/2000/svg';

const FRIENDLY = {
  integer: 'número inteiro', bigint: 'número inteiro', smallint: 'número inteiro', tinyint: 'número inteiro',
  varchar: 'texto', char: 'texto', text: 'texto longo',
  decimal: 'número decimal', double: 'número decimal', real: 'número decimal', float: 'número decimal',
  boolean: 'sim ou não', date: 'data', timestamp: 'data e hora', datetime: 'data e hora', time: 'hora',
};
const SAMPLE = `CREATE TABLE patients (
  id SERIAL PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(255) UNIQUE
);
CREATE TABLE doctors (
  id SERIAL PRIMARY KEY,
  name VARCHAR(100) NOT NULL
);
CREATE TABLE appointments (
  id SERIAL PRIMARY KEY,
  patient_id INTEGER NOT NULL REFERENCES patients(id) ON DELETE CASCADE,
  doctor_id INTEGER NOT NULL REFERENCES doctors(id),
  price NUMERIC(10,2) DEFAULT 0,
  status VARCHAR(20) DEFAULT 'scheduled'
);
CREATE INDEX idx_appt_patient ON appointments (patient_id);`;

let state = { project: null, layout: {} };

const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const tag = (text, cls = '') => `<span class="tag ${cls}">${esc(text)}</span>`;

async function call(action, body = {}) {
  const res = await fetch('api.php?action=' + action, { method: 'POST', body: JSON.stringify(body) });
  const data = await res.json();
  if (data.error) throw new Error(data.error);
  return data;
}
async function run(action, body) {
  try { state = await call(action, body); render(); } catch (e) { alert('Erro: ' + e.message); }
}
const saveLayout = () => call('layout', { layout: state.layout }).catch(() => {});

function typeText(c) {
  if (c.length) return `${c.type}(${c.length})`;
  if (c.precision) return `${c.type}(${c.precision},${c.scale ?? 0})`;
  return c.type;
}

function row(c, mode, fk) {
  const target = fk.get(c.id);
  const tags = [];
  if (mode === 'beginner') {
    if (c.primaryKey) tags.push(tag('identificador único', 'pk'));
    if (target) tags.push(tag('liga com ' + target, 'fk'));
    if (!c.nullable && !c.primaryKey) tags.push(tag('obrigatório'));
    return `<div class="row" data-col="${c.id}"><span class="n">${esc(c.name)}</span><span class="t">${esc(FRIENDLY[c.type] || c.type)}</span>${tags.join('')}</div>`;
  }
  if (c.primaryKey) tags.push(tag('PK', 'pk'));
  if (target) tags.push(tag('FK', 'fk'));
  if (mode === 'advanced') {
    if (!c.nullable && !c.primaryKey) tags.push(tag('NOT NULL'));
    if (c.unique) tags.push(tag('UNIQUE'));
    if (c.autoIncrement) tags.push(tag('AUTO'));
    if (c.default !== null) tags.push(tag('= ' + c.default));
  }
  return `<div class="row" data-col="${c.id}"><span class="n">${esc(c.name)}</span><span class="t">${esc(typeText(c))}</span>${tags.join('')}</div>`;
}

function indexes(t) {
  if (!t.indexes.length) return '';
  const name = (id) => (t.columns.find((c) => c.id === id) || {}).name;
  return '<div class="idx">' + t.indexes.map((i) =>
    `<div>índice ${esc(i.name || '')} (${esc(i.expression || i.columns.map(name).join(', '))})${i.unique ? ' único' : ''}</div>`).join('') + '</div>';
}

function autoPositions(p) {
  const out = {};
  let y = 40;
  for (let i = 0; i < p.tables.length; i += 3) {
    const group = p.tables.slice(i, i + 3);
    group.forEach((t, j) => { out[t.id] = { x: 40 + j * 340, y }; });
    y += Math.max(...group.map((t) => 44 + t.columns.length * 30 + (t.indexes.length ? 30 : 0))) + 70;
  }
  return out;
}

function render() {
  const p = state.project;
  document.getElementById('mode').value = p.mode;
  document.getElementById('empty').hidden = p.tables.length > 0;
  board.querySelectorAll('.table').forEach((n) => n.remove());

  const fk = new Map(); // id da coluna -> nome da tabela de destino
  p.relationships.forEach((r) => {
    const to = p.tables.find((t) => t.id === r.toTable);
    r.fromColumns.forEach((id) => fk.set(id, to ? to.name : '?'));
  });

  const auto = autoPositions(p);
  p.tables.forEach((t) => {
    const el = document.createElement('div');
    const at = state.layout[t.id] || auto[t.id];
    el.className = 'table';
    el.dataset.id = t.id;
    el.style.left = at.x + 'px';
    el.style.top = at.y + 'px';
    el.innerHTML = `<div class="head">${esc(t.schema ? t.schema + '.' + t.name : t.name)}</div>`
      + t.columns.map((c) => row(c, p.mode, fk)).join('')
      + (p.mode === 'advanced' ? indexes(t) : '');
    el.querySelector('.head').addEventListener('pointerdown', (e) => startDrag(e, el, t.id));
    board.appendChild(el);
  });
  drawLinks();
}

function startDrag(e, el, id) {
  const sx = e.clientX, sy = e.clientY, ox = el.offsetLeft, oy = el.offsetTop;
  el.setPointerCapture(e.pointerId);
  el.classList.add('drag');
  const move = (ev) => {
    const x = Math.max(0, ox + ev.clientX - sx), y = Math.max(0, oy + ev.clientY - sy);
    el.style.left = x + 'px';
    el.style.top = y + 'px';
    state.layout[id] = { x, y };
    drawLinks();
  };
  const up = () => {
    el.removeEventListener('pointermove', move);
    el.removeEventListener('pointerup', up);
    el.classList.remove('drag');
    saveLayout();
  };
  el.addEventListener('pointermove', move);
  el.addEventListener('pointerup', up);
}

function drawLinks() {
  svg.innerHTML = '';
  let w = 0, h = 0;
  board.querySelectorAll('.table').forEach((el) => {
    w = Math.max(w, el.offsetLeft + el.offsetWidth);
    h = Math.max(h, el.offsetTop + el.offsetHeight);
  });
  svg.setAttribute('width', w + 120);
  svg.setAttribute('height', h + 120);

  const yOf = (t, r) => t.offsetTop + (r ? r.offsetTop + r.offsetHeight / 2 : t.offsetHeight / 2);
  const text = (x, y, s) => {
    const n = document.createElementNS(NS, 'text');
    n.setAttribute('x', x); n.setAttribute('y', y); n.setAttribute('class', 'card');
    n.textContent = s;
    svg.appendChild(n);
  };

  state.project.relationships.forEach((r) => {
    const a = board.querySelector(`[data-id="${r.fromTable}"]`);
    const b = board.querySelector(`[data-id="${r.toTable}"]`);
    if (!a || !b) return;
    const y1 = yOf(a, a.querySelector(`[data-col="${r.fromColumns[0]}"]`));
    const y2 = yOf(b, b.querySelector(`[data-col="${r.toColumns[0]}"]`));
    const self = a === b;
    let x1, x2, d;
    if (self) { x1 = x2 = a.offsetLeft + a.offsetWidth; d = 50; }
    else if (a.offsetLeft + a.offsetWidth / 2 <= b.offsetLeft + b.offsetWidth / 2) { x1 = a.offsetLeft + a.offsetWidth; x2 = b.offsetLeft; d = 60; }
    else { x1 = a.offsetLeft; x2 = b.offsetLeft + b.offsetWidth; d = -60; }

    const path = document.createElementNS(NS, 'path');
    path.setAttribute('class', 'link');
    path.setAttribute('d', `M${x1},${y1} C${x1 + d},${y1} ${x2 + (self ? d : -d)},${y2} ${x2},${y2}`);
    svg.appendChild(path);

    const [la, lb] = { many_to_one: ['N', '1'], one_to_many: ['1', 'N'], one_to_one: ['1', '1'], many_to_many: ['N', 'N'] }[r.cardinality];
    const s = d > 0 ? 1 : -1;
    text(x1 + s * 10, y1 - 6, la);
    text(x2 + (self ? s : -s) * 10, y2 - 6, lb);
  });
}

const dlg = document.getElementById('dlg');
const sql = document.getElementById('sql');
document.getElementById('mode').addEventListener('change', (e) => run('mode', { mode: e.target.value }));
document.getElementById('open-import').addEventListener('click', () => dlg.showModal());
document.getElementById('cancel').addEventListener('click', () => dlg.close());
document.getElementById('sample').addEventListener('click', () => { sql.value = SAMPLE; });
document.getElementById('do-import').addEventListener('click', async () => {
  if (!sql.value.trim()) return;
  await run('import', { sql: sql.value });
  dlg.close();
});

run('load');
