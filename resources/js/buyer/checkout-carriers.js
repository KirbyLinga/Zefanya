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
        var shippingEl = document.querySelector('[data-checkout-shipping-total]');
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

        function refresh() {
            var shipping = selectedShippingMinor();
            var blocked = shipping === null;
            if (shippingEl) {
                shippingEl.textContent = blocked ? 'Select a carrier' : pesos(shipping);
            }
            if (payableEl) {
                payableEl.textContent = blocked
                    ? payableLabel(merchandise)
                    : payableLabel(merchandise + shipping);
            }
            placeButtons.forEach(function (btn) {
                btn.disabled = blocked || merchandise === 0;
                var label = btn.querySelector('[data-checkout-place-label]');
                if (label && !blocked) {
                    label.textContent = 'Place Order — ' + payableLabel(merchandise + shipping);
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
