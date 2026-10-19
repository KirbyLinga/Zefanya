import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const root = process.cwd();
const artifactDir = path.join(root, 'playwright/product-form-preview');
fs.mkdirSync(artifactDir, { recursive: true });
const previewPhp = String.raw`require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['session.driver' => 'array', 'app.url' => 'http://127.0.0.1']);
Illuminate\Support\Facades\URL::forceRootUrl('http://127.0.0.1');
$errors = new Illuminate\Support\ViewErrorBag;
$modal = view('Components.seller.product-create-modal', [
    'newProduct' => new App\Models\Product(['status' => 'draft', 'low_stock_threshold' => 5]),
    'lockedCategory' => new App\Models\Shared\Category(['label' => "Women's Apparel"]),
    'openCreateModal' => true,
    'errors' => $errors,
])->render();
$manifest = json_decode(file_get_contents(getcwd().'/public/build/manifest.json'), true);
$css = '/build/'.$manifest['resources/css/app.css']['file'];
echo '<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Seller Product Form ? Local Preview</title><link rel="stylesheet" href="'.$css.'"></head><body class="seller-body bg-[var(--bg-page)]"><main class="p-8"><p class="font-serif text-2xl">Zefanya ? Seller Center</p><button data-product-create-open type="button">Add New Product</button></main>'.$modal.'<script src="/js/lucide.min.js"></script><script type="module" src="/__product-form.js"></script></body></html>';
`;
const html = execFileSync('php', ['-r', previewPhp], { encoding: 'utf8' });
const publicDir = path.resolve('public');
const server = http.createServer((req, res) => {
    if (req.method !== 'GET') { res.writeHead(405); res.end(); return; }
    const pathname = new URL(req.url, 'http://127.0.0.1').pathname;
    if (pathname === '/__pdp-check.js') { res.setHeader('Content-Type', 'text/javascript'); res.end(fs.readFileSync('resources/js/buyer/product-show.js')); return; }
    if (pathname === '/__color-image.png' || pathname.startsWith('/__color-image-')) { res.setHeader('Content-Type', 'image/png'); res.end(png); return; }
    if (pathname === '/__pdp') {
        const variants = ['Black','White','Beige'].flatMap((color, axis) => ['S','M','L','XL'].map((size,index) => ({ id: axis*4+index+1, values:[color,size], color_axis:0, images:['/__color-image-'+color+'.png'], price:350,stock:8,available:true })));
        res.setHeader('Content-Type','text/html');
        res.end('<!doctype html><html><body><img id="pdp-main-img" src="/__color-image.png"><script type="application/json" data-pdp-variants>'+JSON.stringify(variants)+'</script>'+[['Black','White','Beige'],['S','M','L','XL']].map((values,axis)=>'<div role="group">'+values.map(value=>'<button class="js-pdp-variant-value" data-axis-index="'+axis+'" data-value="'+value+'">'+value+'</button>').join('')+'</div>').join('')+'<span id="pdp-price">350</span><input id="pdp-variant-id"><span id="pdp-qty">1</span><button id="pdp-qty-up">+</button><button id="pdp-qty-down">-</button><button id="pdp-add-cart" data-stock="94">Add</button><p id="pdp-variant-error"></p><script type="module" src="/__pdp-check.js"></script></body></html>'); return;
    }
    if (pathname === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(html); return; }
    if (pathname === '/__product-form.js') { res.setHeader('Content-Type', 'text/javascript'); res.end(fs.readFileSync('resources/js/seller/product-create-modal.js')); return; }
    const file = path.resolve(publicDir, '.' + pathname);
    if (!file.startsWith(publicDir + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404); res.end(); return; }
    res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : 'application/octet-stream');
    fs.createReadStream(file).pipe(res);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = 'http://127.0.0.1:' + server.address().port;
const browser = await chromium.launch();
const results = [];
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const photo = name => ({ name, mimeType: 'image/png', buffer: png });
try {
    for (const [viewportName, viewport] of Object.entries({ desktop: { width: 1440, height: 1000 }, mobile: { width: 390, height: 844 } })) {
        const context = await browser.newContext({ viewport });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.setDefaultTimeout(6000);
        await context.route('**/*', async route => {
            if (route.request().method() !== 'GET' || new URL(route.request().url()).origin !== base) return route.abort();
            return route.continue();
        });
        const shot = async label => { await page.locator('#productModalBody').evaluate(body => { body.scrollTop = 0; }); return page.screenshot({ path: path.join(artifactDir, viewportName + '-' + label + '.png'), timeout: 6000 }); };
        const panel = step => page.locator('[data-pf-step="' + step + '"]');
        const next = () => page.locator('[data-pf-next]').click();
        await page.goto(base);
        await page.locator('#name').waitFor({ state: 'visible' });
        await next();
        assert.equal(await panel(1).isVisible(), true, 'empty information must not advance');
        await page.locator('#name').fill('Premium Cotton Oversized Shirt');
        await page.locator('#description').fill('Premium cotton oversized shirt, available in multiple colors and sizes.');
        await page.locator('[data-pf-gallery-files]').setInputFiles([photo('front.png'), photo('detail.png')]);
        await page.locator('[data-pf-set-cover="1"]').click();
        assert.equal(await page.locator('[data-pf-cover-index]').inputValue(), '1');
        await shot('information');
        await next();
        await page.locator('[data-pf-variants-toggle]').check();
        await page.locator('[data-pf-option-name]').first().fill('Color');
        await page.locator('[data-pf-value-entry]').first().fill('Black,White,Beige');
        await page.locator('[data-pf-add-value]').first().click();
        await page.locator('[data-pf-add-option]').click();
        await page.locator('[data-pf-option-name]').nth(1).fill('Size');
        await page.locator('[data-pf-value-entry]').nth(1).fill('S,M,L,XL');
        await page.locator('[data-pf-add-value]').nth(1).click();
        assert.equal(await page.locator('[data-pf-variant-row]').count(), 12);
        await shot('variations');
        await next();
        assert.equal(await panel(3).isVisible(), true);
        assert.equal(await page.locator('[data-pf-color-card]').count(), 3);
        for (const color of ['Black','White','Beige']) await page.locator('[data-pf-color-card][data-color="' + color + '"] [data-pf-color-files]').setInputFiles(photo(color + '.png'));
        await shot('colors');
        await next();
        await page.locator('[data-pf-bulk-price]').fill('350');
        await page.locator('[data-pf-bulk-stock]').fill('8');
        await page.locator('[data-pf-apply-bulk]').click();
        await page.locator('[data-pf-stock]').nth(10).fill('7');
        await page.locator('[data-pf-stock]').nth(11).fill('7');
        assert.equal(await page.locator('[data-pf-total-stock]').innerText(), '94');
        await page.locator('[data-pf-price]').first().fill('-1');
        await next();
        assert.equal(await panel(4).isVisible(), true, 'negative price must not advance');
        await page.locator('[data-pf-price]').first().fill('350');
        await shot('inventory');
        await page.locator('[data-pf-back]').click();
        await page.locator('[data-pf-back]').click();
        await page.locator('[data-pf-value-entry]').nth(1).fill('XXL');
        await page.locator('[data-pf-add-value]').nth(1).click();
        assert.equal(await page.locator('[data-pf-variant-row]').count(), 15);
        await page.getByRole('button', { name: 'Remove XXL', exact: true }).click();
        assert.equal(await page.locator('[data-pf-variant-row]').count(), 12);
        await next();
        assert.equal(await page.locator('[data-pf-color-files]').evaluateAll(inputs => inputs.filter(input => input.files.length === 1).length), 3, 'unchanged color cards keep selected photos');
        await next();
        assert.equal(await page.locator('[data-pf-total-stock]').innerText(), '94', 'regenerating options preserves entered inventory');
        await next();
        assert.equal(await panel(5).isVisible(), true);
        assert.match(await page.locator('[data-pf-review-summary]').innerText(), /12 variants.*94 pieces/);
        assert.equal(await page.locator('#productModalSubmit').isEnabled(), true);
        assert.match(await page.locator('#productModalSubmit').innerText(), /Publish Product/i);
        await page.locator('#status').selectOption('draft');
        assert.match(await page.locator('#productModalSubmit').innerText(), /Save Draft/i);
        await page.locator('#status').selectOption('active');
        await shot('publish');
        await page.evaluate(() => { document.documentElement.dataset.theme = 'dark'; });
        await shot('dark');
        const payload = await page.locator('#productModalForm').evaluate(form => {
            const data = new FormData(form);
            return [...data].map(([key,value]) => [key, typeof value === 'string' ? value : value.name]);
        });
        assert.equal(payload.filter(([key]) => /^variants\[\d+\]\[values\]$/.test(key)).length,12);
        assert.equal(payload.filter(([key]) => /^color_images\[\d+\]\[files\]\[\]$/.test(key)).length,3);
        assert.equal(payload.find(([key]) => key === 'stock_quantity')[1], '94');
        const dims = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }));
        assert.ok(dims.scroll <= dims.width + 1, 'page must not overflow viewport');
        await page.locator('#productModalClose').click();
        assert.equal(await page.locator('#productModalOverlay').getAttribute('aria-hidden'), 'true');
        await page.locator('[data-product-create-open]').click();
        assert.equal(await panel(5).isVisible(), true, 'reopening preserves current draft');
        await page.goto(base + '/__pdp');
        for (const color of ['Black','White','Beige']) {
            await page.locator('.js-pdp-variant-value[data-value="'+color+'"]').click();
            assert.equal(await page.locator('#pdp-main-img').getAttribute('src'), '/__color-image-'+color+'.png', 'color selection previews photo before size selection');
            await page.locator('.js-pdp-variant-value[data-value="M"]').click();
            assert.equal(await page.locator('#pdp-main-img').getAttribute('src'), '/__color-image-'+color+'.png', 'size selection shares color photo');
        }
        assert.deepEqual(errors, []);
        results.push({ viewport: viewportName, status: 'PASS', cases: ['required information', 'cover selection', '12 combinations', '3 color uploads', 'per-variant price/stock', 'negative price validation', '94-stock rollup', 'publish review', 'draft status', 'multipart payload', 'responsive width', 'close/reopen', 'dark preview', 'buyer color photo preview', 'buyer sizes share color photo', 'add/remove sizes preserves inventory', 'color uploads survive size edits'], payload });
        await context.close();
    }
    fs.writeFileSync(path.join(artifactDir,'results.json'), JSON.stringify(results,null,2));
    console.log(JSON.stringify({ status: 'PASS', viewports: results.length, checks: results.reduce((sum,r)=>sum+r.cases.length,0), artifacts: artifactDir }));
} finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
}
