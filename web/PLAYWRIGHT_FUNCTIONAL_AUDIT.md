# Playwright Functional Audit — Live Zefanya

Date: 2026-10-17 (Asia/Singapore). Target: https://zefanya.shop only. Chromium desktop 1440×1000 and mobile 390×844. No localhost server started.

## Scope and safety

Executed the existing Playwright configuration in isolated live-audit mode, one worker, using fresh anonymous browser contexts. All non-GET/HEAD/OPTIONS requests and external document navigation were blocked before transmission. No account registration, OTP, login attempt, password-reset email, newsletter signup, order, payment, approval, deletion or database change was submitted. Application source and dependencies were not modified. Browser sessions necessarily generate normal server session/access records. No dedicated role accounts were supplied. Authenticated role checks and authorized state transitions remain BLOCKED.

Coverage is the executed cases below, not a claim that every DOM element or business invariant was tested. Inventory records are discovery evidence, not functional passes; inventory visibility uses DOM geometry and can include CSS-hidden ancestors. No test asserted server state after a write. Mobile coverage is Chromium viewport/touch emulation, not physical iOS/Safari.

The two original staged driver tests completed; their list-reporter “passed” status means orchestration completed. Individual assertions are caught and persisted so later checks continue. Functional failures are counted here and in JSON, regardless of runner status. An earlier image/sort verification pass was stopped after its audit extraction error and a popup-navigation stall; only final persisted verification results are counted. The first supplemental attempt timed out at homepage navigation before any assertions, and was retried with a longer timeout; this is an execution incident, not a confirmed application bug.

## Counts

Counts are assertion cases across both viewports, including repeat controls on distinct product cards. They are not unique DOM-node counts. Blocked cases were not executed.

| Category | Passed | Failed | Blocked | Total |
|---|---:|---:|---:|---:|
| page | 76 | 0 | 8 | 84 |
| button | 66 | 30 | 30 | 126 |
| link | 18 | 8 | 1 | 27 |
| workflow | 10 | 4 | 30 | 44 |
| guard | 58 | 0 | 0 | 58 |
| layout | 38 | 0 | 0 | 38 |
| content | 2 | 0 | 0 | 2 |
| **All cases** | **268** | **42** | **69** | **379** |

38 distinct requested public page URLs loaded successfully in both desktop and mobile runs, including registration redirect entry points and /buyer/home alias. Guest guards are separate and prove anonymous denial only, not authenticated cross-role isolation.

## Confirmed live defects

Priorities: P1 blocks a core entry/registration path; P2 breaks a secondary function or content promise; P3 minor navigation/presentation. Repeated card/viewport failures are grouped into findings.

### LIVE-01 — P1: Homepage Shop Now has no effect

Open / and click SHOP NOW. Expected catalog navigation or shopping content. Actual URL, visible text and dialog state stay unchanged.

Source corroboration: resources/views/LandingPage/index.blade.php renders a plain button without a handler.

Evidence: [desktop-54](playwright/live-audit/desktop-54.png), [mobile-54](playwright/live-audit/mobile-54.png).

### LIVE-02 — P1: Homepage recommendation actions are inert

Open /; click QUICK VIEW and ADD on each of four recommended cards. Expected product details or guest login/cart feedback. All eight actions leave visible content, URL and dialogs unchanged.

Source corroboration: resources/views/Components/product-card.blade.php contains presentation-only buttons; recommendations in LandingPage/index.blade.php are hard-coded demo entries rather than catalog products.

Evidence: [desktop-58](playwright/live-audit/desktop-58.png), [desktop-59](playwright/live-audit/desktop-59.png), [desktop-60](playwright/live-audit/desktop-60.png), [desktop-61](playwright/live-audit/desktop-61.png), [desktop-62](playwright/live-audit/desktop-62.png), [desktop-63](playwright/live-audit/desktop-63.png), [desktop-64](playwright/live-audit/desktop-64.png), [desktop-65](playwright/live-audit/desktop-65.png), [mobile-58](playwright/live-audit/mobile-58.png), [mobile-59](playwright/live-audit/mobile-59.png), [mobile-60](playwright/live-audit/mobile-60.png), [mobile-61](playwright/live-audit/mobile-61.png), [mobile-62](playwright/live-audit/mobile-62.png), [mobile-63](playwright/live-audit/mobile-63.png), [mobile-64](playwright/live-audit/mobile-64.png), [mobile-65](playwright/live-audit/mobile-65.png).

### LIVE-03 — P2: Load More does not load products

Open / and click LOAD MORE below recommendations. Expected additional products or a catalog link. No visible result.

Source corroboration: resources/views/LandingPage/index.blade.php renders an unwired button.

Evidence: [desktop-55](playwright/live-audit/desktop-55.png), [mobile-55](playwright/live-audit/mobile-55.png).

### LIVE-04 — P2: App download buttons have no destination

Open / and click APP STORE or GOOGLE PLAY. Expected a store listing or availability explanation. No visible result.

Source corroboration: resources/views/Layouts/footer.blade.php contains buttons with no destination or handler.

Evidence: [desktop-56](playwright/live-audit/desktop-56.png), [desktop-57](playwright/live-audit/desktop-57.png), [mobile-56](playwright/live-audit/mobile-56.png), [mobile-57](playwright/live-audit/mobile-57.png).

### LIVE-05 — P2: Seller and Logistics password reveal toggles do not reveal

Open /register; choose Seller or Logistics; type a password and click its eye icon. Expected input type=text. Actual input remains password.

Source corroboration: Shared delegated toggles in registration-validation.js overlap with buyer-register-modal.js global handlers and role-specific handlers in seller/seller-register-modal.js and logistics/logistics-register-modal.js. Buyer toggle passes on the tested page.

Evidence: [desktop-79](playwright/live-audit/desktop-79.png), [desktop-84](playwright/live-audit/desktop-84.png), [mobile-79](playwright/live-audit/mobile-79.png), [mobile-84](playwright/live-audit/mobile-84.png).

### LIVE-06 — P1: Seller and Logistics deep-link province loading fails

Visit /register/seller or /register/logistics (also linked from footer). Modal auto-opens; province options fail to populate. Network records show GET /undefined/provinces/ → 404. Opening normally through /register works.

Source corroboration: Role modal scripts call open*RegisterModal() from the open query parameter before the var PSGC_BASE assignment. fetchProvinces() therefore builds an undefined base URL.

Evidence: [desktop-extra-21](playwright/live-audit/desktop-extra-21.png), [desktop-extra-22](playwright/live-audit/desktop-extra-22.png), [mobile-extra-21](playwright/live-audit/mobile-extra-21.png), [mobile-extra-22](playwright/live-audit/mobile-extra-22.png).

### LIVE-07 — P2: Landing View All and category tiles are not navigation

Open / and click VIEW ALL or the first Explore Categories tile. Expected category navigation. The visible content and URL do not change.

Source corroboration: LandingPage/index.blade.php renders these as div elements without links or handlers. Navbar category links work separately.

Evidence: [desktop-extra-12](playwright/live-audit/desktop-extra-12.png), [desktop-extra-13](playwright/live-audit/desktop-extra-13.png), [mobile-extra-12](playwright/live-audit/mobile-extra-12.png), [mobile-extra-13](playwright/live-audit/mobile-extra-13.png).

### LIVE-08 — P3: Footer Email and Website links are placeholders

Open / and click the footer Email or Website icon. Both href values are #; neither opens a meaningful destination.

Source corroboration: resources/views/Layouts/footer.blade.php uses href=#.

Evidence: [desktop-extra-10](playwright/live-audit/desktop-extra-10.png), [desktop-extra-11](playwright/live-audit/desktop-extra-11.png), [mobile-extra-10](playwright/live-audit/mobile-extra-10.png), [mobile-extra-11](playwright/live-audit/mobile-extra-11.png).

### LIVE-09 — P2: Catalog List view does not change layout

Open /buyer/products and click List view. Expected list presentation. Computed grid columns remain unchanged.

Source corroboration: resources/views/Buyer/Products/index.blade.php provides Grid/List buttons but no layout-switch handler. Initial generic innerHTML assertion was discarded as inadequate.

Evidence: [desktop-extra-28](playwright/live-audit/desktop-extra-28.png), [mobile-extra-28](playwright/live-audit/mobile-extra-28.png).

### LIVE-10 — P2: Homepage recommendation images return 404

Open /. All four recommendation image backgrounds are blank. Playwright captured HTTP 404 for their /img/ab6...png URLs in both viewport runs. The four hard-coded image references are in LandingPage/index.blade.php and Components/product-card.blade.php. See [desktop screenshot](playwright/live-audit/desktop-1.png), [mobile screenshot](playwright/live-audit/mobile-1.png) and the network table below.

Confirmed grouped findings above: **10**. Additional failed assertions, if any, are retained below and require interpretation before becoming bug claims.

