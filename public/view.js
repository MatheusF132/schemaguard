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
  if (e.button !== 0 || e.target.closest('.table')) return;
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
