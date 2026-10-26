/** Optional real-Chromium layout QA; isolated synthetic data, never submits to ARCA.
 * PHP_BINARY=/path/to/php CHROMIUM_PATH=/path/to/chromium node tests/print_layout.mjs
 * Requires Playwright and pdfjs-dist in NODE_MODULES or CODEX_PRIMARY_RUNTIME_NODE_MODULES.
 */
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { tmpdir } from 'node:os';
import { randomBytes } from 'node:crypto';
import net from 'node:net';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const require = createRequire(join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES || root, 'qa-dependencies.cjs'));
const { chromium } = require('playwright');
const pdfjs = await import(pathToFileURL(require.resolve('pdfjs-dist/legacy/build/pdf.mjs')).href);
const php = process.env.PHP_BINARY || 'php';
const storage = mkdtempSync(join(tmpdir(), 'facturafacil-print-layout-'));
const password = 'Print-QA-' + randomBytes(18).toString('hex');
const env = { ...process.env, FACTURAFACIL_STORAGE: storage };
const out = process.env.PRINT_QA_OUTPUT;
if (out) mkdirSync(out, { recursive: true });
let processHandle;
let browser;
let checks = 0;
const check = (condition, message) => { assert(condition, message); ++checks; };

const fixtureSource = `
require 'app/bootstrap.php';
$db = FacturaFacil\\Database::connect(ff_storage());
$settings = new FacturaFacil\\Settings($db, ff_storage());
$settings->save(['company_name'=>str_repeat('Empresa', 28),'cuit'=>'20123456786','address'=>str_repeat('Direccion', 25),'income_tax_number'=>'12345678901234567890','activity_start'=>'2020-01-01']);
$catalog = new FacturaFacil\\Catalog($db);
$customer = $catalog->save('customers',['name'=>str_repeat('Cliente',28),'document_type'=>'DNI','document_number'=>'12345678','tax_condition'=>5,'address'=>str_repeat('Domicilio',25)]);
$product = $catalog->save('products',['code'=>str_repeat('P',80),'name'=>str_repeat('Articulo',24),'description'=>'Fixture de diseño','price'=>'99999999.99','vat_rate'=>'21']);
$second = $catalog->save('products',['code'=>'IVA105','name'=>'Producto al diez coma cinco por ciento','price'=>'110.50','vat_rate'=>'10.5']);
$service = new FacturaFacil\\InvoiceService($db,$settings,ff_storage());
$items = array_fill(0,6,['product_id'=>$product['id'],'quantity'=>'1']);
$items[] = ['product_id'=>$second['id'],'quantity'=>'1'];
$invoice = $service->create(['customer_id'=>$customer['id'],'issue_date'=>date('Y-m-d'),'items'=>$items]);
$db->prepare("UPDATE invoices SET status='authorized',number=1,cae='12345678901234',cae_due_date='2026-12-31' WHERE id=?")->execute([$invoice['id']]);
echo $invoice['id'];
`;

function runPhp(args, options = {}) {
  const result = spawnSync(php, args, { cwd: root, env, encoding: 'utf8', ...options });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout || 'PHP fixture failed');
  return result.stdout;
}

async function portNumber() {
  const server = net.createServer();
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  await new Promise(resolve => server.close(resolve));
  return port;
}