## ERP requirements and source-only gaps

Read docs/1-Context.md, 2-Design.md, 3-Folder-Structure.md and 4-Flow.md, then checked current routes, controllers, Blade and JavaScript. Local source is corroborating evidence; production commit identity is unverified. The following are source-confirmed gaps, **not authenticated live functional test results**:

| Requirement | Current source evidence | Live status | Priority |
|---|---|---|---|
| Seller vouchers, reporting, feedback, account and legacy Chat | Seller/{Vouchers,Reports,Feedback,Account,Chat}/index.blade.php contain headings/descriptions only | BLOCKED behind login | P2 |
| Buyer account landing | Buyer/Account/index.blade.php renders empty main; profile sidebar exists | BLOCKED | P2 |
| Buyer legacy Chat | Buyer/ChatController redirects with “Chat is coming soon”; actual Messages is separate | BLOCKED | P2 |
| Rider self-registration, rider approval/accounts and mobile API | No rider auth/API routes; Logistics sidebar rider screens have route=null | BLOCKED; footer Become a Courier leads to logistics-provider registration, not rider registration | P1 |
| Admin commissions, compliance, disputes, reports, settings, chat, account | Components/admin-sidebar.blade.php marks modules Soon | BLOCKED | P2 |
| Commission engine, shipping-fee calculation, product ratings and buyer return-request flow | No matching implementation found in app/routes; order creation and documented scope inspected | Source gap; runtime financial correctness unverified | P1/P2 |
| Public shipping/return promises | Help and Shipping & Returns promise paid/free shipping rules, 14-day returns and refunds; source lacks corresponding self-service fee/return engine | Public copy observed; fulfilment/support process unverified | P2 |

Documentation corrections: category index is implemented and passed, despite stale placeholder notes. Public footer support/legal links navigate successfully; do not repeat the old “13 dead footer links” claim. Admin user accounts exist in routes/controllers; not all admin modules are missing. No old order-engine bug is promoted to a live-confirmed finding.

## Stages and limitations

1. Guest: public routes, all 14 category pages, footer links, carousel, login modal, empty-form validation, registration personal/address transitions, cascading dropdowns and FAQ controls executed. Final registration submit, ID uploads to server, OTP, reset emails and OAuth blocked.
2. Buyer: five catalog product detail pages, quantity buttons, catalog sort/search and guest purchase/wishlist gates executed where recorded. No claim of cart persistence or order correctness.
3. Seller: public registration UI and anonymous protected-route denial executed. Authenticated workflows blocked.
4. Logistics: public registration UI and anonymous protected-route denial executed. Authenticated operations blocked.
5. Admin: public login form and anonymous protected-route denial executed. Authenticated modules blocked.
6. Cross-role: anonymous denial only. Signed-in role switching, ownership and object access blocked.

Real catalog fixtures determine coverage: no variant/gallery case is counted unless its dynamic control loop actually ran. Stock/variant combinations, multi-image galleries, pagination beyond existing results, product editing, vouchers, messages, order history and admin/logistics modals require suitable dedicated fixtures/accounts. Inventory does not establish that every repeated navigation/filter element was clicked. Uncovered elements and data-dependent paths remain unverified. Public catalog/category routes discovered from source were visited. Remaining pending-registration pages and seller-login alias were attempted or scheduled in the final interrupted pass and remain BLOCKED where no successful result was recorded. Arbitrary invalid IDs and signed OTP/reset links were not guessed.

## Network, console and redirect evidence

Intentional safety aborts for /_boost/browser-logs and /cdn-cgi/rum generate ERR_BLOCKED_BY_CLIENT and “Failed to send logs” console entries. These are audit-induced and **not site bugs**. The production pages include Boost browser-log injection; its production configuration is not inferred from this observation.

| HTTP | URL | Evidence file |
|---:|---|---|
| 404 | https://zefanya.shop/img/ab6axudtt6jbu5-nkg5wzbbijzeszxns-qve3vvsrvsfsiw5hnpqdw9bs5uc3ureqby0mi-jcbaexaapmkcbyjoxrjelhugy8rubofhpu-toqo7jwwijpb9gq3oqc-rue8evuu7qjgo-l3bf2mwwehrohoven6n6s-0hcsfv2p-2txan8lnfkwv-taiztklfo3ezfq0wmezpqyp2wvwrv4jhx-0lvyb2a34vdoa1ikcm7wzd5sykc4mqtsp5.png | mobile-supplemental.json |
| 404 | https://zefanya.shop/img/ab6axuaqrpvz4obmcgbskrmz4ewsw7tfxn9kr5w0jihp8eck-ey7pyflbluhfvfr0uvzf5chjmwppioxtzgaqkpn0-wumted-f2gu-zizw8row4eajwkslaj0kjcdiuvsto6y0m0r6gmiz9teqz6gvhxlhxkr5dov0dsl4hxxjngzlrtfurxieiqf5tp4ol2bgbw2nn1t49d-jqvye7xivsmvabg0my2novxotfdw8u5ssuoo2ibhc8gnwnx.png | mobile-supplemental.json |
| 404 | https://zefanya.shop/img/ab6axudxnss12ck0hhlnxkyy7qzfuqwpffzkvmv1gwyxm0fjcl5ah7vreiahqaxofwbaypzl-infc79pbltoj31v7twsrs6cyldsjneridoj8wxnrdkrdgha4optxzh1-veeqmic-xj6duq9izv6w0w36tsphgsbwfwgeboh2rgw-ihaiycpodu35iutifcnhqj8mniw9mcq9kwdswcn100zcjxb6lpmxsaq3klplnwdwc-x6vzw5rgi0dl.png | mobile-supplemental.json |
| 404 | https://zefanya.shop/img/ab6axucbufyg85myhfn0sumboaoq0ns4gmxefybuwbhbagaxf2rdoz-67booqib4fmnqi2gylmxigy8phqjw3qbu5scm-qq2jzopjwkq3caydvxzfdrwdfnjgysl0azk6glspkium8qfjogpp2oxr91hjc-zpbp7wcbvdikvvkd8wrikomr4myi-q28ap5zxaphi-iru-pxcuhgq8qub1uxpqjnski-voiusqt5chmhjzmom5zwsnfkec9eh.png | mobile-supplemental.json |
| 404 | https://zefanya.shop/undefined/provinces/ | mobile-supplemental.json |

Homepage prices visibly use dollars ($129.99, $24.50, $32.00, $185.00), whereas real catalog prices use Philippine pesos. This is a confirmed content mismatch in the demo recommendation section (same screenshot evidence as LIVE-02/LIVE-10), not a tested currency-conversion calculation.

The last verification pass was stopped after repeated navigation timeouts. This does not establish a server outage, rate limit, or application defect; no bypass or repeated probing was attempted. Some corrected sort/image assertions remain BLOCKED.

No HTTP 500 is claimed unless present in the table. Registration redirect paths and guest login redirects were expected; redirect endpoints are recorded per case. The mistaken /logistics/dashboard 404 is excluded from site defects. Full console errors, failed requests, HTTP events and timestamps are in the six JSON files.

## Full executed-case ledger

