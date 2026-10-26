'use strict';
const formatSelect = document.getElementById('print-format');
formatSelect.addEventListener('change', () => formatSelect.form.requestSubmit());
document.body.classList.add('print-enhanced');
const qrContainer = document.getElementById('fiscal-qr');
if (qrContainer && typeof qrcode === 'function') {
  const code = qrcode(0, 'M');
  code.addData(qrContainer.dataset.url);
  code.make();
  qrContainer.innerHTML = code.createSvgTag({cellSize: 2, margin: 8, scalable: true});
}

function preparePaper() {
  const format = document.body.dataset.printFormat;
  if (!['80mm', '50mm'].includes(format)) return;
  const sheet = document.querySelector('.invoice-sheet');
  // CSS millimetres are defined as 96 / 25.4 CSS pixels. Measure the actual
  // receipt after wrapping and QR generation; roll paper has no fixed height.
  const height = Math.max(60, Math.ceil(sheet.getBoundingClientRect().height * 25.4 / 96) + 2);
  const paper = document.getElementById('paper-stylesheet').sheet;
  if (!paper) return;
  for (const rule of paper.cssRules) {
    if (rule.type === CSSRule.PAGE_RULE) rule.style.setProperty('size', `${format.replace('mm', '')}mm ${height}mm`);
  }
}

document.getElementById('print-button').addEventListener('click', async () => {
  await document.fonts.ready;
  preparePaper();
  window.print();
});
window.addEventListener('beforeprint', preparePaper);
document.fonts.ready.then(preparePaper);
window.addEventListener('resize', preparePaper);