try {
  runPhp(['bin/install.php', 'print-qa@example.test'], { input: password + '\n' + password + '\n' });
  const invoiceId = runPhp(['-r', fixtureSource]).trim();
  const port = await portNumber();
  const base = `http://127.0.0.1:${port}`;
  processHandle = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', 'public', 'public/router.php'], { cwd: root, env, stdio: 'ignore' });
  for (let attempt = 0; attempt < 100; ++attempt) {
    try { if ((await fetch(base)).ok) break; } catch {}
    if (attempt === 99) throw new Error('Local PHP server did not become ready');
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, headless: true, args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(base);
  await page.locator('input[name="email"]').fill('print-qa@example.test');
  await page.locator('input[name="password"]').fill(password);
  await Promise.all([page.waitForURL(base + '/'), page.locator('button[type="submit"]').click()]);

  const results = [];
  for (const format of ['a4', '80mm', '50mm']) {
    await page.emulateMedia({ media: 'screen' });
    await page.setViewportSize({ width: 1280, height: 1000 });
    await page.goto(`${base}/invoices/${invoiceId}/print?format=${format}`);
    await page.locator('#fiscal-qr svg').waitFor();
    check(await page.locator('body').getAttribute('data-print-format') === format, `${format}: format applied`);
    check(await page.locator('#fiscal-qr svg').count() === 1, `${format}: QR rendered as SVG`);
    const content = await page.locator('.invoice-sheet').innerText();
    check(content.includes('12345678901234') && content.includes('10,5%') && content.includes('21%'), `${format}: fiscal data retained`);

    const measurements = async () => page.evaluate(() => {
      const sheet = document.querySelector('.invoice-sheet');
      const visible = element => element.getClientRects().length && getComputedStyle(element).display !== 'none';
      return {
        viewport: document.documentElement.clientWidth,
        documentWidth: document.documentElement.scrollWidth,
        sheetWidth: sheet.getBoundingClientRect().width,
        sheetHeight: sheet.getBoundingClientRect().height,
        overflow: [...sheet.querySelectorAll('*')].filter(element => visible(element) && element.scrollWidth > element.clientWidth + 2).map(element => ({ tag: element.tagName, class: element.className?.baseVal ?? element.className, scroll: element.scrollWidth, client: element.clientWidth })).slice(0, 10),
      };
    });
    const screen = await measurements();
    check(screen.documentWidth <= screen.viewport + 2, `${format}: no horizontal document overflow on desktop`);
    check(screen.overflow.length === 0, `${format}: no overflowing invoice content on screen: ${JSON.stringify(screen.overflow)}`);
    if (out) await page.screenshot({ path: join(out, `print-${format}.png`), fullPage: true });

    if (format !== 'a4') {
      await page.setViewportSize({ width: 320, height: 900 });
      const mobile = await measurements();
      check(mobile.documentWidth <= mobile.viewport + 2, `${format}: no horizontal overflow at 320px viewport`);
      check(mobile.overflow.length === 0, `${format}: no overflowing ticket content at 320px`);
    }
    const cssWidth = format === 'a4' ? Math.ceil(210 * 96 / 25.4) : Math.ceil(parseInt(format) * 96 / 25.4);
    await page.setViewportSize({ width: cssWidth, height: 1000 });
    await page.emulateMedia({ media: 'print' });
    await page.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
    const print = await measurements();
    check(print.documentWidth <= print.viewport + 2, `${format}: no horizontal overflow in print media`);
    check(print.overflow.length === 0, `${format}: no overflowing printed text: ${JSON.stringify(print.overflow)}`);
    const pdf = await page.pdf({ preferCSSPageSize: true, printBackground: true, displayHeaderFooter: false });
    if (out) writeFileSync(join(out, `print-${format}.pdf`), pdf);
    const document = await pdfjs.getDocument({ data: new Uint8Array(pdf), useSystemFonts: true }).promise;
    const first = await document.getPage(1);
    const widthMm = (first.view[2] - first.view[0]) * 25.4 / 72;
    const heightMm = (first.view[3] - first.view[1]) * 25.4 / 72;
    const expectedMm = format === 'a4' ? 210 : parseInt(format);
    check(Math.abs(widthMm - expectedMm) < 0.8, `${format}: PDF paper width ${widthMm.toFixed(2)}mm`);
    if (format === 'a4') check(Math.abs(heightMm - 297) < 0.8, 'A4 PDF page height is 297mm');
    else check(document.numPages === 1 && heightMm > 100, `${format}: receipt is a single continuous PDF page`);
    let pdfText = '';
    for (let number = 1; number <= document.numPages; ++number) {
      const pdfPage = await document.getPage(number);
      pdfText += (await pdfPage.getTextContent()).items.map(item => item.str).join(' ');
    }
    const compactText = pdfText.replace(/\s+/g, '');
    check(compactText.includes('12345678901234') && compactText.includes('600.000.110,44') && compactText.includes('IVA105'), `${format}: final PDF retains CAE, grand total and last product`);
    results.push({ format, screen, print, pdf: { pages: document.numPages, widthMm, heightMm } });
    await document.destroy();
  }
  await page.emulateMedia({ media: 'screen' });
  await page.setViewportSize({ width: 1280, height: 1000 });
  await page.goto(`${base}/invoices/${invoiceId}/print?format=a4`);
  await Promise.all([
    page.waitForURL(url => url.searchParams.get('format') === '80mm'),
    page.locator('#print-format').selectOption('80mm'),
  ]);
  check(await page.locator('body').getAttribute('data-print-format') === '80mm', 'format selector navigates to an 80mm ticket');
  await Promise.all([
    page.waitForURL(url => url.searchParams.get('format') === '50mm'),
    page.locator('#print-format').selectOption('50mm'),
  ]);
  check(await page.locator('body').getAttribute('data-print-format') === '50mm', 'format selector navigates to a 50mm ticket');
  check(await page.locator('#print-button').isVisible(), 'print action remains available after changing formats');
  check(errors.length === 0, `No browser JavaScript errors: ${errors.join('; ')}`);
  console.log(JSON.stringify({ checks, results }, null, 2));
} finally {
  if (browser) await browser.close();
  if (processHandle) {
    processHandle.kill('SIGTERM');
    await new Promise(resolve => processHandle.once('exit', resolve));
  }
  rmSync(storage, { recursive: true, force: true });
}