| Case | Kind | Result | Check | Evidence |
|---|---|---|---|---|
| desktop-1 | page | PASS | Guest / | [desktop-1](playwright/live-audit/desktop-1.png) |
| desktop-2 | layout | PASS | No horizontal overflow / | desktop-2 (desktop.json) |
| desktop-3 | page | PASS | Guest /login | [desktop-3](playwright/live-audit/desktop-3.png) |
| desktop-4 | layout | PASS | No horizontal overflow /login | desktop-4 (desktop.json) |
| desktop-5 | page | PASS | Guest /register | [desktop-5](playwright/live-audit/desktop-5.png) |
| desktop-6 | layout | PASS | No horizontal overflow /register | desktop-6 (desktop.json) |
| desktop-7 | page | PASS | Guest /forgot-password | [desktop-7](playwright/live-audit/desktop-7.png) |
| desktop-8 | layout | PASS | No horizontal overflow /forgot-password | desktop-8 (desktop.json) |
| desktop-9 | page | PASS | Guest /admin/login | [desktop-9](playwright/live-audit/desktop-9.png) |
| desktop-10 | layout | PASS | No horizontal overflow /admin/login | desktop-10 (desktop.json) |
| desktop-11 | page | PASS | Guest /buyer | [desktop-11](playwright/live-audit/desktop-11.png) |
| desktop-12 | layout | PASS | No horizontal overflow /buyer | desktop-12 (desktop.json) |
| desktop-13 | page | PASS | Guest /buyer/home | [desktop-13](playwright/live-audit/desktop-13.png) |
| desktop-14 | layout | PASS | No horizontal overflow /buyer/home | desktop-14 (desktop.json) |
| desktop-15 | page | PASS | Guest /buyer/products | [desktop-15](playwright/live-audit/desktop-15.png) |
| desktop-16 | layout | PASS | No horizontal overflow /buyer/products | desktop-16 (desktop.json) |
| desktop-17 | page | PASS | Guest /buyer/categories | [desktop-17](playwright/live-audit/desktop-17.png) |
| desktop-18 | layout | PASS | No horizontal overflow /buyer/categories | desktop-18 (desktop.json) |
| desktop-19 | content | PASS | Category index displays category links | desktop-19 (desktop.json) |
| desktop-20 | page | PASS | Guest /buyer/search?q=shirt | [desktop-20](playwright/live-audit/desktop-20.png) |
| desktop-21 | layout | PASS | No horizontal overflow /buyer/search?q=shirt | desktop-21 (desktop.json) |
| desktop-22 | page | PASS | Guest /buyer/help | [desktop-22](playwright/live-audit/desktop-22.png) |
| desktop-23 | layout | PASS | No horizontal overflow /buyer/help | desktop-23 (desktop.json) |
| desktop-24 | page | PASS | Guest /buyer/pages/shipping-returns | [desktop-24](playwright/live-audit/desktop-24.png) |
| desktop-25 | layout | PASS | No horizontal overflow /buyer/pages/shipping-returns | desktop-25 (desktop.json) |
| desktop-26 | page | PASS | Guest /buyer/pages/privacy-policy | [desktop-26](playwright/live-audit/desktop-26.png) |
| desktop-27 | layout | PASS | No horizontal overflow /buyer/pages/privacy-policy | desktop-27 (desktop.json) |
| desktop-28 | page | PASS | Guest /buyer/pages/terms-of-service | [desktop-28](playwright/live-audit/desktop-28.png) |
| desktop-29 | layout | PASS | No horizontal overflow /buyer/pages/terms-of-service | desktop-29 (desktop.json) |
| desktop-30 | page | PASS | Guest /buyer/pages/about | [desktop-30](playwright/live-audit/desktop-30.png) |
| desktop-31 | layout | PASS | No horizontal overflow /buyer/pages/about | desktop-31 (desktop.json) |
| desktop-32 | page | PASS | Guest /buyer/pages/contact | [desktop-32](playwright/live-audit/desktop-32.png) |
| desktop-33 | layout | PASS | No horizontal overflow /buyer/pages/contact | desktop-33 (desktop.json) |
| desktop-34 | page | PASS | Guest /register/buyer | [desktop-34](playwright/live-audit/desktop-34.png) |
| desktop-35 | layout | PASS | No horizontal overflow /register/buyer | desktop-35 (desktop.json) |
| desktop-36 | page | PASS | Guest /register/seller | [desktop-36](playwright/live-audit/desktop-36.png) |
| desktop-37 | layout | PASS | No horizontal overflow /register/seller | desktop-37 (desktop.json) |
| desktop-38 | page | PASS | Guest /register/logistics | [desktop-38](playwright/live-audit/desktop-38.png) |
| desktop-39 | layout | PASS | No horizontal overflow /register/logistics | desktop-39 (desktop.json) |
| desktop-40 | page | PASS | Category https://zefanya.shop/buyer/categories/1 | [desktop-40](playwright/live-audit/desktop-40.png) |
| desktop-41 | page | PASS | Category https://zefanya.shop/buyer/categories/2 | [desktop-41](playwright/live-audit/desktop-41.png) |
| desktop-42 | page | PASS | Category https://zefanya.shop/buyer/categories/3 | [desktop-42](playwright/live-audit/desktop-42.png) |
| desktop-43 | page | PASS | Category https://zefanya.shop/buyer/categories/4 | [desktop-43](playwright/live-audit/desktop-43.png) |
| desktop-44 | page | PASS | Category https://zefanya.shop/buyer/categories/5 | [desktop-44](playwright/live-audit/desktop-44.png) |
| desktop-45 | page | PASS | Category https://zefanya.shop/buyer/categories/6 | [desktop-45](playwright/live-audit/desktop-45.png) |
| desktop-46 | page | PASS | Category https://zefanya.shop/buyer/categories/7 | [desktop-46](playwright/live-audit/desktop-46.png) |
| desktop-47 | page | PASS | Category https://zefanya.shop/buyer/categories/8 | [desktop-47](playwright/live-audit/desktop-47.png) |
| desktop-48 | page | PASS | Category https://zefanya.shop/buyer/categories/9 | [desktop-48](playwright/live-audit/desktop-48.png) |
| desktop-49 | page | PASS | Category https://zefanya.shop/buyer/categories/10 | [desktop-49](playwright/live-audit/desktop-49.png) |
| desktop-50 | page | PASS | Category https://zefanya.shop/buyer/categories/11 | [desktop-50](playwright/live-audit/desktop-50.png) |
| desktop-51 | page | PASS | Category https://zefanya.shop/buyer/categories/12 | [desktop-51](playwright/live-audit/desktop-51.png) |
| desktop-52 | page | PASS | Category https://zefanya.shop/buyer/categories/13 | [desktop-52](playwright/live-audit/desktop-52.png) |
| desktop-53 | page | PASS | Category https://zefanya.shop/buyer/categories/14 | [desktop-53](playwright/live-audit/desktop-53.png) |
| desktop-54 | button | FAIL | Landing SHOP NOW produces a result | [desktop-54](playwright/live-audit/desktop-54.png) |
| desktop-55 | button | FAIL | Landing LOAD MORE produces a result | [desktop-55](playwright/live-audit/desktop-55.png) |
| desktop-56 | button | FAIL | Landing APP STORE produces a result | [desktop-56](playwright/live-audit/desktop-56.png) |
| desktop-57 | button | FAIL | Landing GOOGLE PLAY produces a result | [desktop-57](playwright/live-audit/desktop-57.png) |
| desktop-58 | button | FAIL | Landing card 1: QUICK VIEW produces a result | [desktop-58](playwright/live-audit/desktop-58.png) |
| desktop-59 | button | FAIL | Landing card 1: ADD produces a result | [desktop-59](playwright/live-audit/desktop-59.png) |
| desktop-60 | button | FAIL | Landing card 2: QUICK VIEW produces a result | [desktop-60](playwright/live-audit/desktop-60.png) |
| desktop-61 | button | FAIL | Landing card 2: ADD produces a result | [desktop-61](playwright/live-audit/desktop-61.png) |
| desktop-62 | button | FAIL | Landing card 3: QUICK VIEW produces a result | [desktop-62](playwright/live-audit/desktop-62.png) |
| desktop-63 | button | FAIL | Landing card 3: ADD produces a result | [desktop-63](playwright/live-audit/desktop-63.png) |
| desktop-64 | button | FAIL | Landing card 4: QUICK VIEW produces a result | [desktop-64](playwright/live-audit/desktop-64.png) |
| desktop-65 | button | FAIL | Landing card 4: ADD produces a result | [desktop-65](playwright/live-audit/desktop-65.png) |
| desktop-66 | button | PASS | Category carousel #catNext | desktop-66 (desktop.json) |
| desktop-67 | button | PASS | Category carousel #catPrev | desktop-67 (desktop.json) |
| desktop-68 | button | PASS | Category carousel #catDots button:nth-child(2) | desktop-68 (desktop.json) |
| desktop-69 | button | PASS | Login modal opens | desktop-69 (desktop.json) |
| desktop-70 | button | PASS | Login password toggle reveals and hides | desktop-70 (desktop.json) |
| desktop-72 | button | PASS | Close login hides modal | desktop-72 (desktop.json) |
| desktop-73 | button | PASS | buyer registration opens | desktop-73 (desktop.json) |
| desktop-74 | button | PASS | buyer password visibility | desktop-74 (desktop.json) |
| desktop-75 | button | PASS | buyer empty Next shows errors and stays on step 1 | desktop-75 (desktop.json) |
| desktop-76 | workflow | PASS | buyer valid personal details advance to dropdown address | desktop-76 (desktop.json) |
| desktop-77 | button | PASS | buyer registration closes | desktop-77 (desktop.json) |
| desktop-78 | button | PASS | seller registration opens | desktop-78 (desktop.json) |
| desktop-79 | button | FAIL | seller password visibility | [desktop-79](playwright/live-audit/desktop-79.png) |
| desktop-80 | button | PASS | seller empty Next shows errors and stays on step 1 | desktop-80 (desktop.json) |
| desktop-81 | workflow | PASS | seller valid personal details advance to dropdown address | desktop-81 (desktop.json) |
| desktop-82 | button | PASS | seller registration closes | desktop-82 (desktop.json) |
| desktop-83 | button | PASS | logistics registration opens | desktop-83 (desktop.json) |
| desktop-84 | button | FAIL | logistics password visibility | [desktop-84](playwright/live-audit/desktop-84.png) |
| desktop-85 | button | PASS | logistics empty Next shows errors and stays on step 1 | desktop-85 (desktop.json) |
| desktop-86 | workflow | PASS | logistics valid personal details advance to dropdown address | desktop-86 (desktop.json) |
| desktop-87 | button | PASS | logistics registration closes | desktop-87 (desktop.json) |
| desktop-88 | button | PASS | FAQ 1 expands and collapses | desktop-88 (desktop.json) |
| desktop-89 | button | PASS | FAQ 2 expands and collapses | desktop-89 (desktop.json) |
| desktop-90 | button | PASS | FAQ 3 expands and collapses | desktop-90 (desktop.json) |
| desktop-91 | button | PASS | FAQ 4 expands and collapses | desktop-91 (desktop.json) |
| desktop-92 | button | PASS | Sort dropdown opens | desktop-92 (desktop.json) |
| desktop-93 | workflow | PASS | Price ascending sort updates URL and selected label | desktop-93 (desktop.json) |
| desktop-95 | page | PASS | Product https://zefanya.shop/buyer/products/11 | [desktop-95](playwright/live-audit/desktop-95.png) |
| desktop-96 | button | PASS | Product https://zefanya.shop/buyer/products/11 quantity increases/decreases | desktop-96 (desktop.json) |
| desktop-97 | page | PASS | Product https://zefanya.shop/buyer/products/10 | [desktop-97](playwright/live-audit/desktop-97.png) |
| desktop-98 | button | PASS | Product https://zefanya.shop/buyer/products/10 quantity increases/decreases | desktop-98 (desktop.json) |
| desktop-99 | page | PASS | Product https://zefanya.shop/buyer/products/9 | [desktop-99](playwright/live-audit/desktop-99.png) |
| desktop-100 | button | PASS | Product https://zefanya.shop/buyer/products/9 quantity increases/decreases | desktop-100 (desktop.json) |
| desktop-101 | page | PASS | Product https://zefanya.shop/buyer/products/8 | [desktop-101](playwright/live-audit/desktop-101.png) |
| desktop-102 | button | PASS | Product https://zefanya.shop/buyer/products/8 quantity increases/decreases | desktop-102 (desktop.json) |
| desktop-103 | page | PASS | Product https://zefanya.shop/buyer/products/7 | [desktop-103](playwright/live-audit/desktop-103.png) |
| desktop-104 | button | PASS | Product https://zefanya.shop/buyer/products/7 quantity increases/decreases | desktop-104 (desktop.json) |
| desktop-105 | guard | PASS | Guest denied /buyer/cart | desktop-105 (desktop.json) |
| desktop-106 | guard | PASS | Guest denied /buyer/checkout | desktop-106 (desktop.json) |
| desktop-107 | guard | PASS | Guest denied /buyer/orders | desktop-107 (desktop.json) |
| desktop-108 | guard | PASS | Guest denied /buyer/account | desktop-108 (desktop.json) |
| desktop-109 | guard | PASS | Guest denied /buyer/account/address | desktop-109 (desktop.json) |
| desktop-110 | guard | PASS | Guest denied /buyer/account/password | desktop-110 (desktop.json) |
| desktop-111 | guard | PASS | Guest denied /buyer/wishlist | desktop-111 (desktop.json) |
| desktop-112 | guard | PASS | Guest denied /buyer/settings | desktop-112 (desktop.json) |
| desktop-113 | guard | PASS | Guest denied /buyer/notifications | desktop-113 (desktop.json) |
| desktop-114 | guard | PASS | Guest denied /buyer/messages | desktop-114 (desktop.json) |
| desktop-115 | guard | PASS | Guest denied /buyer/chat | desktop-115 (desktop.json) |
| desktop-116 | guard | PASS | Guest denied /seller/dashboard | desktop-116 (desktop.json) |
| desktop-117 | guard | PASS | Guest denied /seller/products | desktop-117 (desktop.json) |
| desktop-118 | guard | PASS | Guest denied /seller/orders | desktop-118 (desktop.json) |
| desktop-119 | guard | PASS | Guest denied /seller/shipments | desktop-119 (desktop.json) |
| desktop-120 | guard | PASS | Guest denied /seller/messages | desktop-120 (desktop.json) |
| desktop-121 | guard | PASS | Guest denied /seller/vouchers | desktop-121 (desktop.json) |
| desktop-122 | guard | PASS | Guest denied /seller/reports | desktop-122 (desktop.json) |
| desktop-123 | guard | PASS | Guest denied /seller/feedback | desktop-123 (desktop.json) |
| desktop-124 | guard | PASS | Guest denied /seller/account | desktop-124 (desktop.json) |
| desktop-126 | guard | PASS | Guest denied /logistics/incoming-parcels | desktop-126 (desktop.json) |
| desktop-127 | guard | PASS | Guest denied /logistics/parcel-sorting | desktop-127 (desktop.json) |
| desktop-128 | guard | PASS | Guest denied /logistics/delivery-assignments | desktop-128 (desktop.json) |
| desktop-129 | guard | PASS | Guest denied /logistics/delivery-monitoring | desktop-129 (desktop.json) |
| desktop-130 | guard | PASS | Guest denied /logistics/areas | desktop-130 (desktop.json) |
| desktop-131 | guard | PASS | Guest denied /admin/dashboard | desktop-131 (desktop.json) |
| desktop-132 | guard | PASS | Guest denied /admin/registrations | desktop-132 (desktop.json) |
| desktop-133 | guard | PASS | Guest denied /admin/users | desktop-133 (desktop.json) |
| desktop-blocked-133 | workflow | BLOCKED | Buyer authenticated cart, vouchers, wishlist, account, settings, messages and notifications | desktop-blocked-133 (desktop.json) |
| desktop-blocked-134 | workflow | BLOCKED | Buyer checkout, order placement, payment and receipt confirmation | desktop-blocked-134 (desktop.json) |
| desktop-blocked-135 | workflow | BLOCKED | Seller authenticated dashboard, catalog, orders, shipments, waybill and messages | desktop-blocked-135 (desktop.json) |
| desktop-blocked-136 | workflow | BLOCKED | Seller product/stock changes and fulfilment transitions | desktop-blocked-136 (desktop.json) |
| desktop-blocked-137 | workflow | BLOCKED | Logistics authenticated dashboards, areas and parcel lists | desktop-blocked-137 (desktop.json) |
| desktop-blocked-138 | workflow | BLOCKED | Logistics pickup, sorting, rider assignment, delivery, retry and return | desktop-blocked-138 (desktop.json) |
| desktop-blocked-139 | workflow | BLOCKED | Admin authenticated dashboard, registration queue, documents and account lists | desktop-blocked-139 (desktop.json) |
| desktop-blocked-140 | workflow | BLOCKED | Admin approvals, rejections, suspension and reactivation | desktop-blocked-140 (desktop.json) |
| desktop-blocked-141 | workflow | BLOCKED | Authenticated cross-role access and object ownership | desktop-blocked-141 (desktop.json) |
| desktop-blocked-142 | workflow | BLOCKED | Registration submission, email OTP, resend, password-reset mail and Google OAuth | desktop-blocked-142 (desktop.json) |
| mobile-1 | page | PASS | Guest / | [mobile-1](playwright/live-audit/mobile-1.png) |
| mobile-2 | layout | PASS | No horizontal overflow / | mobile-2 (mobile.json) |
| mobile-3 | page | PASS | Guest /login | [mobile-3](playwright/live-audit/mobile-3.png) |
| mobile-4 | layout | PASS | No horizontal overflow /login | mobile-4 (mobile.json) |
| mobile-5 | page | PASS | Guest /register | [mobile-5](playwright/live-audit/mobile-5.png) |
| mobile-6 | layout | PASS | No horizontal overflow /register | mobile-6 (mobile.json) |
| mobile-7 | page | PASS | Guest /forgot-password | [mobile-7](playwright/live-audit/mobile-7.png) |
| mobile-8 | layout | PASS | No horizontal overflow /forgot-password | mobile-8 (mobile.json) |
| mobile-9 | page | PASS | Guest /admin/login | [mobile-9](playwright/live-audit/mobile-9.png) |
| mobile-10 | layout | PASS | No horizontal overflow /admin/login | mobile-10 (mobile.json) |
| mobile-11 | page | PASS | Guest /buyer | [mobile-11](playwright/live-audit/mobile-11.png) |
| mobile-12 | layout | PASS | No horizontal overflow /buyer | mobile-12 (mobile.json) |
| mobile-13 | page | PASS | Guest /buyer/home | [mobile-13](playwright/live-audit/mobile-13.png) |
| mobile-14 | layout | PASS | No horizontal overflow /buyer/home | mobile-14 (mobile.json) |
| mobile-15 | page | PASS | Guest /buyer/products | [mobile-15](playwright/live-audit/mobile-15.png) |
| mobile-16 | layout | PASS | No horizontal overflow /buyer/products | mobile-16 (mobile.json) |
| mobile-17 | page | PASS | Guest /buyer/categories | [mobile-17](playwright/live-audit/mobile-17.png) |
| mobile-18 | layout | PASS | No horizontal overflow /buyer/categories | mobile-18 (mobile.json) |
| mobile-19 | content | PASS | Category index displays category links | mobile-19 (mobile.json) |
| mobile-20 | page | PASS | Guest /buyer/search?q=shirt | [mobile-20](playwright/live-audit/mobile-20.png) |
| mobile-21 | layout | PASS | No horizontal overflow /buyer/search?q=shirt | mobile-21 (mobile.json) |
| mobile-22 | page | PASS | Guest /buyer/help | [mobile-22](playwright/live-audit/mobile-22.png) |
| mobile-23 | layout | PASS | No horizontal overflow /buyer/help | mobile-23 (mobile.json) |
| mobile-24 | page | PASS | Guest /buyer/pages/shipping-returns | [mobile-24](playwright/live-audit/mobile-24.png) |
| mobile-25 | layout | PASS | No horizontal overflow /buyer/pages/shipping-returns | mobile-25 (mobile.json) |
| mobile-26 | page | PASS | Guest /buyer/pages/privacy-policy | [mobile-26](playwright/live-audit/mobile-26.png) |
| mobile-27 | layout | PASS | No horizontal overflow /buyer/pages/privacy-policy | mobile-27 (mobile.json) |
| mobile-28 | page | PASS | Guest /buyer/pages/terms-of-service | [mobile-28](playwright/live-audit/mobile-28.png) |
| mobile-29 | layout | PASS | No horizontal overflow /buyer/pages/terms-of-service | mobile-29 (mobile.json) |
| mobile-30 | page | PASS | Guest /buyer/pages/about | [mobile-30](playwright/live-audit/mobile-30.png) |
| mobile-31 | layout | PASS | No horizontal overflow /buyer/pages/about | mobile-31 (mobile.json) |
| mobile-32 | page | PASS | Guest /buyer/pages/contact | [mobile-32](playwright/live-audit/mobile-32.png) |
| mobile-33 | layout | PASS | No horizontal overflow /buyer/pages/contact | mobile-33 (mobile.json) |
| mobile-34 | page | PASS | Guest /register/buyer | [mobile-34](playwright/live-audit/mobile-34.png) |
| mobile-35 | layout | PASS | No horizontal overflow /register/buyer | mobile-35 (mobile.json) |
| mobile-36 | page | PASS | Guest /register/seller | [mobile-36](playwright/live-audit/mobile-36.png) |
| mobile-37 | layout | PASS | No horizontal overflow /register/seller | mobile-37 (mobile.json) |
| mobile-38 | page | PASS | Guest /register/logistics | [mobile-38](playwright/live-audit/mobile-38.png) |
| mobile-39 | layout | PASS | No horizontal overflow /register/logistics | mobile-39 (mobile.json) |
| mobile-40 | page | PASS | Category https://zefanya.shop/buyer/categories/1 | [mobile-40](playwright/live-audit/mobile-40.png) |
| mobile-41 | page | PASS | Category https://zefanya.shop/buyer/categories/2 | [mobile-41](playwright/live-audit/mobile-41.png) |
| mobile-42 | page | PASS | Category https://zefanya.shop/buyer/categories/3 | [mobile-42](playwright/live-audit/mobile-42.png) |
| mobile-43 | page | PASS | Category https://zefanya.shop/buyer/categories/4 | [mobile-43](playwright/live-audit/mobile-43.png) |
| mobile-44 | page | PASS | Category https://zefanya.shop/buyer/categories/5 | [mobile-44](playwright/live-audit/mobile-44.png) |
| mobile-45 | page | PASS | Category https://zefanya.shop/buyer/categories/6 | [mobile-45](playwright/live-audit/mobile-45.png) |
| mobile-46 | page | PASS | Category https://zefanya.shop/buyer/categories/7 | [mobile-46](playwright/live-audit/mobile-46.png) |
| mobile-47 | page | PASS | Category https://zefanya.shop/buyer/categories/8 | [mobile-47](playwright/live-audit/mobile-47.png) |
| mobile-48 | page | PASS | Category https://zefanya.shop/buyer/categories/9 | [mobile-48](playwright/live-audit/mobile-48.png) |
| mobile-49 | page | PASS | Category https://zefanya.shop/buyer/categories/10 | [mobile-49](playwright/live-audit/mobile-49.png) |
| mobile-50 | page | PASS | Category https://zefanya.shop/buyer/categories/11 | [mobile-50](playwright/live-audit/mobile-50.png) |
| mobile-51 | page | PASS | Category https://zefanya.shop/buyer/categories/12 | [mobile-51](playwright/live-audit/mobile-51.png) |
| mobile-52 | page | PASS | Category https://zefanya.shop/buyer/categories/13 | [mobile-52](playwright/live-audit/mobile-52.png) |
| mobile-53 | page | PASS | Category https://zefanya.shop/buyer/categories/14 | [mobile-53](playwright/live-audit/mobile-53.png) |
| mobile-54 | button | FAIL | Landing SHOP NOW produces a result | [mobile-54](playwright/live-audit/mobile-54.png) |
| mobile-55 | button | FAIL | Landing LOAD MORE produces a result | [mobile-55](playwright/live-audit/mobile-55.png) |
| mobile-56 | button | FAIL | Landing APP STORE produces a result | [mobile-56](playwright/live-audit/mobile-56.png) |
| mobile-57 | button | FAIL | Landing GOOGLE PLAY produces a result | [mobile-57](playwright/live-audit/mobile-57.png) |
| mobile-58 | button | FAIL | Landing card 1: QUICK VIEW produces a result | [mobile-58](playwright/live-audit/mobile-58.png) |
| mobile-59 | button | FAIL | Landing card 1: ADD produces a result | [mobile-59](playwright/live-audit/mobile-59.png) |
| mobile-60 | button | FAIL | Landing card 2: QUICK VIEW produces a result | [mobile-60](playwright/live-audit/mobile-60.png) |
| mobile-61 | button | FAIL | Landing card 2: ADD produces a result | [mobile-61](playwright/live-audit/mobile-61.png) |
| mobile-62 | button | FAIL | Landing card 3: QUICK VIEW produces a result | [mobile-62](playwright/live-audit/mobile-62.png) |
| mobile-63 | button | FAIL | Landing card 3: ADD produces a result | [mobile-63](playwright/live-audit/mobile-63.png) |
| mobile-64 | button | FAIL | Landing card 4: QUICK VIEW produces a result | [mobile-64](playwright/live-audit/mobile-64.png) |
| mobile-65 | button | FAIL | Landing card 4: ADD produces a result | [mobile-65](playwright/live-audit/mobile-65.png) |
| mobile-66 | button | PASS | Category carousel #catNext | mobile-66 (mobile.json) |
| mobile-67 | button | PASS | Category carousel #catPrev | mobile-67 (mobile.json) |
| mobile-68 | button | PASS | Category carousel #catDots button:nth-child(2) | mobile-68 (mobile.json) |
| mobile-69 | button | PASS | Login modal opens | mobile-69 (mobile.json) |
| mobile-70 | button | PASS | Login password toggle reveals and hides | mobile-70 (mobile.json) |
| mobile-72 | button | PASS | Close login hides modal | mobile-72 (mobile.json) |
| mobile-73 | button | PASS | buyer registration opens | mobile-73 (mobile.json) |
| mobile-74 | button | PASS | buyer password visibility | mobile-74 (mobile.json) |
| mobile-75 | button | PASS | buyer empty Next shows errors and stays on step 1 | mobile-75 (mobile.json) |
| mobile-76 | workflow | PASS | buyer valid personal details advance to dropdown address | mobile-76 (mobile.json) |
| mobile-77 | button | PASS | buyer registration closes | mobile-77 (mobile.json) |
| mobile-78 | button | PASS | seller registration opens | mobile-78 (mobile.json) |
| mobile-79 | button | FAIL | seller password visibility | [mobile-79](playwright/live-audit/mobile-79.png) |
| mobile-80 | button | PASS | seller empty Next shows errors and stays on step 1 | mobile-80 (mobile.json) |
| mobile-81 | workflow | PASS | seller valid personal details advance to dropdown address | mobile-81 (mobile.json) |
| mobile-82 | button | PASS | seller registration closes | mobile-82 (mobile.json) |
| mobile-83 | button | PASS | logistics registration opens | mobile-83 (mobile.json) |
| mobile-84 | button | FAIL | logistics password visibility | [mobile-84](playwright/live-audit/mobile-84.png) |
| mobile-85 | button | PASS | logistics empty Next shows errors and stays on step 1 | mobile-85 (mobile.json) |
| mobile-86 | workflow | PASS | logistics valid personal details advance to dropdown address | mobile-86 (mobile.json) |
| mobile-87 | button | PASS | logistics registration closes | mobile-87 (mobile.json) |
| mobile-88 | button | PASS | FAQ 1 expands and collapses | mobile-88 (mobile.json) |
| mobile-89 | button | PASS | FAQ 2 expands and collapses | mobile-89 (mobile.json) |
| mobile-90 | button | PASS | FAQ 3 expands and collapses | mobile-90 (mobile.json) |
| mobile-91 | button | PASS | FAQ 4 expands and collapses | mobile-91 (mobile.json) |
| mobile-92 | button | PASS | Sort dropdown opens | mobile-92 (mobile.json) |
| mobile-93 | workflow | PASS | Price ascending sort updates URL and selected label | mobile-93 (mobile.json) |
| mobile-95 | page | PASS | Product https://zefanya.shop/buyer/products/11 | [mobile-95](playwright/live-audit/mobile-95.png) |
| mobile-96 | button | PASS | Product https://zefanya.shop/buyer/products/11 quantity increases/decreases | mobile-96 (mobile.json) |
| mobile-97 | page | PASS | Product https://zefanya.shop/buyer/products/10 | [mobile-97](playwright/live-audit/mobile-97.png) |
| mobile-98 | button | PASS | Product https://zefanya.shop/buyer/products/10 quantity increases/decreases | mobile-98 (mobile.json) |
| mobile-99 | page | PASS | Product https://zefanya.shop/buyer/products/9 | [mobile-99](playwright/live-audit/mobile-99.png) |
| mobile-100 | button | PASS | Product https://zefanya.shop/buyer/products/9 quantity increases/decreases | mobile-100 (mobile.json) |
| mobile-101 | page | PASS | Product https://zefanya.shop/buyer/products/8 | [mobile-101](playwright/live-audit/mobile-101.png) |
| mobile-102 | button | PASS | Product https://zefanya.shop/buyer/products/8 quantity increases/decreases | mobile-102 (mobile.json) |
| mobile-103 | page | PASS | Product https://zefanya.shop/buyer/products/7 | [mobile-103](playwright/live-audit/mobile-103.png) |
| mobile-104 | button | PASS | Product https://zefanya.shop/buyer/products/7 quantity increases/decreases | mobile-104 (mobile.json) |
| mobile-105 | guard | PASS | Guest denied /buyer/cart | mobile-105 (mobile.json) |
| mobile-106 | guard | PASS | Guest denied /buyer/checkout | mobile-106 (mobile.json) |
| mobile-107 | guard | PASS | Guest denied /buyer/orders | mobile-107 (mobile.json) |
| mobile-108 | guard | PASS | Guest denied /buyer/account | mobile-108 (mobile.json) |
| mobile-109 | guard | PASS | Guest denied /buyer/account/address | mobile-109 (mobile.json) |
| mobile-110 | guard | PASS | Guest denied /buyer/account/password | mobile-110 (mobile.json) |
| mobile-111 | guard | PASS | Guest denied /buyer/wishlist | mobile-111 (mobile.json) |
| mobile-112 | guard | PASS | Guest denied /buyer/settings | mobile-112 (mobile.json) |
| mobile-113 | guard | PASS | Guest denied /buyer/notifications | mobile-113 (mobile.json) |
| mobile-114 | guard | PASS | Guest denied /buyer/messages | mobile-114 (mobile.json) |
| mobile-115 | guard | PASS | Guest denied /buyer/chat | mobile-115 (mobile.json) |
| mobile-116 | guard | PASS | Guest denied /seller/dashboard | mobile-116 (mobile.json) |
| mobile-117 | guard | PASS | Guest denied /seller/products | mobile-117 (mobile.json) |
| mobile-118 | guard | PASS | Guest denied /seller/orders | mobile-118 (mobile.json) |
| mobile-119 | guard | PASS | Guest denied /seller/shipments | mobile-119 (mobile.json) |
| mobile-120 | guard | PASS | Guest denied /seller/messages | mobile-120 (mobile.json) |
| mobile-121 | guard | PASS | Guest denied /seller/vouchers | mobile-121 (mobile.json) |
| mobile-122 | guard | PASS | Guest denied /seller/reports | mobile-122 (mobile.json) |
| mobile-123 | guard | PASS | Guest denied /seller/feedback | mobile-123 (mobile.json) |
| mobile-124 | guard | PASS | Guest denied /seller/account | mobile-124 (mobile.json) |
| mobile-126 | guard | PASS | Guest denied /logistics/incoming-parcels | mobile-126 (mobile.json) |
| mobile-127 | guard | PASS | Guest denied /logistics/parcel-sorting | mobile-127 (mobile.json) |
| mobile-128 | guard | PASS | Guest denied /logistics/delivery-assignments | mobile-128 (mobile.json) |
| mobile-129 | guard | PASS | Guest denied /logistics/delivery-monitoring | mobile-129 (mobile.json) |
| mobile-130 | guard | PASS | Guest denied /logistics/areas | mobile-130 (mobile.json) |
| mobile-131 | guard | PASS | Guest denied /admin/dashboard | mobile-131 (mobile.json) |
| mobile-132 | guard | PASS | Guest denied /admin/registrations | mobile-132 (mobile.json) |
| mobile-133 | guard | PASS | Guest denied /admin/users | mobile-133 (mobile.json) |
| mobile-blocked-133 | workflow | BLOCKED | Buyer authenticated cart, vouchers, wishlist, account, settings, messages and notifications | mobile-blocked-133 (mobile.json) |
| mobile-blocked-134 | workflow | BLOCKED | Buyer checkout, order placement, payment and receipt confirmation | mobile-blocked-134 (mobile.json) |
| mobile-blocked-135 | workflow | BLOCKED | Seller authenticated dashboard, catalog, orders, shipments, waybill and messages | mobile-blocked-135 (mobile.json) |
| mobile-blocked-136 | workflow | BLOCKED | Seller product/stock changes and fulfilment transitions | mobile-blocked-136 (mobile.json) |
| mobile-blocked-137 | workflow | BLOCKED | Logistics authenticated dashboards, areas and parcel lists | mobile-blocked-137 (mobile.json) |
| mobile-blocked-138 | workflow | BLOCKED | Logistics pickup, sorting, rider assignment, delivery, retry and return | mobile-blocked-138 (mobile.json) |
| mobile-blocked-139 | workflow | BLOCKED | Admin authenticated dashboard, registration queue, documents and account lists | mobile-blocked-139 (mobile.json) |
| mobile-blocked-140 | workflow | BLOCKED | Admin approvals, rejections, suspension and reactivation | mobile-blocked-140 (mobile.json) |
| mobile-blocked-141 | workflow | BLOCKED | Authenticated cross-role access and object ownership | mobile-blocked-141 (mobile.json) |
| mobile-blocked-142 | workflow | BLOCKED | Registration submission, email OTP, resend, password-reset mail and Google OAuth | mobile-blocked-142 (mobile.json) |
| desktop-extra-1 | link | PASS | Footer Help Center | [desktop-extra-1](playwright/live-audit/desktop-extra-1.png) |
| desktop-extra-2 | link | PASS | Footer How to Buy | [desktop-extra-2](playwright/live-audit/desktop-extra-2.png) |
| desktop-extra-3 | link | PASS | Footer Return & Refund | [desktop-extra-3](playwright/live-audit/desktop-extra-3.png) |
| desktop-extra-4 | link | PASS | Footer Contact Us | [desktop-extra-4](playwright/live-audit/desktop-extra-4.png) |
| desktop-extra-5 | link | PASS | Footer About Us | [desktop-extra-5](playwright/live-audit/desktop-extra-5.png) |
| desktop-extra-6 | link | PASS | Footer Privacy Policy | [desktop-extra-6](playwright/live-audit/desktop-extra-6.png) |
| desktop-extra-7 | link | PASS | Footer Terms of Service | [desktop-extra-7](playwright/live-audit/desktop-extra-7.png) |
| desktop-extra-8 | link | PASS | Footer Seller Center | [desktop-extra-8](playwright/live-audit/desktop-extra-8.png) |
| desktop-extra-9 | link | PASS | Footer Become a Courier | [desktop-extra-9](playwright/live-audit/desktop-extra-9.png) |
| desktop-extra-10 | link | FAIL | Footer Email | [desktop-extra-10](playwright/live-audit/desktop-extra-10.png) |
| desktop-extra-11 | link | FAIL | Footer Website | [desktop-extra-11](playwright/live-audit/desktop-extra-11.png) |
| desktop-extra-12 | link | FAIL | Landing VIEW ALL navigates to categories | [desktop-extra-12](playwright/live-audit/desktop-extra-12.png) |
| desktop-extra-13 | link | FAIL | Landing category card opens category | [desktop-extra-13](playwright/live-audit/desktop-extra-13.png) |
| desktop-extra-14 | button | PASS | Navbar category dropdown opens and category navigation works | [desktop-extra-14](playwright/live-audit/desktop-extra-14.png) |
| desktop-extra-15 | workflow | PASS | Navbar search submits query and shows matching results or empty state | [desktop-extra-15](playwright/live-audit/desktop-extra-15.png) |
| desktop-extra-16 | button | PASS | Login empty submission has browser validation with no POST | [desktop-extra-16](playwright/live-audit/desktop-extra-16.png) |
| desktop-extra-17 | button | PASS | Empty form /login blocks submit in browser | [desktop-extra-17](playwright/live-audit/desktop-extra-17.png) |
| desktop-extra-18 | button | PASS | Empty form /admin/login blocks submit in browser | [desktop-extra-18](playwright/live-audit/desktop-extra-18.png) |
| desktop-extra-19 | button | PASS | Empty form /forgot-password blocks submit in browser | [desktop-extra-19](playwright/live-audit/desktop-extra-19.png) |
| desktop-extra-20 | guard | PASS | Guest denied /logistics (correct dashboard route) | [desktop-extra-20](playwright/live-audit/desktop-extra-20.png) |
| desktop-extra-21 | workflow | FAIL | seller deep link address provinces load automatically | [desktop-extra-21](playwright/live-audit/desktop-extra-21.png) |
| desktop-extra-22 | workflow | FAIL | logistics deep link address provinces load automatically | [desktop-extra-22](playwright/live-audit/desktop-extra-22.png) |
| desktop-extra-28 | button | FAIL | List view changes computed grid columns | [desktop-extra-28](playwright/live-audit/desktop-extra-28.png) |
| desktop-extra-29 | button | PASS | Catalog guest [data-add-to-cart] opens login without mutation | [desktop-extra-29](playwright/live-audit/desktop-extra-29.png) |
| desktop-extra-30 | button | PASS | Catalog guest [data-wishlist-toggle] opens login without mutation | [desktop-extra-30](playwright/live-audit/desktop-extra-30.png) |
| desktop-extra-31 | button | BLOCKED | /buyer/products/11 guest #pdp-add-cart opens login | [desktop-extra-31](playwright/live-audit/desktop-extra-31.png) |
| desktop-extra-32 | button | BLOCKED | /buyer/products/11 guest #pdp-buy-now opens login | [desktop-extra-32](playwright/live-audit/desktop-extra-32.png) |
| desktop-extra-34 | button | BLOCKED | /buyer/products/10 guest #pdp-add-cart opens login | [desktop-extra-34](playwright/live-audit/desktop-extra-34.png) |
| desktop-extra-35 | button | BLOCKED | /buyer/products/10 guest #pdp-buy-now opens login | [desktop-extra-35](playwright/live-audit/desktop-extra-35.png) |
| desktop-extra-37 | button | BLOCKED | /buyer/products/9 guest #pdp-add-cart opens login | [desktop-extra-37](playwright/live-audit/desktop-extra-37.png) |
| desktop-extra-38 | button | BLOCKED | /buyer/products/9 guest #pdp-buy-now opens login | [desktop-extra-38](playwright/live-audit/desktop-extra-38.png) |
| desktop-extra-40 | button | BLOCKED | /buyer/products/8 guest #pdp-add-cart opens login | [desktop-extra-40](playwright/live-audit/desktop-extra-40.png) |
| desktop-extra-41 | button | BLOCKED | /buyer/products/8 guest #pdp-buy-now opens login | [desktop-extra-41](playwright/live-audit/desktop-extra-41.png) |
| desktop-extra-43 | button | BLOCKED | /buyer/products/7 guest #pdp-add-cart opens login | [desktop-extra-43](playwright/live-audit/desktop-extra-43.png) |
| desktop-extra-44 | button | BLOCKED | /buyer/products/7 guest #pdp-buy-now opens login | [desktop-extra-44](playwright/live-audit/desktop-extra-44.png) |
| mobile-extra-1 | link | PASS | Footer Help Center | [mobile-extra-1](playwright/live-audit/mobile-extra-1.png) |
| mobile-extra-2 | link | PASS | Footer How to Buy | [mobile-extra-2](playwright/live-audit/mobile-extra-2.png) |
| mobile-extra-3 | link | PASS | Footer Return & Refund | [mobile-extra-3](playwright/live-audit/mobile-extra-3.png) |
| mobile-extra-4 | link | PASS | Footer Contact Us | [mobile-extra-4](playwright/live-audit/mobile-extra-4.png) |
| mobile-extra-5 | link | PASS | Footer About Us | [mobile-extra-5](playwright/live-audit/mobile-extra-5.png) |
| mobile-extra-6 | link | PASS | Footer Privacy Policy | [mobile-extra-6](playwright/live-audit/mobile-extra-6.png) |
| mobile-extra-7 | link | PASS | Footer Terms of Service | [mobile-extra-7](playwright/live-audit/mobile-extra-7.png) |
| mobile-extra-8 | link | PASS | Footer Seller Center | [mobile-extra-8](playwright/live-audit/mobile-extra-8.png) |
| mobile-extra-9 | link | PASS | Footer Become a Courier | [mobile-extra-9](playwright/live-audit/mobile-extra-9.png) |
| mobile-extra-10 | link | FAIL | Footer Email | [mobile-extra-10](playwright/live-audit/mobile-extra-10.png) |
| mobile-extra-11 | link | FAIL | Footer Website | [mobile-extra-11](playwright/live-audit/mobile-extra-11.png) |
| mobile-extra-12 | link | FAIL | Landing VIEW ALL navigates to categories | [mobile-extra-12](playwright/live-audit/mobile-extra-12.png) |
| mobile-extra-13 | link | FAIL | Landing category card opens category | [mobile-extra-13](playwright/live-audit/mobile-extra-13.png) |
| mobile-extra-14 | button | PASS | Navbar category dropdown opens and category navigation works | [mobile-extra-14](playwright/live-audit/mobile-extra-14.png) |
| mobile-extra-15 | workflow | PASS | Navbar search submits query and shows matching results or empty state | [mobile-extra-15](playwright/live-audit/mobile-extra-15.png) |
| mobile-extra-16 | button | PASS | Login empty submission has browser validation with no POST | [mobile-extra-16](playwright/live-audit/mobile-extra-16.png) |
| mobile-extra-17 | button | PASS | Empty form /login blocks submit in browser | [mobile-extra-17](playwright/live-audit/mobile-extra-17.png) |
| mobile-extra-18 | button | PASS | Empty form /admin/login blocks submit in browser | [mobile-extra-18](playwright/live-audit/mobile-extra-18.png) |
| mobile-extra-19 | button | PASS | Empty form /forgot-password blocks submit in browser | [mobile-extra-19](playwright/live-audit/mobile-extra-19.png) |
| mobile-extra-20 | guard | PASS | Guest denied /logistics (correct dashboard route) | [mobile-extra-20](playwright/live-audit/mobile-extra-20.png) |
| mobile-extra-21 | workflow | FAIL | seller deep link address provinces load automatically | [mobile-extra-21](playwright/live-audit/mobile-extra-21.png) |
| mobile-extra-22 | workflow | FAIL | logistics deep link address provinces load automatically | [mobile-extra-22](playwright/live-audit/mobile-extra-22.png) |
| mobile-extra-28 | button | FAIL | List view changes computed grid columns | [mobile-extra-28](playwright/live-audit/mobile-extra-28.png) |
| mobile-extra-29 | button | PASS | Catalog guest [data-add-to-cart] opens login without mutation | [mobile-extra-29](playwright/live-audit/mobile-extra-29.png) |
| mobile-extra-30 | button | PASS | Catalog guest [data-wishlist-toggle] opens login without mutation | [mobile-extra-30](playwright/live-audit/mobile-extra-30.png) |
| mobile-extra-31 | button | BLOCKED | /buyer/products/11 guest #pdp-add-cart opens login | [mobile-extra-31](playwright/live-audit/mobile-extra-31.png) |
| mobile-extra-32 | button | BLOCKED | /buyer/products/11 guest #pdp-buy-now opens login | [mobile-extra-32](playwright/live-audit/mobile-extra-32.png) |
| mobile-extra-34 | button | BLOCKED | /buyer/products/10 guest #pdp-add-cart opens login | [mobile-extra-34](playwright/live-audit/mobile-extra-34.png) |
| mobile-extra-35 | button | BLOCKED | /buyer/products/10 guest #pdp-buy-now opens login | [mobile-extra-35](playwright/live-audit/mobile-extra-35.png) |
| mobile-extra-37 | button | BLOCKED | /buyer/products/9 guest #pdp-add-cart opens login | [mobile-extra-37](playwright/live-audit/mobile-extra-37.png) |
| mobile-extra-38 | button | BLOCKED | /buyer/products/9 guest #pdp-buy-now opens login | [mobile-extra-38](playwright/live-audit/mobile-extra-38.png) |
| mobile-extra-40 | button | BLOCKED | /buyer/products/8 guest #pdp-add-cart opens login | [mobile-extra-40](playwright/live-audit/mobile-extra-40.png) |
| mobile-extra-41 | button | BLOCKED | /buyer/products/8 guest #pdp-buy-now opens login | [mobile-extra-41](playwright/live-audit/mobile-extra-41.png) |
| mobile-extra-43 | button | BLOCKED | /buyer/products/7 guest #pdp-add-cart opens login | [mobile-extra-43](playwright/live-audit/mobile-extra-43.png) |
| mobile-extra-44 | button | BLOCKED | /buyer/products/7 guest #pdp-buy-now opens login | [mobile-extra-44](playwright/live-audit/mobile-extra-44.png) |
| desktop-verify-1 | page | BLOCKED | Guest /register/buyer/pending | desktop-verify-1 (desktop-verification.json) |
| desktop-verify-2 | page | BLOCKED | Guest /register/seller/pending | desktop-verify-2 (desktop-verification.json) |
| desktop-verify-3 | page | BLOCKED | Guest /register/logistics/pending | desktop-verify-3 (desktop-verification.json) |
| desktop-verify-4 | page | BLOCKED | Guest /seller/login | desktop-verify-4 (desktop-verification.json) |
| desktop-verify-5 | link | BLOCKED | Navbar Sale has a shopping destination | [desktop-verify-5](playwright/live-audit/desktop-verify-5.png) |
| desktop-verify-6 | workflow | BLOCKED | Verified catalog price_asc result | desktop-verify-6 (desktop-verification.json) |
| desktop-not-run-356 | workflow | BLOCKED | Verified catalog price_desc result | desktop-not-run-356 (final verification interruption) |
| desktop-not-run-357 | workflow | BLOCKED | Verified catalog newest result | desktop-not-run-357 (final verification interruption) |
| desktop-not-run-358 | workflow | BLOCKED | Verified catalog oldest result | desktop-not-run-358 (final verification interruption) |
| desktop-not-run-359 | workflow | BLOCKED | Verified catalog relevance result | desktop-not-run-359 (final verification interruption) |
| desktop-not-run-360 | button | BLOCKED | Verified full image /buyer/products/11 | desktop-not-run-360 (final verification interruption) |
| desktop-not-run-361 | button | BLOCKED | Verified full image /buyer/products/10 | desktop-not-run-361 (final verification interruption) |
| desktop-not-run-362 | button | BLOCKED | Verified full image /buyer/products/9 | desktop-not-run-362 (final verification interruption) |
| desktop-not-run-363 | button | BLOCKED | Verified full image /buyer/products/8 | desktop-not-run-363 (final verification interruption) |
| desktop-not-run-364 | button | BLOCKED | Verified full image /buyer/products/7 | desktop-not-run-364 (final verification interruption) |
| mobile-not-run-365 | page | BLOCKED | Guest /register/buyer/pending | mobile-not-run-365 (final verification interruption) |
| mobile-not-run-366 | page | BLOCKED | Guest /register/seller/pending | mobile-not-run-366 (final verification interruption) |
| mobile-not-run-367 | page | BLOCKED | Guest /register/logistics/pending | mobile-not-run-367 (final verification interruption) |
| mobile-not-run-368 | page | BLOCKED | Guest /seller/login | mobile-not-run-368 (final verification interruption) |
| mobile-not-run-369 | workflow | BLOCKED | Verified catalog price_asc result | mobile-not-run-369 (final verification interruption) |
| mobile-not-run-370 | workflow | BLOCKED | Verified catalog price_desc result | mobile-not-run-370 (final verification interruption) |
| mobile-not-run-371 | workflow | BLOCKED | Verified catalog newest result | mobile-not-run-371 (final verification interruption) |
| mobile-not-run-372 | workflow | BLOCKED | Verified catalog oldest result | mobile-not-run-372 (final verification interruption) |
| mobile-not-run-373 | workflow | BLOCKED | Verified catalog relevance result | mobile-not-run-373 (final verification interruption) |
| mobile-not-run-374 | button | BLOCKED | Verified full image /buyer/products/11 | mobile-not-run-374 (final verification interruption) |
| mobile-not-run-375 | button | BLOCKED | Verified full image /buyer/products/10 | mobile-not-run-375 (final verification interruption) |
| mobile-not-run-376 | button | BLOCKED | Verified full image /buyer/products/9 | mobile-not-run-376 (final verification interruption) |
| mobile-not-run-377 | button | BLOCKED | Verified full image /buyer/products/8 | mobile-not-run-377 (final verification interruption) |
| mobile-not-run-378 | button | BLOCKED | Verified full image /buyer/products/7 | mobile-not-run-378 (final verification interruption) |

