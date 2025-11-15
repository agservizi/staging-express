'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const shell = document.querySelector('.portal-shell');
  const toggle = document.querySelector('.portal-sidebar-toggle');
  const toggleLabel = toggle?.querySelector('.portal-sidebar-toggle__label');
  const storageKey = 'portal-sidebar-collapsed';

  const applySidebarState = (collapsed) => {
    if (!shell) {
      return;
    }
    shell.classList.toggle('portal-shell--collapsed', collapsed);
    if (toggle) {
      const labelText = collapsed ? 'Mostra menu' : 'Nascondi menu';
      toggle.setAttribute('aria-expanded', String(!collapsed));
      toggle.setAttribute('aria-label', labelText);
      toggle.setAttribute('title', labelText);
      if (toggleLabel) {
        toggleLabel.textContent = labelText;
      }
    }
  };

  if (shell && toggle) {
    let initialCollapsed = false;
    try {
      initialCollapsed = window.localStorage.getItem(storageKey) === '1';
    } catch (error) {
      initialCollapsed = false;
    }
    applySidebarState(initialCollapsed);

    toggle.addEventListener('click', () => {
      const nextState = !shell.classList.contains('portal-shell--collapsed');
      applySidebarState(nextState);
      try {
        window.localStorage.setItem(storageKey, nextState ? '1' : '0');
      } catch (error) {
        /* ignore quota errors */
      }
    });
  }

  const paymentFormAction = document.querySelector('form.portal-form input[name="action"][value="create_payment"]');
  if (paymentFormAction) {
    const paymentForm = paymentFormAction.closest('form');
    if (paymentForm) {
      const saleSelect = paymentForm.querySelector('select[name="sale_id"]');
      const amountInput = paymentForm.querySelector('input[name="amount"]');

      if (saleSelect && amountInput) {
        saleSelect.addEventListener('change', () => {
          const selectedOption = saleSelect.options[saleSelect.selectedIndex];
          const balance = selectedOption?.getAttribute('data-balance');
          if (balance !== null && balance !== undefined && balance !== '') {
            amountInput.value = balance;
          }
        });
      }
    }
  }

  const productFormAction = document.querySelector('form.portal-form input[name="action"][value="create_product_request"]');
  if (productFormAction) {
    const productForm = productFormAction.closest('form');
    if (productForm) {
      const typeSelect = productForm.querySelector('#product-request-type');
      const depositField = productForm.querySelector('[data-field="deposit"]');
      const depositInput = depositField?.querySelector('input[name="deposit_amount"]');
      const installmentsField = productForm.querySelector('[data-field="installments"]');
      const installmentsInput = installmentsField?.querySelector('input[name="installments"]');
      const productSelect = productForm.querySelector('select[name="product_id"]');

      const updateVisibility = () => {
        const type = typeSelect?.value || 'Purchase';
        if (depositField) {
          if (type === 'Deposit' || type === 'Installment') {
            depositField.style.display = '';
          } else {
            depositField.style.display = 'none';
            if (depositInput) {
              depositInput.value = '';
            }
          }
        }
        if (installmentsField) {
          if (type === 'Installment') {
            installmentsField.style.display = '';
            if (installmentsInput && !installmentsInput.value) {
              installmentsInput.value = '6';
            }
          } else {
            installmentsField.style.display = 'none';
            if (installmentsInput) {
              installmentsInput.value = '';
            }
          }
        }
      };

      const updateDepositPlaceholder = () => {
        if (!productSelect || !depositInput) {
          return;
        }
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        const priceAttr = selectedOption?.getAttribute('data-price');
        if (priceAttr && priceAttr !== '') {
          depositInput.placeholder = parseFloat(priceAttr).toFixed(2);
        } else {
          depositInput.placeholder = '0.00';
        }
      };

      typeSelect?.addEventListener('change', updateVisibility);
      productSelect?.addEventListener('change', () => {
        updateDepositPlaceholder();
        if (!depositInput || !typeSelect) {
          return;
        }
        if (typeSelect.value === 'Deposit' && depositInput.value === '') {
          const selectedOption = productSelect.options[productSelect.selectedIndex];
          const priceAttr = selectedOption?.getAttribute('data-price');
          const priceValue = priceAttr ? parseFloat(priceAttr) : 0;
          if (!Number.isNaN(priceValue) && priceValue > 0) {
            depositInput.value = priceValue.toFixed(2);
          }
        }
      });

      updateVisibility();
      updateDepositPlaceholder();
    }
  }
});
