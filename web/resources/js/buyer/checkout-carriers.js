// Buyer checkout: recalculate shipping + payable total from server-rendered fees.
// Never trusts user-typed prices — only radio values (carrier ids) are submitted.
(function () {
    function pesos(minor) {
        if (minor === 0) return 'Free';
        return '₱' + (minor / 100).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function payableLabel(minor) {
        return '₱' + (minor / 100).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function init() {
        var root = document.querySelector('[data-checkout-carriers]');
        if (!root) return;

        var merchandise = parseInt(root.getAttribute('data-merchandise-minor') || '0', 10) || 0;
        var shippingVoucherType = root.getAttribute('data-shipping-voucher-type') || '';
        var shippingVoucherValue = parseInt(root.getAttribute('data-shipping-voucher-value') || '0', 10) || 0;
        var shippingEl = document.querySelector('[data-checkout-shipping-total]');
        var shippingDiscountRow = document.querySelector('[data-checkout-shipping-discount-row]');
        var shippingDiscountEl = document.querySelector('[data-checkout-shipping-discount]');
        var payableEl = document.querySelector('[data-checkout-payable-total]');
        var placeButtons = document.querySelectorAll('[data-checkout-place-order]');
        var blockNotice = document.querySelector('[data-checkout-shipping-block]');

        function selectedShippingMinor() {
            var total = 0;
            var groups = root.querySelectorAll('[data-carrier-group]');
            for (var i = 0; i < groups.length; i++) {
                var group = groups[i];
                if (group.getAttribute('data-available') !== '1') {
                    return null;
                }
                var checked = group.querySelector('input[type="radio"][name^="carriers["]:checked');
                if (!checked) {
                    return null;
                }
                total += parseInt(checked.getAttribute('data-fee-minor') || '0', 10) || 0;
            }
            return total;
        }

        // Mirrors Money::percentage() (half-up) on the server. The submitted
        // order is still re-priced server-side, so this is display only.
        function shippingDiscount(fee) {
            if (fee <= 0 || shippingVoucherValue <= 0) {
                return 0;
            }
            if (shippingVoucherType === 'fixed') {
                return Math.min(fee, shippingVoucherValue);
            }
            if (shippingVoucherType === 'percent') {
                return Math.min(fee, Math.floor((fee * shippingVoucherValue + 50) / 100));
            }
            return 0;
        }

        function refresh() {
            var grossShipping = selectedShippingMinor();
            var blocked = grossShipping === null;
            var discount = blocked ? 0 : shippingDiscount(grossShipping);
            var netShipping = blocked ? 0 : Math.max(0, grossShipping - discount);
            if (shippingEl) {
                shippingEl.textContent = blocked ? 'Select a carrier' : pesos(netShipping);
            }
            if (shippingDiscountRow) {
                shippingDiscountRow.hidden = blocked || discount <= 0;
            }
            if (shippingDiscountEl) {
                shippingDiscountEl.textContent = '-₱' + (discount / 100).toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
            }
            if (payableEl) {
                payableEl.textContent = blocked
                    ? payableLabel(merchandise)
                    : payableLabel(merchandise + netShipping);
            }
            placeButtons.forEach(function (btn) {
                btn.disabled = blocked || merchandise === 0;
                var label = btn.querySelector('[data-checkout-place-label]');
                if (label && !blocked) {
                    label.textContent = 'Place Order — ' + payableLabel(merchandise + netShipping);
                } else if (label && blocked) {
                    label.textContent = 'Place Order';
                }
            });
            if (blockNotice) {
                blockNotice.hidden = !blocked;
            }
        }

        root.addEventListener('change', function (event) {
            if (event.target && event.target.matches('input[type="radio"][name^="carriers["]')) {
                refresh();
            }
        });

        refresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
