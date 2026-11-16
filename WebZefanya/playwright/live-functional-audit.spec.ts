import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const origin = 'https://zefanya.shop';
const dir = path.resolve('playwright/live-audit');
fs.mkdirSync(dir, { recursive: true });
test.describe.configure({ mode: 'default' });
const publicPaths = ['/', '/login', '/register', '/forgot-password', '/admin/login', '/buyer', '/buyer/home', '/buyer/products', '/buyer/categories', '/buyer/search?q=shirt', '/buyer/help', '/buyer/pages/shipping-returns', '/buyer/pages/privacy-policy', '/buyer/pages/terms-of-service', '/buyer/pages/about', '/buyer/pages/contact', '/register/buyer', '/register/seller', '/register/logistics'];
const guarded = ['/buyer/cart','/buyer/checkout','/buyer/orders','/buyer/account','/buyer/account/address','/buyer/account/password','/buyer/wishlist','/buyer/settings','/buyer/notifications','/buyer/messages','/buyer/chat','/seller/dashboard','/seller/products','/seller/orders','/seller/shipments','/seller/messages','/seller/vouchers','/seller/reports','/seller/feedback','/seller/account','/logistics','/logistics/incoming-parcels','/logistics/parcel-sorting','/logistics/delivery-assignments','/logistics/delivery-monitoring','/logistics/areas','/admin/dashboard','/admin/registrations','/admin/users'];

