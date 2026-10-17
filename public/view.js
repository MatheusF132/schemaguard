'use strict';
// Navegar no mapa (arrastar o fundo) e alternar entre tema claro e escuro.

const themeBtn = document.getElementById('theme');
function setTheme(t) {
  document.documentElement.dataset.theme = t;
  try { localStorage.setItem('theme', t); } catch (e) { /* sem armazenamento: so nao lembra */ }
  themeBtn.textContent = t === 'dark' ? 'Tema claro' : 'Tema escuro';
}
setTheme(document.documentElement.dataset.theme || 'dark');
themeBtn.addEventListener('click', () => setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'));

let pan = null;
let justPanned = false;
board.addEventListener('pointerdown', (e) => {
  if (e.button !== 0 || e.target.closest('.table') || !document.body.classList.contains('space')) return; // o mapa so se move com Espaço
  pan = { x: e.clientX, y: e.clientY, left: board.scrollLeft, top: board.scrollTop, moved: false };
  board.setPointerCapture(e.pointerId);
  board.classList.add('panning');
});
board.addEventListener('pointermove', (e) => {
  if (!pan) return;
  const dx = e.clientX - pan.x, dy = e.clientY - pan.y;
  if (Math.abs(dx) + Math.abs(dy) > 3) pan.moved = true;
  board.scrollLeft = pan.left - dx;
  board.scrollTop = pan.top - dy;
});
function endPan() {
  if (!pan) return;
  justPanned = pan.moved;
  pan = null;
  board.classList.remove('panning');
}
board.addEventListener('pointerup', endPan);
board.addEventListener('pointercancel', endPan);
// depois de arrastar o mapa, o "click" que o navegador dispara nao deve desselecionar a tabela
board.addEventListener('click', (e) => { if (justPanned) e.stopImmediatePropagation(); justPanned = false; }, true);

// largura do painel: arrastar a divisoria (cursor col-resize) ou usar as setas do teclado
const resizer = document.getElementById('resizer');
const setPanelWidth = (w) => document.documentElement.style.setProperty('--panel-w', Math.min(640, Math.max(260, Math.round(w))) + 'px');
const saveWidth = () => { try { localStorage.setItem('panelWidth', String(panel.offsetWidth)); } catch (e) { /* ok */ } };
try { const w = parseInt(localStorage.getItem('panelWidth'), 10); if (w) setPanelWidth(w); } catch (e) { /* ok */ }

resizer.addEventListener('pointerdown', (e) => {
  if (e.button !== 0) return;
  resizer.setPointerCapture(e.pointerId);
  document.body.classList.add('resizing');
});
resizer.addEventListener('pointermove', (e) => {
  if (document.body.classList.contains('resizing')) setPanelWidth(e.clientX - panel.getBoundingClientRect().left);
});
function endResize() {
  if (!document.body.classList.contains('resizing')) return;
  document.body.classList.remove('resizing');
  saveWidth();
  drawLinks();
}
resizer.addEventListener('pointerup', endResize);
resizer.addEventListener('pointercancel', endResize);
resizer.addEventListener('keydown', (e) => {
  if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
  e.preventDefault();
  setPanelWidth(panel.offsetWidth + (e.key === 'ArrowRight' ? 20 : -20));
  saveWidth();
});

// mover tabelas: so enquanto a tecla Espaço estiver pressionada
const typing = (el) => el.closest('input, textarea, select, button, [contenteditable]') || document.querySelector('dialog[open]');
window.addEventListener('keydown', (e) => {
  if (e.code !== 'Space' || typing(e.target)) return;
  e.preventDefault();
  document.body.classList.add('space');
});
window.addEventListener('keyup', (e) => { if (e.code === 'Space') document.body.classList.remove('space'); });
window.addEventListener('blur', () => document.body.classList.remove('space'));

// atalhos: Ctrl+Z desfaz, Ctrl+Y (ou Ctrl+Shift+Z) refaz; nos campos de texto vale o desfazer do proprio campo
window.addEventListener('keydown', (e) => {
  if (!(e.ctrlKey || e.metaKey) || typing(e.target)) return;
  const k = e.key.toLowerCase();
  if (k === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
  else if (k === 'y' || (k === 'z' && e.shiftKey)) { e.preventDefault(); redo(); }
});

// Organizar: camadas a partir da tabela mais ligada, ordenadas para reduzir cruzamentos; tabelas sem ligacao vao para uma grade embaixo
function arrange() {
  const p = state.project;
  if (!p.tables.length) return;
  const el = (id) => board.querySelector(`[data-id="${id}"]`);
  const adj = new Map(p.tables.map((t) => [t.id, new Set()]));
  p.relationships.forEach((r) => {
    if (r.fromTable !== r.toTable && adj.has(r.fromTable) && adj.has(r.toTable)) {
      adj.get(r.fromTable).add(r.toTable);
      adj.get(r.toTable).add(r.fromTable);
    }
  });

  const seen = new Set(), comps = [];
  [...p.tables].sort((a, b) => adj.get(b.id).size - adj.get(a.id).size).forEach((t) => {
    if (seen.has(t.id)) return;
    seen.add(t.id);
    const layers = [[t.id]];
    for (let i = 0; i < layers.length; i++) {
      const next = [];
      layers[i].forEach((id) => adj.get(id).forEach((n) => { if (!seen.has(n)) { seen.add(n); next.push(n); } }));
      if (next.length) layers.push(next);
    }
    comps.push(layers);
  });

  const GAP_X = 120, GAP_Y = 40, layout = {};
  let top = 40;
  comps.filter((c) => c.length > 1 || c[0].length > 1).forEach((layers) => {
    const pos = new Map();
    layers.forEach((layer, i) => {
      if (i > 0) {
        const bary = (id) => {
          const ns = [...adj.get(id)].filter((n) => pos.has(n));
          return ns.length ? ns.reduce((s, n) => s + pos.get(n), 0) / ns.length : 0;
        };
        layer.sort((a, b) => bary(a) - bary(b));
      }
      layer.forEach((id, j) => pos.set(id, j));
    });
    const heights = layers.map((layer) => layer.reduce((s, id) => s + el(id).offsetHeight + GAP_Y, -GAP_Y));
    const tall = Math.max(...heights);
    let x = 40;
    layers.forEach((layer, i) => {
      let y = top + (tall - heights[i]) / 2;
      layer.forEach((id) => { layout[id] = { x, y }; y += el(id).offsetHeight + GAP_Y; });
      x += Math.max(...layer.map((id) => el(id).offsetWidth)) + GAP_X;
    });
    top += tall + 80;
  });

  const alone = comps.filter((c) => c.length === 1 && c[0].length === 1).map((c) => c[0][0]);
  let x = 40, y = top, rowH = 0;
  alone.forEach((id, j) => {
    if (j > 0 && j % 4 === 0) { y += rowH + GAP_Y; rowH = 0; x = 40; }
    layout[id] = { x, y };
    x += el(id).offsetWidth + 40;
    rowH = Math.max(rowH, el(id).offsetHeight);
  });

  state.layout = layout;
  saveLayout();
  render();
  board.scrollTo(0, 0);
}
document.getElementById('arrange').addEventListener('click', arrange);
