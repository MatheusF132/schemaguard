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

// area de rolagem maior que a tela, para dar espaco de mover o mapa
const baseDraw = drawLinks;
drawLinks = function () {
  baseDraw();
  svg.setAttribute('width', Math.max(+svg.getAttribute('width'), board.clientWidth * 2));
  svg.setAttribute('height', Math.max(+svg.getAttribute('height'), board.clientHeight * 2));
};

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