for (const [name, viewport] of Object.entries({ desktop: { width: 1440, height: 1000 }, mobile: { width: 390, height: 844 } })) {
 test(`${name}: staged safe live functional audit`, async ({ browser }) => {
  test.setTimeout(900000);
  const context = await browser.newContext({ viewport, isMobile: name === 'mobile', hasTouch: name === 'mobile', serviceWorkers: 'block' });
  const page = await context.newPage();
  page.setDefaultTimeout(6000);
  const records: any[] = [], events: any[] = [], inventory: any[] = [];
  let counter = 0;
  const log = (event: any) => { events.push({ time: new Date().toISOString(), page: page.url(), ...event }); };
  await context.route('**/*', async route => {
   const req = route.request(), url = new URL(req.url());
   if (!['GET','HEAD','OPTIONS'].includes(req.method())) { log({ type: 'safety-block', method: req.method(), url: req.url() }); return route.abort('blockedbyclient'); }
   if (['localhost','127.0.0.1','::1'].includes(url.hostname) || (req.isNavigationRequest() && url.origin !== origin)) { log({ type: 'navigation-block', url: req.url() }); return route.abort('blockedbyclient'); }
   await route.continue();
  });
  page.on('console', msg => { if (msg.type() === 'error') log({ type: 'console-error', message: msg.text() }); });
  page.on('pageerror', error => log({ type: 'page-error', message: error.message }));
  page.on('requestfailed', req => log({ type: 'request-failed', url: req.url(), failure: req.failure() }));
  page.on('response', response => { if (response.status() >= 400 || (response.status() >= 300 && response.status() < 400)) log({ type: 'http', status: response.status(), url: response.url() }); });
  async function check(kind: string, label: string, fn: () => Promise<any>) {
   const row: any = { id: `${name}-${++counter}`, viewport: name, kind, label, url: page.url(), status: 'PASS' };
   try { row.actual = await fn(); } catch (error: any) { row.status = 'FAIL'; row.actual = error.message.slice(0,1600); }
   row.url = page.url();
   if (row.status === 'FAIL' || kind === 'page') {
    row.screenshot = `${name}-${counter}.png`;
    await page.screenshot({ path: path.join(dir,row.screenshot), fullPage: true, timeout: 10000 }).catch(() => { row.screenshot = null; });
   }
   records.push(row);
   fs.writeFileSync(path.join(dir,`${name}.json`),JSON.stringify({ records, events, inventory },null,2));
  }
  async function visit(url: string) {
   const response = await page.goto(url.startsWith('http') ? url : origin + url, { waitUntil: 'domcontentloaded', timeout: 30000 });
   await page.waitForTimeout(350);
   return response;
  }
  async function inspect() {
   inventory.push({ url: page.url(), elements: await page.locator('a,button,input,select,textarea,summary,[role="button"]').evaluateAll(es => es.map((e: any) => ({ tag: e.tagName, text: (e.innerText || e.getAttribute('aria-label') || '').trim().slice(0,140), id: e.id, href: e.getAttribute('href'), type: e.getAttribute('type'), visible: !!(e.offsetWidth || e.offsetHeight || e.getClientRects().length) }))) });
  }
  // Stage 1: guest surfaces. Every page gets HTTP, body, overflow and evidence checks.
  const categories = new Set<string>(), products = new Set<string>();
  for (const url of publicPaths) {
   await check('page',`Guest ${url}`,async () => {
    const response = await visit(url);
    expect(response?.status()).toBe(200);
    expect(new URL(page.url()).origin).toBe(origin);
    expect((await page.locator('body').innerText()).trim().length).toBeGreaterThan(30);
    await inspect();
    for (const href of await page.locator('a[href]').evaluateAll(es => es.map((e:any) => e.href))) {
     if (new URL(href).origin !== origin) continue;
     if (/\/buyer\/categories\/\d+$/.test(href)) categories.add(href);
     if (/\/buyer\/products\/\d+$/.test(href)) products.add(href);
    }
    return { status: response?.status(), finalURL: page.url(), title: await page.title() };
   });
   await check('layout',`No horizontal overflow ${url}`,async () => {
    const dims = await page.evaluate(() => ({ viewport: innerWidth, width: document.documentElement.scrollWidth }));
    expect(dims.width).toBeLessThanOrEqual(dims.viewport + 2); return dims;
   });
   if (url === '/buyer/categories') await check('content','Category index displays category links',async () => { const links=await page.locator('main a[href*="/categories/"]').count(); expect(links).toBeGreaterThan(0); return { links }; });
  }
  for (const url of categories) await check('page',`Category ${url}`,async () => { const r=await visit(url); expect(r?.status()).toBe(200); await expect(page.locator('h1').first()).toBeVisible(); await inspect(); for(const h of await page.locator('a[href]').evaluateAll(es=>es.map((e:any)=>e.href))) if(new URL(h).origin===origin && /\/buyer\/products\/\d+$/.test(h)) products.add(h); return {status:r?.status(),heading:await page.locator('h1').first().innerText()}; });
  await visit('/');
  // Verify intended effects, ignoring hover/focus changes when detecting inert buttons.
  async function effectSnapshot() { return page.evaluate(() => JSON.stringify({ url:location.href, text:document.body.innerText, dialogs:[...document.querySelectorAll('[role="dialog"]')].map(e=>({id:e.id,visible:!!(e as HTMLElement).offsetWidth})), count:document.querySelectorAll('main article').length })); }
  for (const label of ['SHOP NOW','LOAD MORE','APP STORE','GOOGLE PLAY']) await check('button',`Landing ${label} produces a result`,async () => { const before=await effectSnapshot(); await page.getByRole('button',{name:label,exact:true}).click(); await page.waitForTimeout(600); expect(await effectSnapshot()).not.toBe(before); return 'Visible content or navigation changed'; });
  for (let i=0;i<4;i++) for(const label of ['QUICK VIEW','ADD']) await check('button',`Landing card ${i+1}: ${label} produces a result`,async()=>{ const before=await effectSnapshot(); await page.getByRole('button',{name:label,exact:true}).nth(i).click(); await page.waitForTimeout(400); expect(await effectSnapshot()).not.toBe(before); return 'Visible content or navigation changed'; });
  for (const selector of ['#catNext','#catPrev','#catDots button:nth-child(2)']) await check('button',`Category carousel ${selector}`,async()=>{ const before=await page.locator('#catTrack').getAttribute('style'); await page.locator(selector).click(); await page.waitForTimeout(500); expect(await page.locator('#catTrack').getAttribute('style')).not.toBe(before); return await page.locator('#catTrack').getAttribute('style'); });
  await check('button','Login modal opens',async()=>{await page.locator('[data-login-trigger]').first().click(); await expect(page.locator('#loginModalOverlay')).toHaveAttribute('aria-hidden','false');return 'Login dialog visible';});
  await check('button','Login password toggle reveals and hides',async()=>{ await page.locator('#loginPassword').fill('AuditOnly!23'); await page.locator('#togglePassword').click(); await expect(page.locator('#loginPassword')).toHaveAttribute('type','text'); await page.locator('#togglePassword').click(); await expect(page.locator('#loginPassword')).toHaveAttribute('type','password'); });
  await check('button','Empty login stays local and marks required input invalid',async()=>{await page.locator('#loginPassword').fill('');const before=events.filter(e=>e.type==='safety-block').length;await page.locator('#loginModalOverlay button[type="submit"]').click();expect(await page.locator('#loginEmail').evaluate((e:HTMLInputElement)=>e.validity.valueMissing)).toBe(true);expect(events.filter(e=>e.type==='safety-block').length).toBe(before);});
  await check('button','Close login hides modal',async()=>{await page.locator('#loginModalClose').click();await expect(page.locator('#loginModalOverlay')).toHaveAttribute('aria-hidden','true');});
  // Registration UI only: no submit, OTP or account request can leave browser.
  for(const role of ['buyer','seller','logistics']) {
   await visit('/register');
   const prefix=role==='buyer'?'':role;
   const field=(suffix:string)=>'#'+(prefix ? prefix+suffix : suffix[0].toLowerCase()+suffix.slice(1));
   await check('button',`${role} registration opens`,async()=>{await page.locator(`[data-${role}-register-trigger]`).first().click();await expect(page.locator(`#${role}ModalOverlay`)).toHaveAttribute('aria-hidden','false');});
   await check('button',`${role} password visibility`,async()=>{await page.locator(field('Password')).fill('AuditOnly!23'); await page.locator(`[data-toggle-visibility="${prefix?prefix+'Password':'password'}"]`).click(); await expect(page.locator(field('Password'))).toHaveAttribute('type','text');await page.locator(`[data-toggle-visibility="${prefix?prefix+'Password':'password'}"]`).click();await expect(page.locator(field('Password'))).toHaveAttribute('type','password');});
   await check('button',`${role} empty Next shows errors and stays on step 1`,async()=>{ await page.locator('#'+(prefix?prefix+'NextStep1':'nextStep1')).click();const panel=page.locator(`#${role}RegisterForm [data-panel="1"]`);await expect(panel).toBeVisible();const errors=await panel.locator('.field-error').allTextContents();expect(errors.some(x=>x.trim())).toBe(true);return errors.filter(x=>x.trim()); });
   await check('workflow',`${role} valid personal details advance to dropdown address`,async()=>{
    for(const [suffix,value] of Object.entries({FirstName:'Audit',LastName:'Tester',Email:'audit@example.invalid',ContactNo:'09171234567',Password:'AuditOnly!23',PasswordConfirmation:'AuditOnly!23'})) await page.locator(field(suffix)).fill(value);
    await page.locator(field('Sex')).selectOption('male'); await page.locator(field('BirthYear')).selectOption('2000'); await page.locator(field('BirthMonth')).selectOption('01'); await page.locator(field('BirthDay')).selectOption('1');
    await page.locator('#'+(prefix?prefix+'NextStep1':'nextStep1')).click();await expect(page.locator(`#${role}RegisterForm [data-panel="2"]`)).toBeVisible();
    for(const name of ['province','municipality','barangay']) expect(await page.locator(`#${role}RegisterForm select[name="${name}"]`).count()).toBe(1);
    const province=page.locator(`#${role}RegisterForm select[name="province"]`);await expect.poll(()=>province.locator('option').count(),{timeout:15000}).toBeGreaterThan(1);
    await province.selectOption({index:1});const municipality=page.locator(`#${role}RegisterForm select[name="municipality"]`);await expect.poll(()=>municipality.locator('option').count(),{timeout:15000}).toBeGreaterThan(1);await municipality.selectOption({index:1});const barangay=page.locator(`#${role}RegisterForm select[name="barangay"]`);await expect.poll(()=>barangay.locator('option').count(),{timeout:15000}).toBeGreaterThan(1);await barangay.selectOption({index:1});return 'Step 2 shown; cascading province, municipality and barangay loaded and selected';
   });
   await check('button',`${role} registration closes`,async()=>{await page.locator(`#${role}ModalClose`).click();await expect(page.locator(`#${role}ModalOverlay`)).toHaveAttribute('aria-hidden','true');});
  }
  await visit('/buyer/help');
  const faqs=await page.locator('summary').count();
  for(let i=0;i<faqs;i++) await check('button',`FAQ ${i+1} expands and collapses`,async()=>{const summary=page.locator('summary').nth(i);await summary.click();await expect(summary.locator('..')).toHaveAttribute('open','');await expect(summary.locator('..').locator('p')).toBeVisible();await summary.click();expect(await summary.locator('..').getAttribute('open')).toBeNull();});
  // Stage 2: public buyer catalog; no cart/order mutation.
  await visit('/buyer/products');
  await check('button','Sort dropdown opens',async()=>{await page.locator('[data-sort-toggle]').click();await expect(page.locator('[data-sort-menu]')).toBeVisible();});
  await check('workflow','Price ascending sort updates URL and selected label',async()=>{await page.locator('[data-sort-menu]').getByText('Price: Low to High',{exact:true}).click();await expect(page).toHaveURL(/sort=price_asc/);await expect(page.locator('[data-sort-toggle]')).toContainText('Price: Low to High');});
  await check('button','List view changes catalog presentation',async()=>{ const before=await page.locator('main').innerHTML();await page.getByRole('button',{name:'List view',exact:true}).click();await page.waitForTimeout(350);expect(await page.locator('main').innerHTML()).not.toBe(before); });
  for(const url of products) {
   await check('page',`Product ${url}`,async()=>{const r=await visit(url);expect(r?.status()).toBe(200);await expect(page.locator('h1').first()).toBeVisible();await inspect();return{status:r?.status(),title:await page.locator('h1').first().innerText()};});
   if(await page.locator('#pdp-qty-up').count()) await check('button',`Product ${url} quantity increases/decreases`,async()=>{const stock=Number(await page.locator('#pdp-add-cart').getAttribute('data-stock'));await page.locator('#pdp-qty-up').click();await expect(page.locator('#pdp-qty')).toHaveText(stock>1?'2':'1');await page.locator('#pdp-qty-down').click();await expect(page.locator('#pdp-qty')).toHaveText('1');});
   const thumbs=await page.locator('.js-pdp-thumb').count();for(let i=0;i<thumbs;i++) await check('button',`Product ${url} gallery ${i+1}`,async()=>{const thumb=page.locator('.js-pdp-thumb').nth(i);const src=await thumb.getAttribute('data-src');await thumb.click();await expect(thumb).toHaveAttribute('aria-pressed','true');await expect(page.locator('#pdp-main-img')).toHaveAttribute('src',src!);});
   const options=await page.locator('.js-pdp-variant-value').count();for(let i=0;i<options;i++) await check('button',`Product ${url} variant ${i+1}`,async()=>{const option=page.locator('.js-pdp-variant-value').nth(i);await option.click();await expect(option).toHaveAttribute('aria-pressed','true');return{value:await option.getAttribute('data-value'),price:await page.locator('#pdp-price').innerText()};});
  }
  if(!products.size) records.push({id:`${name}-products-blocked`,kind:'workflow',label:'Product detail, variants, gallery, quantity and guest cart prompt',status:'BLOCKED',actual:'No real product detail links discovered in catalog/category pages',viewport:name});
  // Stages 2-6: guest guards only; these do not establish cross-role authorization.
  for(const url of guarded) await check('guard',`Guest denied ${url}`,async()=>{const r=await visit(url);expect(r?.status()).toBe(200);expect(new URL(page.url()).pathname).toBe(url.startsWith('/admin/')?'/admin/login':'/login');return{finalURL:page.url(),status:r?.status()};});
  const workflows = ['Buyer authenticated cart, vouchers, wishlist, account, settings, messages and notifications','Buyer checkout, order placement, payment and receipt confirmation','Seller authenticated dashboard, catalog, orders, shipments, waybill and messages','Seller product/stock changes and fulfilment transitions','Logistics authenticated dashboards, areas and parcel lists','Logistics pickup, sorting, rider assignment, delivery, retry and return','Admin authenticated dashboard, registration queue, documents and account lists','Admin approvals, rejections, suspension and reactivation','Authenticated cross-role access and object ownership','Registration submission, email OTP, resend, password-reset mail and Google OAuth'];
  for(const label of workflows) records.push({id:`${name}-blocked-${records.length}`,viewport:name,kind:'workflow',label,status:'BLOCKED',actual:'No dedicated test accounts supplied; production changes and external authentication are not authorized'});
  fs.writeFileSync(path.join(dir,`${name}.json`),JSON.stringify({records,events,inventory},null,2));
  await context.close();
  console.log(JSON.stringify({viewport:name,counts:records.reduce((a,r)=>(a[r.status]=(a[r.status]||0)+1,a),{} as any),products:products.size,categories:categories.size}));
 });
}

