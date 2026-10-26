'use strict';
document.getElementById('print-button').addEventListener('click', () => window.print());
const qrContainer = document.getElementById('fiscal-qr');
if (qrContainer && typeof qrcode === 'function') {
  const code = qrcode(0, 'M');
  code.addData(qrContainer.dataset.url);
  code.make();
  qrContainer.innerHTML = code.createSvgTag({cellSize: 2, margin: 8, scalable: true});
}
