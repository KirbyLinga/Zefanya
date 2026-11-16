{{--
    Per-product purchase limits dialog (Seller › Products › ⋮ › Purchase limits).
    Filled and submitted by public/js/seller/product-purchase-limits.js. Styles are
    inline and scoped to .pl-* so the page needs no asset rebuild; colours come
    from the shared theme tokens, so light and dark mode both work.
--}}
@php($trustedOrders = \App\Services\Buyer\PurchaseLimitService::TRUSTED_COMPLETED_ORDERS)
<dialog id="plDialog" class="pl-dialog" aria-labelledby="plTitle" aria-describedby="plIntro">
    <form class="pl-form" data-pl-form novalidate>
        <header class="pl-head">
            <span class="pl-head__icon" aria-hidden="true"><i data-lucide="shield-check" width="18" height="18"></i></span>
            <div class="pl-head__text">
                <h2 id="plTitle" class="pl-title">Purchase limits</h2>
                <p class="pl-product" data-pl-product></p>
            </div>
            <button type="button" class="pl-close" data-pl-close aria-label="Close">
                <i data-lucide="x" width="18" height="18"></i>
            </button>
        </header>

        <div class="pl-body">
            <label class="pl-toggle pl-toggle--main">
                <span class="pl-toggle__text">
                    <span class="pl-toggle__title">Limit how much one customer can buy</span>
                    <span class="pl-toggle__hint" id="plIntro">Protects you from very large cash-on-delivery orders of this product.</span>
                </span>
                <input type="checkbox" role="switch" class="pl-switch" name="enabled" data-pl-enabled>
            </label>
            <p class="pl-error" data-pl-error="enabled" hidden></p>

            <div class="pl-fields" data-pl-fields>
                <div class="pl-grid">
                    <div class="pl-field">
                        <label for="plMaxQuantity" class="pl-label">Units per order</label>
                        <div class="pl-input">
                            <input id="plMaxQuantity" name="max_quantity" type="number" inputmode="numeric" min="1" max="999" step="1" placeholder="No limit" aria-describedby="plMaxQuantityHint">
                            <span class="pl-input__affix">units</span>
                        </div>
                        <p class="pl-hint" id="plMaxQuantityHint">Most units of this product in one order.</p>
                        <p class="pl-error" data-pl-error="max_quantity" hidden></p>
                    </div>

                    <div class="pl-field">
                        <label for="plMaxOrder" class="pl-label">Value per order</label>
                        <div class="pl-input">
                            <span class="pl-input__affix">₱</span>
                            <input id="plMaxOrder" name="max_order" inputmode="decimal" placeholder="No limit" autocomplete="off" aria-describedby="plMaxOrderHint">
                        </div>
                        <p class="pl-hint" id="plMaxOrderHint">This product's value in one order, after discounts, excluding shipping.</p>
                        <p class="pl-error" data-pl-error="max_order" hidden></p>
                    </div>

                    <div class="pl-field">
                        <label for="plMaxActive" class="pl-label">Unpaid COD value</label>
                        <div class="pl-input">
                            <span class="pl-input__affix">₱</span>
                            <input id="plMaxActive" name="max_active" inputmode="decimal" placeholder="No limit" autocomplete="off" aria-describedby="plMaxActiveHint">
                        </div>
                        <p class="pl-hint" id="plMaxActiveHint">This product's total across a customer's orders not yet delivered and paid, including failed deliveries awaiting a retry.</p>
                        <p class="pl-error" data-pl-error="max_active" hidden></p>
                    </div>
                </div>

                <label class="pl-toggle">
                    <span class="pl-toggle__text">
                        <span class="pl-toggle__title">Skip limits for loyal customers</span>
                        <span class="pl-toggle__hint">Customers with at least {{ $trustedOrders }} completed, paid orders from your shop can buy without these limits.</span>
                    </span>
                    <input type="checkbox" role="switch" class="pl-switch" name="exempt_trusted" data-pl-exempt>
                </label>
            </div>

            <p class="pl-note">
                <i data-lucide="info" width="14" height="14" aria-hidden="true"></i>
                <span>Leave a field empty for no limit. Limits apply to new checkouts only; orders already placed are not changed. Customers see a neutral shop-policy message.</span>
            </p>
            <p class="pl-error pl-error--general" data-pl-error="general" role="alert" hidden></p>
        </div>

        <footer class="pl-foot">
            <button type="button" class="pl-btn pl-btn--ghost" data-pl-close>Cancel</button>
            <button type="submit" class="pl-btn pl-btn--solid" data-pl-submit>Save limits</button>
        </footer>
    </form>
</dialog>