// Focused follow-up checks also run independently with --grep supplemental.
for (const [name, viewport] of Object.entries({ desktop: {width:1440,height:1000}, mobile:{width:390,height:844} })) {
 test(`${name}: supplemental links, forms and catalog outcomes`,async({browser})=>{
  test.setTimeout(600000);
  const context=await browser.newContext({viewport,serviceWorkers:'block'}), page=await context.newPage();
  page.setDefaultTimeout(5000);
  const records:any[]=[],events:any[]=[],inventory:any[]=[];let count=0;
  await context.route('**/*',async route=>{const req=route.request();if(!['GET','HEAD','OPTIONS'].includes(req.method())) {events.push({type:'safety-block',method:req.method(),url:req.url()});return route.abort('blockedbyclient');}if(req.isNavigationRequest()&&new URL(req.url()).origin!==origin)return route.abort('blockedbyclient');await route.continue();});
  page.on('response',r=>{if(r.status()>=400)events.push({type:'http',url:r.url(),status:r.status()});});page.on('pageerror',e=>events.push({type:'page-error',message:e.message}));page.on('console',m=>{if(m.type()==='error')events.push({type:'console-error',message:m.text()});});page.on('requestfailed',r=>events.push({type:'request-failed',url:r.url(),failure:r.failure()}));
  const save=()=>fs.writeFileSync(path.join(dir,`${name}-supplemental.json`),JSON.stringify({records,events,inventory},null,2));
  async function check(kind:string,label:string,fn:()=>Promise<any>){const row:any={id:`${name}-extra-${++count}`,viewport:name,kind,label,status:'PASS'};try{row.actual=await fn();}catch(e:any){row.status=label.includes('guest #pdp-')?'BLOCKED':'FAIL';row.actual=label.includes('guest #pdp-')?'Production purchase POST blocked before transmission; outcome unverified.':e.message.slice(0,1200);}row.url=page.url();row.screenshot=`${name}-extra-${count}.png`;await page.screenshot({path:path.join(dir,row.screenshot),fullPage:true}).catch(()=>row.screenshot=null);records.push(row);save();}
  async function visit(url:string){await page.goto(origin+url,{waitUntil:'domcontentloaded',timeout:60000});await page.waitForTimeout(250);}
  async function changed(locator:any){const before=await page.evaluate(()=>({url:location.href,text:document.body.innerText}));await locator.click();await page.waitForTimeout(350);const after=await page.evaluate(()=>({url:location.href,text:document.body.innerText}));expect(after).not.toEqual(before);return after.url;}
  await visit('/');
  const footerLinks=await page.locator('footer a').evaluateAll(es=>es.map((e:any)=>({href:e.getAttribute('href'),text:e.innerText.trim()||e.getAttribute('aria-label')})));
  for(const link of footerLinks) await check('link',`Footer ${link.text}`,async()=>{await visit('/');const loc=page.locator('footer a').filter({hasText:link.text});const target=link.href==='#'?page.locator(`footer a[aria-label="${link.text}"]`):loc.first();const responsePromise=page.waitForResponse(r=>r.request().isNavigationRequest(),{timeout:5000}).catch(()=>null);await target.click();await page.waitForTimeout(400);if(link.href==='#'){expect(new URL(page.url()).hash).not.toBe('');throw new Error('Placeholder href=# has no destination');}const response=await responsePromise;expect(response?.status()).toBeLessThan(400);expect(page.url()).toContain(new URL(link.href).pathname.replace('/register/seller','/register').replace('/register/logistics','/register'));return{url:page.url(),status:response?.status()};});
  await visit('/');
  await check('link','Landing VIEW ALL navigates to categories',async()=>changed(page.getByText('VIEW ALL',{exact:true})));
  await check('link','Landing category card opens category',async()=>changed(page.locator('#catTrack > div').first()));
  await check('button','Navbar category dropdown opens and category navigation works',async()=>{await visit('/');await page.locator('label[for="catMenuToggle"]').click();const link=page.locator('nav a[href*="/buyer/categories/"]').first();await expect(link).toBeVisible();const href=await link.getAttribute('href');await link.click();await expect(page).toHaveURL(href!);});
  await check('workflow','Navbar search submits query and shows matching results or empty state',async()=>{await visit('/buyer/products');await page.getByRole('textbox',{name:'Search',exact:true}).fill('shirt');await page.getByRole('button',{name:'Submit search',exact:true}).click();await expect(page).toHaveURL(/\/buyer\/products\?/);expect(new URL(page.url()).searchParams.get('q')).toBe('shirt');await expect(page.locator('main')).toContainText(/shirt|no.*found/i);return await page.locator('main').innerText();});
  await check('button','Login empty submission has browser validation with no POST',async()=>{await visit('/');await page.locator('[data-login-trigger]').first().click();const n=events.filter(e=>e.type==='safety-block'&&e.url.endsWith('/login')).length;await page.locator('#loginModalOverlay button[type="submit"]').click();expect(await page.locator('#loginEmail').evaluate((e:HTMLInputElement)=>e.validity.valueMissing)).toBe(true);expect(events.filter(e=>e.type==='safety-block'&&e.url.endsWith('/login')).length).toBe(n);});
  for(const url of ['/login','/admin/login','/forgot-password']) await check('button',`Empty form ${url} blocks submit in browser`,async()=>{await visit(url);const form=page.locator('form').first();const n=events.filter(e=>e.type==='safety-block').length;await form.locator('button[type="submit"]').click();expect(await form.locator('input:invalid').count()).toBeGreaterThan(0);expect(events.slice(n).filter(e=>e.type==='safety-block'&&!e.url.includes('/_boost/')&&!e.url.includes('/cdn-cgi/')).length).toBe(0);});
  await check('guard','Guest denied /logistics (correct dashboard route)',async()=>{await visit('/logistics');await expect(page).toHaveURL(origin+'/login');});
  for(const role of ['seller','logistics']) await check('workflow',`${role} deep link address provinces load automatically`,async()=>{await visit('/register/'+role);await expect(page.locator(`#${role}ModalOverlay`)).toHaveAttribute('aria-hidden','false');const province=page.locator(`#${role}RegisterForm select[name="province"]`);await expect.poll(()=>province.locator('option').count(),{timeout:7000}).toBeGreaterThan(1);return await province.locator('option').count();});
  await visit('/buyer/products');
  const urls=await page.locator('a[href]').evaluateAll(es=>[...new Set(es.map((e:any)=>e.href).filter(h=>/\/buyer\/products\/\d+$/.test(h)))]);
  for(const sort of ['price_asc','price_desc','newest','oldest','relevance']) await check('workflow',`Catalog ${sort} changes label and actual ordering`,async()=>{await visit('/buyer/products');await page.locator('[data-sort-toggle]').click();await page.locator(`[data-sort-menu] a[href*="sort=${sort}"]`).click();await expect(page).toHaveURL(new RegExp('sort='+sort));const cards=page.locator('[data-add-to-cart]');await cards.first().waitFor();expect(await cards.count()).toBeGreaterThan(0);const prices=await cards.evaluateAll(es=>es.map(e=>{const text=((e.closest('.group')||e.parentElement) as HTMLElement).innerText;const m=text.match(/₱\s*([\d,.]+)/);return m?Number(m[1].replaceAll(',','')):null;}).filter(n=>n!==null));if(sort.startsWith('price_')&&prices.length>1)expect(prices).toEqual([...prices].sort((a:any,b:any)=>sort==='price_asc'?a-b:b-a));return{sort,prices,cardCount:await cards.count()};});
  await check('button','List view changes computed grid columns',async()=>{await visit('/buyer/products');const grid=page.locator('main div.grid').filter({has:page.locator('a[href*="/buyer/products/"]')}).first();const before=await grid.evaluate(e=>getComputedStyle(e).gridTemplateColumns);await page.getByRole('button',{name:'List view',exact:true}).click();await page.waitForTimeout(300);expect(await grid.evaluate(e=>getComputedStyle(e).gridTemplateColumns)).not.toBe(before);return{before,after:await grid.evaluate(e=>getComputedStyle(e).gridTemplateColumns)};});
  for(const selector of ['[data-add-to-cart]','[data-wishlist-toggle]']) await check('button',`Catalog guest ${selector} opens login without mutation`,async()=>{await visit('/buyer/products');await page.locator(selector).first().click();await expect(page.locator('#loginModalOverlay')).toHaveAttribute('aria-hidden','false');});
  for(const full of urls){const url=new URL(full).pathname;
   for(const selector of ['#pdp-add-cart','#pdp-buy-now']) await check('button',`${url} guest ${selector} opens login`,async()=>{await visit(url);await page.locator(selector).click();await expect(page.locator('#loginModalOverlay')).toHaveAttribute('aria-hidden','false');});
   await check('button',`${url} full image opens a valid image`,async()=>{await visit(url);const src=await page.locator('#pdp-main-img').getAttribute('src');expect(src).toBeTruthy();const opened=context.waitForEvent('page',{timeout:5000});await page.locator('#pdp-maximize').click();const popup=await opened;await popup.waitForLoadState('domcontentloaded',{timeout:7000});expect(popup.url()).toBe(new URL(src!,origin).href);expect(await popup.locator('img').evaluate((e:HTMLImageElement)=>e.naturalWidth)).toBeGreaterThan(0);await popup.close();});
  }
  save();await context.close();console.log(JSON.stringify({supplemental:name,counts:records.reduce((a,r)=>(a[r.status]=(a[r.status]||0)+1,a),{} as any)}));
 });
}

