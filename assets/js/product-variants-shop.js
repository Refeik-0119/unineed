function changeQuantity(btn, change) {
    const form = btn.closest('form');
    if (!form) return;

    const qtyInput = form.querySelector('input[name="quantity"]');
    if (!qtyInput) return;

    if (qtyInput.disabled) qtyInput.disabled = false;

    const modal = form.closest('.modal');
    const isPreorder = modal && modal.querySelector('.badge.bg-info') !== null;

    const current = parseInt(qtyInput.value) || 1;

    let max = 9999;
    if (!isPreorder) {
        if (modal) {
            const stockEl = modal.querySelector('[id^="displayStock"]');
            const stockMatch = stockEl?.textContent.match(/(\d+)/);
            if (stockMatch) max = parseInt(stockMatch[0], 10);
        }
    }

    let next = current + change;
    if (!isPreorder) {
        next = Math.max(1, next);
        next = Math.min(max, next);
        // Ensure the input max attribute always matches computed stock cap
        qtyInput.max = max;
    }

    qtyInput.value = next;
    qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
}

document.addEventListener('click', function (event) {
    const btn = event.target.closest('.qty-increase, .qty-decrease');
    if (!btn) return;

    event.stopImmediatePropagation();
    event.preventDefault();

    if (btn.dataset.qtyProcessing === '1') return;
    btn.dataset.qtyProcessing = '1';

    const delta = btn.classList.contains('qty-increase') ? 1 : -1;
    changeQuantity(btn, delta);

    // Release within the next tick
    setTimeout(() => {
        delete btn.dataset.qtyProcessing;
    }, 50);
});
    // Utility to format currency
    function formatCurrency(amount) {
        return '₱' + parseFloat(amount || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    }

    // Update total and add-button state for a product
    function updateTotalAndButton(productId, selectedPrice, availableStock, isPreorder = false) {
        const qtyInput = document.getElementById(`quantityInput${productId}`);
        const totalDisplay = document.getElementById(`displayTotal${productId}`);
        // Prefer modal submit button, fallback to page-level add button
        const addBtn = document.getElementById(`modalAddBtn${productId}`) || document.getElementById(`addToCartBtn${productId}`);

        let qty = 1;
        if (qtyInput) qty = parseInt(qtyInput.value) || 1;

        const total = (parseFloat(selectedPrice) || 0) * qty;
        if (totalDisplay) totalDisplay.textContent = formatCurrency(total);

        // Enable add button: for preorder, always allow if price is set; for stocked items, check stock
        if (addBtn) {
            if (isPreorder) {
                addBtn.disabled = (qty <= 0 || !selectedPrice);
            } else {
                if ((availableStock === null || availableStock === undefined) || (availableStock > 0 && qty > 0 && qty <= availableStock)) {
                    addBtn.disabled = false;
                } else {
                    addBtn.disabled = true;
                }
            }
        }
    }

    // Handle variant selection for every product's selects
    document.querySelectorAll('.variant-select').forEach(select => {
        select.addEventListener('change', function() {
            const productId = this.dataset.productId;
            const variantSelects = document.querySelectorAll(`select[data-product-id="${productId}"]`);
            const quantityInput = document.getElementById(`quantityInput${productId}`);
            const displayPrice = document.getElementById(`displayPrice${productId}`);
            const displayStock = document.getElementById(`displayStock${productId}`);
            const variantPriceInput = document.getElementById(`variantPrice${productId}`);
            const addBtn = document.getElementById(`modalAddBtn${productId}`);
            
            // Check if product is preorder
            const isPreorder = addBtn && addBtn.closest('.modal-content') && 
                               addBtn.closest('.modal-content').querySelector('.badge.bg-info') !== null;

            // Check if all variants are selected
            let allSelected = true;
            let selectedPrice = 0;
            let selectedStock = Infinity;

            variantSelects.forEach(s => {
                const selectedOption = s.options[s.selectedIndex];
                if (!selectedOption || !selectedOption.value) {
                    allSelected = false;
                } else {
                    const p = parseFloat(selectedOption.dataset.price) || 0;
                    const st = parseInt(selectedOption.dataset.stock) || 0;
                    selectedPrice = Math.max(selectedPrice, p);
                    selectedStock = Math.min(selectedStock, st);
                }
            });

            if (allSelected) {
                displayPrice.textContent = formatCurrency(selectedPrice);
                if (displayStock) {
                    displayStock.textContent = `${selectedStock} units`;
                }
                variantPriceInput.value = selectedPrice;
                quantityInput.disabled = false;
                if (!isPreorder) {
                    quantityInput.max = selectedStock;
                    quantityInput.dataset.stockMax = selectedStock;
                    if (parseInt(quantityInput.value) > selectedStock) {
                        quantityInput.value = selectedStock;
                    }
                }
                updateTotalAndButton(productId, selectedPrice, selectedStock, isPreorder);
            } else {
                displayPrice.textContent = 'Select variants to see price';
                if (displayStock) {
                    displayStock.textContent = 'Select variants to see stock';
                }
                variantPriceInput.value = '';
                quantityInput.disabled = true;
                updateTotalAndButton(productId, 0, 0, isPreorder);
            }
        });
    });

    // Handle quantity changes to update total
    document.querySelectorAll('input[id^="quantityInput"]').forEach(q => {
        q.addEventListener('input', function() {
            const productId = this.id.replace('quantityInput', '');
            const variantPriceInput = document.getElementById(`variantPrice${productId}`);
            const price = variantPriceInput && variantPriceInput.value ? parseFloat(variantPriceInput.value) : document.querySelector(`#displayPrice${productId}`) ? parseFloat(document.getElementById(`displayPrice${productId}`).textContent.replace(/[^0-9.-]+/g, '')) : 0;
            const displayStockEl = document.getElementById(`displayStock${productId}`);
            let availableStock = null;
            let isPreorder = false;
            
            // Check if this is a preorder product
            const modal = q.closest('.modal-content');
            if (modal && modal.querySelector('.badge.bg-info')) {
                isPreorder = true;
            }
            
            if (displayStockEl) {
                const m = displayStockEl.textContent.match(/(\d+)/);
                availableStock = m ? parseInt(m[0]) : null;
            }
            // Clamp quantity to min/max (use variant stock if available)
            let max = 9999;
            if (!isPreorder) {
                // Prefer explicit stockMax dataset set by variant selection
                const stockMaxAttr = parseInt(this.dataset.stockMax, 10);
                if (!Number.isNaN(stockMaxAttr) && stockMaxAttr > 0) {
                    max = stockMaxAttr;
                } else {
                    // Use max attribute if set
                    const maxAttr = parseInt(this.max, 10);
                    if (!Number.isNaN(maxAttr) && maxAttr > 0) {
                        max = maxAttr;
                    }

                    // Fallback to displayed stock if nothing else is available
                    if (max === 9999 && availableStock !== null) {
                        max = availableStock;
                    }
                }
            }

            if (parseInt(this.value) > max) this.value = max;
            // For normal products, enforce minimum of 1; for preorder, allow any value
            if (!isPreorder && (parseInt(this.value) < 1 || !this.value)) this.value = 1;
            updateTotalAndButton(productId, price, availableStock, isPreorder);
        });
    });

    // Initialize all variant-selects now in case some are preselected on page (not only in modals)
    // This ensures the displayPrice/displayStock and add-button are updated without needing to click +/−
    document.querySelectorAll('.variant-select').forEach(s => {
        // dispatch change to run the same logic as when user selects an option
        s.dispatchEvent(new Event('change'));
    });