<style>
    .seller-body .sp-pill--limit { background-color: var(--pill-primary-bg); color: var(--link); }
    .seller-body .sp-pill--limit[hidden] { display: none; }

    .pl-dialog { width: calc(100% - 32px); max-width: 640px; max-height: calc(100dvh - 32px); margin: auto; padding: 0; overflow: hidden;
        border: 1px solid var(--border-strong); border-radius: 16px; background: var(--bg-surface); color: var(--text-ink);
        font-family: var(--font-sans); box-shadow: 0 24px 60px rgba(0, 0, 0, .22); }
    .pl-dialog::backdrop { background: var(--overlay, rgba(0, 0, 0, .55)); }
    .pl-form { display: flex; flex-direction: column; max-height: calc(100dvh - 34px); }

    .pl-head { display: flex; align-items: flex-start; gap: 12px; padding: 20px 22px 16px; border-bottom: 1px solid var(--border-hairline, var(--border-strong)); }
    .pl-head__icon { display: grid; place-items: center; flex-shrink: 0; width: 38px; height: 38px; border-radius: 10px;
        background: var(--pill-primary-bg); color: var(--link); }
    .pl-head__text { flex: 1; min-width: 0; }
    .pl-title { margin: 0; font-family: var(--font-serif); font-size: 20px; line-height: 1.25; color: var(--text-ink); }
    .pl-product { margin: 2px 0 0; font-size: 13px; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pl-close { display: grid; place-items: center; width: 32px; height: 32px; border: 0; border-radius: 8px; background: transparent; color: var(--text-muted); cursor: pointer; }
    .pl-close:hover { background: var(--bg-chip); color: var(--text-ink); }

    .pl-body { display: flex; flex-direction: column; gap: 16px; padding: 18px 22px; overflow-y: auto; }

    .pl-toggle { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 16px;
        border: 1px solid var(--border-strong); border-radius: 12px; background: var(--bg-raised); cursor: pointer; }
    .pl-toggle--main { background: var(--bg-surface); }
    .pl-toggle__text { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
    .pl-toggle__title { font-size: 14px; font-weight: 600; color: var(--text-ink); }
    .pl-toggle__hint { font-size: 12.5px; line-height: 1.45; color: var(--text-muted); }

    .pl-switch { appearance: none; -webkit-appearance: none; position: relative; flex-shrink: 0; width: 44px; height: 24px; margin: 0;
        border-radius: 999px; background: var(--border-btn-outline-hover, #c9c0b8); cursor: pointer; transition: background-color .18s ease; }
    .pl-switch::after { content: ""; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%;
        background: #fff; box-shadow: 0 1px 3px rgba(0, 0, 0, .25); transition: transform .18s ease; }
    .pl-switch:checked { background: var(--btn-primary-bg); }
    .pl-switch:checked::after { transform: translateX(20px); }
    .pl-switch:focus-visible { outline: 2px solid var(--btn-primary-bg); outline-offset: 3px; }

    .pl-fields { display: flex; flex-direction: column; gap: 16px; }
    .pl-fields[hidden] { display: none; }
    .pl-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .pl-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .pl-label { font-size: 12.5px; font-weight: 600; color: var(--text-label, var(--text-ink)); }
    .pl-input { display: flex; align-items: center; height: 40px; border: 1px solid var(--border-input); border-radius: 9px;
        background: var(--bg-surface); overflow: hidden; transition: border-color .15s, box-shadow .15s; }
    .pl-input:focus-within { border-color: var(--btn-primary-bg); box-shadow: 0 0 0 3px var(--pill-primary-bg); }
    .pl-input[data-invalid] { border-color: var(--status-danger); }
    .pl-input input { flex: 1; min-width: 0; height: 100%; padding: 0 10px; border: 0; outline: 0; background: transparent;
        color: var(--text-ink); font: inherit; font-size: 14px; }
    .pl-input input::placeholder { color: var(--text-placeholder); }
    .pl-input input::-webkit-outer-spin-button, .pl-input input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .pl-input input[type=number] { -moz-appearance: textfield; }
    .pl-input__affix { display: grid; place-items: center; align-self: stretch; padding: 0 10px; background: var(--bg-chip);
        color: var(--text-muted); font-size: 12.5px; font-weight: 600; }
    .pl-hint { margin: 0; font-size: 11.5px; line-height: 1.45; color: var(--text-muted); }
    .pl-error { margin: 0; font-size: 12px; color: var(--status-danger); }
    .pl-error[hidden] { display: none; }
    .pl-error--general { padding: 10px 12px; border-radius: 8px; background: var(--pill-danger-bg); }

    .pl-note { display: flex; gap: 8px; margin: 0; padding: 10px 12px; border-radius: 10px; background: var(--bg-soft);
        font-size: 12px; line-height: 1.5; color: var(--text-muted); }
    .pl-note svg { flex-shrink: 0; margin-top: 2px; }

    .pl-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 22px; border-top: 1px solid var(--border-hairline, var(--border-strong)); }
    .pl-btn { height: 40px; padding: 0 18px; border-radius: 9px; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; }
    .pl-btn--ghost { border: 1px solid var(--border-btn-outline); background: var(--bg-surface); color: var(--btn-cancel-text, var(--text-ink)); }
    .pl-btn--ghost:hover { border-color: var(--border-btn-outline-hover); }
    .pl-btn--solid { border: 1px solid var(--btn-primary-bg); background: var(--btn-primary-bg); color: var(--btn-primary-text); }
    .pl-btn--solid:hover { background: var(--btn-primary-bg-hover); border-color: var(--btn-primary-bg-hover); }
    .pl-btn[disabled] { opacity: .6; cursor: progress; }

    @media (max-width: 640px) {
        .pl-grid { grid-template-columns: 1fr; }
        .pl-head, .pl-body, .pl-foot { padding-left: 16px; padding-right: 16px; }
        .pl-foot .pl-btn { flex: 1; }
    }
</style>