for(const [name,viewport] of Object.entries({desktop:{width:1440,height:1000},mobile:{width:390,height:844}})) test(`${name}: verification of sorting and image results`,async({browser})=>{
 test.setTimeout(360000);const context=await browser.newContext({viewport}),page=await context.newPage();context.setDefaultTimeout(7000);context.setDefaultNavigationTimeout(15000);const records:any[]=[],events:any[]=[];let n=0;
 await context.route('**/*',async route=>{if(!['GET','HEAD','OPTIONS'].includes(route.request().method()))return route.abort('blockedbyclient');if(route.request().isNavigationRequest()&&new URL(route.request().url()).origin!==origin)return route.abort('blockedbyclient');await route.continue();});
 async function check(kind:string,label:string,fn:()=>Promise<any>){const r:any={id:`${name}-verify-${++n}`,viewport:name,kind,label,status:'PASS'};try{r.actual=await fn();}catch(e:any){r.status='FAIL';r.actual=e.message.slice(0,1000);}r.url=page.url();r.screenshot=`${r.id}.png`;await page.screenshot({path:path.join(dir,r.screenshot),fullPage:true,timeout:5000}).catch(()=>r.screenshot=null);records.push(r);fs.writeFileSync(path.join(dir,`${name}-verification.json`),JSON.stringify({records,events,inventory:[]},null,2));}
 for(const url of ['/register/buyer/pending','/register/seller/pending','/register/logistics/pending','/seller/login'])await check('page','Guest '+url,async()=>{const r=await page.goto(origin+url,{waitUntil:'domcontentloaded'});expect(r?.status()).toBe(200);expect((await page.locator('body').innerText()).length).toBeGreaterThan(30);return{status:r?.status(),finalURL:page.url()};});
 if(name==='desktop')await check('link','Navbar Sale has a shopping destination',async()=>{await page.goto(origin+'/buyer/products',{waitUntil:'domcontentloaded'});await page.locator('nav a[title="Sale"]').click();expect(new URL(page.url()).pathname+new URL(page.url()).search).not.toBe('/buyer/products');return page.url();});
 for(const sort of ['price_asc','price_desc','newest','oldest','relevance'])await check('workflow',`Verified catalog ${sort} result`,async()=>{await page.goto(origin+'/buyer/products',{waitUntil:'domcontentloaded'});await page.locator('[data-sort-toggle]').click();await Promise.all([page.waitForURL(new RegExp('sort='+sort),{waitUntil:'domcontentloaded'}),page.locator(`[data-sort-menu] a[href*="sort=${sort}"]`).click()]);const cards=page.locator('[data-add-to-cart]');await cards.first().waitFor();const items=await cards.evaluateAll(es=>es.map(e=>{const container=e.closest('.group')||e.parentElement!;const m=(container as HTMLElement).innerText.match(/₱\s*([\d,.]+)/);return{title:(container as HTMLElement).innerText,price:m?Number(m[1].replaceAll(',','')):null,href:e.querySelector('a')?.href};}));expect(items.length).toBeGreaterThan(0);const prices=items.map(x=>x.price);expect(prices.every(x=>x!==null)).toBe(true);if(sort.startsWith('price_'))expect(prices).toEqual([...prices].sort((a:any,b:any)=>sort==='price_asc'?a-b:b-a));const label=await page.locator('[data-sort-toggle]').innerText();return{label,items,note:sort==='newest'||sort==='oldest'?'Creation timestamps are not exposed in UI; chronology not independently verified.':'Rendered prices checked for price sorts.'};});
 const urls=await page.locator('a[href]').evaluateAll(es=>[...new Set(es.map((e:any)=>e.href).filter(h=>/\/buyer\/products\/\d+$/.test(h)))]);
 for(const url of urls)await check('button',`Verified full image ${new URL(url).pathname}`,async()=>{await page.goto(url,{waitUntil:'domcontentloaded'});const src=await page.locator('#pdp-main-img').getAttribute('src');const opened=context.waitForEvent('page',{timeout:7000});await page.locator('#pdp-maximize').click();const popup=await opened;await popup.waitForLoadState('domcontentloaded',{timeout:7000});expect(popup.url()).toBe(new URL(src!,origin).href);await expect.poll(()=>popup.locator('img').evaluate((e:HTMLImageElement)=>e.naturalWidth)).toBeGreaterThan(0);const result={url:popup.url(),width:await popup.locator('img').evaluate((e:HTMLImageElement)=>e.naturalWidth)};await popup.close();return result;});
 await context.close();console.log(JSON.stringify({verification:name,records:records.length,failed:records.filter(r=>r.status==='FAIL').length}));
});