## Audit corrections excluded from totals

- desktop-71: Initial audit used nonexistent #loginForm; corrected supplemental assertion supersedes it.
- desktop-94: innerHTML comparison could pass on menu dismissal; computed grid comparison in supplemental supersedes it.
- desktop-125: Audit used a nonexistent route; actual dashboard is /logistics and supplemental guest guard supersedes it.
- mobile-71: Initial audit used nonexistent #loginForm; corrected supplemental assertion supersedes it.
- mobile-94: innerHTML comparison could pass on menu dismissal; computed grid comparison in supplemental supersedes it.
- mobile-125: Audit used a nonexistent route; actual dashboard is /logistics and supplemental guest guard supersedes it.
- desktop-extra-23: Initial catalog assertion used local CSS structure absent in deployed markup; stable-attribute verification supersedes it.
- desktop-extra-24: Initial catalog assertion used local CSS structure absent in deployed markup; stable-attribute verification supersedes it.
- desktop-extra-25: Initial catalog assertion used local CSS structure absent in deployed markup; stable-attribute verification supersedes it.
- desktop-extra-26: Initial catalog assertion used local CSS structure absent in deployed markup; stable-attribute verification supersedes it.
- desktop-extra-27: Initial catalog assertion used local CSS structure absent in deployed markup; stable-attribute verification supersedes it.
- desktop-extra-33: Initial image assertion compared relative src with absolute popup URL; normalized URL verification supersedes it.
- desktop-extra-36: Initial image assertion compared relative src with absolute popup URL; normalized URL verification supersedes it.
- desktop-extra-39: Initial image assertion compared relative src with absolute popup URL; normalized URL verification supersedes it.
- desktop-extra-42: Initial image assertion compared relative src with absolute popup URL; normalized URL verification supersedes it.
- desktop-extra-45: Initial image assertion compared relative src with absolute popup URL; normalized URL verification supersedes it.
- mobile-extra-23: Initial catalog assertion used local CSS structure absent in deployed markup; stable-attribute verification supersedes it.
- mobile-extra-24: Initial catalog assertion used local CSS structure absent in deployed markup; stable-attribute verification supersedes it.
- mobile-extra-25: Initial catalog assertion used local CSS structure absent in deployed markup; stable-attribute verification supersedes it.
- mobile-extra-26: Initial catalog assertion used local CSS structure absent in deployed markup; stable-attribute verification supersedes it.
- mobile-extra-27: Initial catalog assertion used local CSS structure absent in deployed markup; stable-attribute verification supersedes it.
- mobile-extra-33: Initial image assertion compared relative src with absolute popup URL; normalized URL verification supersedes it.
- mobile-extra-36: Initial image assertion compared relative src with absolute popup URL; normalized URL verification supersedes it.
- mobile-extra-39: Initial image assertion compared relative src with absolute popup URL; normalized URL verification supersedes it.
- mobile-extra-42: Initial image assertion compared relative src with absolute popup URL; normalized URL verification supersedes it.
- mobile-extra-45: Initial image assertion compared relative src with absolute popup URL; normalized URL verification supersedes it.
- mobile-verify-1: Audit extraction ReferenceError; not an application failure. Corrected verification could not complete after repeated navigation timeouts.
- mobile-verify-2: Audit extraction ReferenceError; not an application failure. Corrected verification could not complete after repeated navigation timeouts.
- mobile-verify-3: Audit extraction ReferenceError; not an application failure. Corrected verification could not complete after repeated navigation timeouts.
- mobile-verify-4: Audit extraction ReferenceError; not an application failure. Corrected verification could not complete after repeated navigation timeouts.

## Reproduce

PowerShell:

```powershell
$env:ZEFANYA_LIVE_AUDIT='1'
npx playwright test --config=playwright.config.ts
node playwright/generate-functional-audit.mjs
```

The live mode excludes the existing localhost buyer-registration tests, disables webServer, and forces one worker. The default config still uses the original local environment. Browser findings persist in playwright/live-audit/*.json and screenshots; generator creates this report and summary.json. No PHP/CSS/application JS was edited, so Pint and asset rebuild are not applicable.
