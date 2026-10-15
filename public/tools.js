'use strict';
// Versoes, comparacao e exportacao de SQL. Usa esc() do app.js. So mostra SQL: nada e executado.

const out = document.getElementById('out');
const outBody = document.getElementById('out-body');
const RISK = { safe: 'seguro', attention: 'atenção', destructive: 'perigoso' };
const dialect = () => document.getElementById('dialect').value;
let again = null;
let ver = null;

async function tool(action, body = {}) {
  const res = await fetch('tools.php?action=' + action, { method: 'POST', body: JSON.stringify(body) });
  const data = await res.json();
  if (data.error) throw new Error(data.error);
  return data;
}

async function openOut(title, load, withDialect = true) {
  document.getElementById('out-title').textContent = title;
  document.getElementById('dialect').closest('label').hidden = !withDialect;
  again = async () => {
    try { outBody.innerHTML = await load(); }
    catch (e) { outBody.innerHTML = `<p class="hint">${esc(e.message)}</p>`; }
  };
  await again();
  if (!out.open) out.showModal();
}

document.getElementById('save-version').addEventListener('click', async () => {
  const label = prompt('Nome da versão (opcional):');
  if (label === null) return;
  try { await tool('snapshot', { label }); alert('Versão salva.'); } catch (e) { alert('Erro: ' + e.message); }
});

document.getElementById('export-sql').addEventListener('click', () =>
  openOut('SQL do esquema atual', async () => `<pre>${esc((await tool('sql', { dialect: dialect() })).sql)}</pre>`));

document.getElementById('compare').addEventListener('click', () => openOut('Comparar versões', async () => {
  const { versions } = await tool('versions');
  if (!versions.length) return '<p class="hint">Nenhuma versão salva ainda. Use "Salvar versão" primeiro.</p>';
  if (!versions.some((v) => v.id === ver)) ver = versions[0].id;
  const d = await tool('diff', { version: ver, dialect: dialect() });
  const list = d.changes.length
    ? '<ul class="changes">' + d.changes.map((c) => `<li><span class="risk ${c.risk}">${RISK[c.risk]}</span>${esc(c.text)}</li>`).join('') + '</ul>'
    : '<p class="hint">Nenhuma diferença desde essa versão.</p>';
  const options = versions.map((v) => `<option value="${v.id}"${v.id === ver ? ' selected' : ''}>${esc(v.label)} (${esc(v.createdAt.slice(0, 16).replace('T', ' '))})</option>`).join('');
  return `<label>Comparar o esquema atual com<select id="ver">${options}</select></label>${list}`
    + (d.sql ? `<h3>Migração sugerida</h3><pre>${esc(d.sql)}</pre>` : '');
}));

outBody.addEventListener('change', (e) => { if (e.target.id === 'ver') { ver = e.target.value; again(); } });
document.getElementById('dialect').addEventListener('change', () => again && again());
document.getElementById('out-close').addEventListener('click', () => out.close());
document.getElementById('copy').addEventListener('click', () => {
  const pre = outBody.querySelector('pre');
  if (pre) navigator.clipboard.writeText(pre.textContent);
});

// ---- verificacao do esquema (regras filtradas pelo modo) ----
const RANK = { beginner: 0, intermediate: 1, advanced: 2 };
let findings = [];
let seq = 0;
const visible = () => findings.filter((f) => RANK[f.level] <= RANK[state.project.mode]);

function paint() {
  const v = visible();
  document.getElementById('verify').textContent = v.length ? `Verificar (${v.length})` : 'Verificar';
  const ids = new Set(v.map((f) => f.table));
  board.querySelectorAll('.table').forEach((el) => el.classList.toggle('warn', ids.has(el.dataset.id)));
}

const prevRender = render;
render = function () {
  prevRender();
  paint();
  const mine = ++seq;
  tool('validate').then((d) => { if (mine === seq) { findings = d.findings; paint(); } }).catch(() => {});
};

document.getElementById('verify').addEventListener('click', () => openOut('Verificação do esquema', async () => {
  findings = (await tool('validate')).findings;
  paint();
  const v = visible(), mode = state.project.mode, det = mode === 'beginner' ? ' open' : '';
  if (!v.length) return '<p class="hint">Nenhum problema encontrado para este modo.</p>';
  return '<ul class="changes">' + v.map((f) => `<li>${esc(f.message)}<details${det}><summary>Por quê?</summary><p class="hint">${esc(f.explanation)}</p></details></li>`).join('') + '</ul>'
    + (mode === 'advanced' ? '' : '<p class="hint">Troque para um modo mais avançado para ver verificações mais detalhadas.</p>');
}, false));
