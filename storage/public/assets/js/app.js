const ToastThemes = {
  info: { name: 'info', className: 'toast--info', icon: 'I', ariaLive: 'polite' },
  success: { name: 'success', className: 'toast--success', icon: 'OK', ariaLive: 'polite' },
  warning: { name: 'warning', className: 'toast--warning', icon: '!', ariaLive: 'assertive' },
  danger: { name: 'danger', className: 'toast--danger', icon: '!', ariaLive: 'assertive' },
  sale: { name: 'sale', className: 'toast--sale', icon: 'SA', ariaLive: 'polite' },
  cancel: { name: 'cancel', className: 'toast--cancel', icon: 'AN', ariaLive: 'assertive' },
  store: { name: 'store', className: 'toast--store', icon: 'ST', ariaLive: 'polite' },
  reorder: { name: 'reorder', className: 'toast--reorder', icon: 'RS', ariaLive: 'polite' }
};

const ToastAliases = {
  alert: 'warning',
  avviso: 'warning',
  avvisi: 'warning',
  vendita: 'sale',
  vendite: 'sale',
  sale: 'sale',
  annullamento: 'cancel',
  annullamenti: 'cancel',
  cancel: 'cancel',
  acquisto: 'store',
  acquisti: 'store',
  store: 'store',
  'riordino sim': 'reorder',
  riordino: 'reorder',
  riordinosim: 'reorder',
  reorder: 'reorder',
  sim: 'reorder'
};

function resolveToastTheme(type) {
  const normalized = typeof type === 'string' ? type.trim().toLowerCase() : '';
  if (!normalized) {
    return ToastThemes.info;
  }
  const aliasKey = ToastAliases[normalized] || normalized;
  return ToastThemes[aliasKey] || ToastThemes.info;
}

function sanitizeToastOptions(raw) {
  if (raw == null) {
    return null;
  }
  if (typeof raw === 'string' || typeof raw === 'number') {
    const message = String(raw).trim();
    if (!message) {
      return null;
    }
    return {
      message,
      title: '',
      type: 'info',
      duration: 5000,
      dismissible: true,
      id: null,
      onDismiss: null
    };
  }
  if (typeof raw !== 'object') {
    return null;
  }
  const options = {};
  if (typeof raw.message === 'string') {
    options.message = raw.message.trim();
  } else if (typeof raw.text === 'string') {
    options.message = raw.text.trim();
  } else if (raw.message != null) {
    options.message = String(raw.message);
  } else {
    options.message = '';
  }

  options.title = typeof raw.title === 'string' ? raw.title.trim() : '';

  if (!options.message && !options.title) {
    return null;
  }

  if (typeof raw.type === 'string') {
    options.type = raw.type;
  } else if (typeof raw.variant === 'string') {
    options.type = raw.variant;
  } else {
    options.type = 'info';
  }

  let duration = raw.duration;
  if (duration === undefined && raw.timeout !== undefined) {
    duration = raw.timeout;
  }
  if (typeof duration === 'string' && duration.trim() !== '') {
    const parsedValue = parseInt(duration, 10);
    duration = Number.isFinite(parsedValue) ? parsedValue : undefined;
  }
  if (typeof duration === 'number' && Number.isFinite(duration)) {
    options.duration = duration;
  } else {
    options.duration = 5000;
  }
  options.duration = Math.max(0, options.duration);

  options.dismissible = raw.dismissible !== false;
  options.id = typeof raw.id === 'string' && raw.id.trim() !== '' ? raw.id : null;
  options.onDismiss = typeof raw.onDismiss === 'function' ? raw.onDismiss : null;

  return options;
}

function createToastStack() {
  const stack = document.createElement('div');
  stack.className = 'toast-stack';
  stack.setAttribute('data-toast-stack', 'true');
  stack.setAttribute('aria-live', 'polite');
  stack.setAttribute('aria-atomic', 'true');
  document.body.appendChild(stack);
  return stack;
}

class ToastManager {
  constructor(container) {
    this.container = container || createToastStack();
    this.toasts = new Map();
  }

  show(rawOptions) {
    const options = sanitizeToastOptions(rawOptions);
    if (!options) {
      return null;
    }
    const theme = resolveToastTheme(options.type);
    const toastId = options.id || this.generateId();
    if (this.toasts.has(toastId)) {
      this.dismiss(toastId);
    }
    const element = this.buildToastElement(toastId, options, theme);
    this.container.appendChild(element);
    const record = {
      id: toastId,
      element,
      options,
      theme,
      timeoutId: null,
      isClosing: false
    };
    this.toasts.set(toastId, record);
    requestAnimationFrame(() => element.classList.add('is-visible'));
    if (options.duration > 0) {
      const progressEl = element.querySelector('.toast__progress');
      if (progressEl) {
        progressEl.style.setProperty('--toast-duration', `${options.duration}ms`);
      }
      record.timeoutId = window.setTimeout(() => this.dismiss(toastId), options.duration);
    }
    return toastId;
  }

  dismiss(identifier) {
    const record = typeof identifier === 'string' ? this.toasts.get(identifier) : identifier;
    if (!record || record.isClosing) {
      return;
    }
    record.isClosing = true;
    if (record.timeoutId) {
      window.clearTimeout(record.timeoutId);
    }
    record.element.classList.remove('is-visible');
    record.element.classList.add('is-hiding');

    const finalize = () => {
      record.element.remove();
      this.toasts.delete(record.id);
      if (typeof record.options.onDismiss === 'function') {
        try {
          record.options.onDismiss(record);
        } catch (error) {
          console.error('Toast onDismiss error', error);
        }
      }
    };

    const handleTransitionEnd = event => {
      if (event.propertyName === 'opacity') {
        record.element.removeEventListener('transitionend', handleTransitionEnd);
        finalize();
      }
    };

    record.element.addEventListener('transitionend', handleTransitionEnd);
    window.setTimeout(() => {
      record.element.removeEventListener('transitionend', handleTransitionEnd);
      finalize();
    }, 320);
  }

  clear() {
    Array.from(this.toasts.keys()).forEach(id => this.dismiss(id));
  }

  buildToastElement(id, options, theme) {
    const toastEl = document.createElement('section');
    toastEl.className = `toast ${theme.className}`;
    toastEl.dataset.toastId = id;
    toastEl.dataset.toastType = theme.name;
    toastEl.setAttribute('role', 'status');
    toastEl.setAttribute('aria-live', theme.ariaLive || 'polite');

    const iconEl = document.createElement('span');
    iconEl.className = 'toast__icon';
    iconEl.setAttribute('aria-hidden', 'true');
    iconEl.textContent = theme.icon;
    toastEl.appendChild(iconEl);

    const contentEl = document.createElement('div');
    contentEl.className = 'toast__content';

    if (options.title) {
      const titleEl = document.createElement('span');
      titleEl.className = 'toast__title';
      titleEl.textContent = options.title;
      contentEl.appendChild(titleEl);
    }

    if (options.message) {
      const messageEl = document.createElement('p');
      messageEl.className = 'toast__message';
      messageEl.textContent = options.message;
      contentEl.appendChild(messageEl);
    }

    toastEl.appendChild(contentEl);

    if (options.dismissible) {
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'toast__close';
      closeBtn.setAttribute('aria-label', 'Chiudi notifica');
      closeBtn.textContent = 'X';
      closeBtn.addEventListener('click', () => this.dismiss(id));
      toastEl.appendChild(closeBtn);
    }

    if (options.duration > 0) {
      const progressEl = document.createElement('div');
      progressEl.className = 'toast__progress';
      toastEl.appendChild(progressEl);
    }

    return toastEl;
  }

  generateId() {
    return `toast-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const toastContainer = document.querySelector('[data-toast-stack]');
  const toastManager = new ToastManager(toastContainer);

  const showToast = (input, overrides) => {
    let baseOptions;
    if (typeof input === 'string' || typeof input === 'number') {
      baseOptions = { message: String(input) };
    } else if (input && typeof input === 'object') {
      baseOptions = { ...input };
    } else {
      baseOptions = {};
    }
    if (overrides && typeof overrides === 'object') {
      baseOptions = { ...baseOptions, ...overrides };
    }
    return toastManager.show(baseOptions);
  };

  const toastApi = {
    show(input, overrides) {
      return showToast(input, overrides);
    },
    info(message, options) {
      return showToast(message, Object.assign({ type: 'info' }, options || {}));
    },
    success(message, options) {
      return showToast(message, Object.assign({ type: 'success' }, options || {}));
    },
    warning(message, options) {
      return showToast(message, Object.assign({ type: 'warning' }, options || {}));
    },
    danger(message, options) {
      return showToast(message, Object.assign({ type: 'danger', duration: 0 }, options || {}));
    },
    sale(message, options) {
      return showToast(message, Object.assign({ type: 'sale', duration: 6000 }, options || {}));
    },
    cancel(message, options) {
      return showToast(message, Object.assign({ type: 'cancel', duration: 6000 }, options || {}));
    },
    store(message, options) {
      return showToast(message, Object.assign({ type: 'store', duration: 6000 }, options || {}));
    },
    reorder(message, options) {
      return showToast(message, Object.assign({ type: 'reorder', duration: 6000 }, options || {}));
    },
    clear() {
      toastManager.clear();
    }
  };

  window.toast = toastApi;
  window.toastManager = toastManager;

  const notify = {
    info: toastApi.info,
    success: toastApi.success,
    warning: toastApi.warning,
    danger: toastApi.danger,
    sale: toastApi.sale,
    cancel: toastApi.cancel,
    store: toastApi.store,
    reorder: toastApi.reorder
  };

  window.notify = notify;

  const seededToasts = Array.isArray(window.AppInitialToasts) ? window.AppInitialToasts : [];
  seededToasts.forEach(toast => toastManager.show(toast));
  delete window.AppInitialToasts;

  document.querySelectorAll('[data-toast-message]').forEach(node => {
    const rawMessage = node.getAttribute('data-toast-message');
    const rawTitle = node.getAttribute('data-toast-title');
    const rawType = node.getAttribute('data-toast-type');
    const durationAttr = node.getAttribute('data-toast-duration');
    const dismissibleAttr = node.getAttribute('data-toast-dismissible');

    const message = rawMessage ? rawMessage.trim() : '';
    const title = rawTitle ? rawTitle.trim() : '';
    const type = rawType ? rawType.trim() : '';

    if (!message && !title) {
      node.remove();
      return;
    }

    const toastOptions = {};
    if (message) {
      toastOptions.message = message;
    }
    if (title) {
      toastOptions.title = title;
    }
    if (type) {
      toastOptions.type = type;
    }
    if (durationAttr) {
      const parsedDuration = parseInt(durationAttr, 10);
      if (Number.isFinite(parsedDuration)) {
        toastOptions.duration = parsedDuration;
      }
    }
    if (dismissibleAttr !== null) {
      toastOptions.dismissible = dismissibleAttr !== 'false';
    }

    toastManager.show(toastOptions);
    node.remove();
  });

  document.addEventListener('app:toast', event => {
    if (!event || !event.detail) {
      return;
    }
    toastManager.show(event.detail);
  });

  const sidebar = document.querySelector('.sidebar');
  const toggle = document.querySelector('.sidebar__toggle');
  const collapseKey = 'sidebar-collapsed';

  if (sidebar && toggle) {
    const saved = localStorage.getItem(collapseKey);
    if (saved === 'true') {
      sidebar.dataset.collapsed = 'true';
      document.body.dataset.sidebarCollapsed = 'true';
    }

    toggle.addEventListener('click', () => {
      const collapsed = sidebar.dataset.collapsed === 'true';
      sidebar.dataset.collapsed = (!collapsed).toString();
      document.body.dataset.sidebarCollapsed = (!collapsed).toString();
      localStorage.setItem(collapseKey, (!collapsed).toString());
    });
  }

  const itemsTable = document.querySelector('#items-table tbody');
  const addItemBtn = document.querySelector('[data-action="add-item"]');
  const barcodeInput = document.querySelector('#barcode_input');
  const iccidIndex = buildIccidIndex();
  const offerSelect = document.querySelector('[data-offer-select]');
  const refundForm = document.querySelector('[data-refund-form]');
  const refundRowTemplate = document.querySelector('#refund-item-row-template');
  const discountCampaignSelect = document.querySelector('[data-discount-campaign]');
  const discountInput = document.querySelector('#discount');
  const discountCampaignNote = document.querySelector('[data-discount-campaign-note]');

  if (itemsTable) {
    if (addItemBtn) {
      addItemBtn.addEventListener('click', () => {
        addNewItemRow();
      });
    }

    itemsTable.addEventListener('click', event => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }
      if (target.matches('[data-action="remove-item"]')) {
        const row = target.closest('.item-row');
        if (!row) {
          return;
        }
        if (itemsTable.querySelectorAll('.item-row').length === 1) {
          resetRow(row);
          scheduleDiscountUpdate();
          return;
        }
        row.remove();
        scheduleDiscountUpdate();
      }
    });

    itemsTable.addEventListener('change', event => {
      const target = event.target;
      if (!(target instanceof HTMLSelectElement)) {
        return;
      }
      if (target.name !== 'item_iccid[]') {
        return;
      }

      const row = target.closest('.item-row');
      if (!row) {
        return;
      }

      const hidden = row.querySelector('input[name="item_iccid_code[]"]');
      const descInput = row.querySelector('input[name="item_description[]"]');

      if (!target.value) {
        if (hidden) {
          hidden.value = '';
        }
        return;
      }

      const selectedId = parseInt(target.value, 10);
      if (Number.isNaN(selectedId)) {
        if (hidden) {
          hidden.value = '';
        }
        return;
      }

      if (isIccidAlreadyUsed(selectedId, target)) {
        notify.warning('Questo ICCID è già associato a un altro articolo.', { duration: 6000 });
        target.value = '';
        if (hidden) {
          hidden.value = '';
        }
        return;
      }

      const info = iccidIndex.byId.get(selectedId);
      if (info && hidden) {
        hidden.value = info.code;
      }
      if (info && descInput && !descInput.value) {
        descInput.value = 'SIM ' + info.provider;
      }
    });

    itemsTable.addEventListener('input', event => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement)) {
        return;
      }
      if (target.name === 'item_price[]' || target.name === 'item_quantity[]') {
        scheduleDiscountUpdate();
      }
    });
  }

  if (barcodeInput && itemsTable) {
    barcodeInput.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        const code = barcodeInput.value.trim();
        handleBarcode(code);
        barcodeInput.value = '';
      }
    });
  }

  if (discountCampaignSelect && discountInput) {
    discountCampaignSelect.addEventListener('change', () => {
      if (!discountCampaignSelect.value) {
        if (discountCampaignNote) {
          discountCampaignNote.textContent = '';
        }
        discountInput.value = '0.00';
        return;
      }
      applySelectedDiscountCampaign();
    });
  }

  if (offerSelect && itemsTable) {
    const applySelectedOffer = () => {
      const option = offerSelect instanceof HTMLSelectElement ? offerSelect.selectedOptions[0] : null;
      if (!option || option.value === '') {
        return;
      }

      const fallbackTitle = option.textContent ? option.textContent.trim() : '';
      const title = option.dataset.title || fallbackTitle;
      const price = parseFloat(option.dataset.price || '0');
      const provider = option.dataset.provider || '';
      const description = option.dataset.description || '';

      let row = findFirstEmptyRow();
      if (!row) {
        row = addNewItemRow();
      }
      if (!row) {
        notify.danger('Impossibile creare una nuova riga articoli.');
        return;
      }

      const select = row.querySelector('select[name="item_iccid[]"]');
      const hidden = row.querySelector('input[name="item_iccid_code[]"]');
      const descInput = row.querySelector('input[name="item_description[]"]');
      const priceInput = row.querySelector('input[name="item_price[]"]');
      const qtyInput = row.querySelector('input[name="item_quantity[]"]');

      if (select) {
        select.value = '';
      }
      if (hidden) {
        hidden.value = '';
      }
      if (descInput) {
        const base = title;
        descInput.value = description ? base + ' - ' + description : base;
      }
      if (priceInput) {
        priceInput.value = Number.isFinite(price) ? price.toFixed(2) : '';
      }
      if (qtyInput) {
        qtyInput.value = '1';
      }

      if (provider) {
        row.dataset.offerProvider = provider;
      } else {
        delete row.dataset.offerProvider;
      }

      row.classList.add('highlight');
      setTimeout(() => row.classList.remove('highlight'), 1200);

      if (priceInput) {
        priceInput.focus();
      }

      offerSelect.value = '';
      scheduleDiscountUpdate();
    };

    offerSelect.addEventListener('change', applySelectedOffer);
  }

  if (refundForm && refundRowTemplate) {
    setupRefundForm(refundForm, refundRowTemplate);
  }

  setupLiveRefreshContainers();
  setupDraggableDashboard();
  setupAccordions();

  if (discountCampaignSelect && discountCampaignSelect.value) {
    scheduleDiscountUpdate();
  }

  function handleBarcode(code) {
    if (!code) {
      return;
    }
    const normalized = code.replace(/\s+/g, '');
    const info = iccidIndex.byCode.get(normalized);
    if (!info) {
      notify.danger('ICCID non trovato in magazzino: ' + normalized);
      return;
    }

    if (isIccidAlreadyUsed(info.id)) {
      notify.warning('Questo ICCID è già associato a un altro articolo.', { duration: 6000 });
      return;
    }

    let row = findFirstEmptyRow();
    if (!row) {
      row = addNewItemRow();
    }

    if (!row) {
      notify.danger('Impossibile creare una nuova riga articoli.');
      return;
    }

    const select = row.querySelector('select[name="item_iccid[]"]');
    const hidden = row.querySelector('input[name="item_iccid_code[]"]');
    const descInput = row.querySelector('input[name="item_description[]"]');
    const priceInput = row.querySelector('input[name="item_price[]"]');

    if (select) {
      select.value = info.id.toString();
    }
    if (hidden) {
      hidden.value = info.code;
    }
    if (descInput && !descInput.value) {
      descInput.value = 'SIM ' + info.provider;
    }

    if (priceInput) {
      priceInput.focus();
    }

    row.classList.add('highlight');
    setTimeout(() => row.classList.remove('highlight'), 1200);

    scheduleDiscountUpdate();
  }

  function setupRefundForm(form, template) {
    const saleIdInput = form.querySelector('[data-refund-sale-id]');
    const loadButton = form.querySelector('[data-action="load-sale"]');
    const feedbackBox = form.querySelector('[data-refund-feedback]');
    const panel = form.querySelector('[data-refund-items]');
    const tableBody = form.querySelector('[data-refund-items-body]');
    const saleLabel = form.querySelector('[data-refund-sale]');
    const statusBadge = form.querySelector('[data-refund-status]');
    const totalSpan = form.querySelector('[data-refund-total]');
    const refundedSpan = form.querySelector('[data-refund-refunded]');
    const creditedSpan = form.querySelector('[data-refund-credited]');
    const customerWrapper = form.querySelector('[data-refund-customer]');
    const customerName = form.querySelector('[data-refund-customer-name]');
    const submitButton = form.querySelector('button[type="submit"]');

    if (!saleIdInput || !loadButton || !feedbackBox || !panel || !tableBody || !saleLabel || !statusBadge || !totalSpan || !refundedSpan || !creditedSpan) {
      return;
    }

    let loading = false;

    loadButton.addEventListener('click', () => {
      triggerLoad();
    });

    saleIdInput.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        triggerLoad();
      }
    });

    form.addEventListener('input', event => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement)) {
        return;
      }
      if (target.matches('input[name="refund_item_quantity[]"]')) {
        const max = parseInt(target.getAttribute('max') || '0', 10);
        if (!Number.isNaN(max)) {
          if (parseInt(target.value || '0', 10) > max) {
            target.value = String(max);
          }
          if (parseInt(target.value || '0', 10) < 0) {
            target.value = '0';
          }
        }
      }
    });

    function triggerLoad() {
      if (loading) {
        return;
      }
      const saleId = parseInt(saleIdInput.value, 10);
      if (Number.isNaN(saleId) || saleId <= 0) {
        setFeedback('Inserisci un numero scontrino valido.', 'error');
        return;
      }

      loading = true;
      loadButton.disabled = true;
      setFeedback('Recupero dettagli in corso...', 'info');
      hidePanel();

      const params = new URLSearchParams({ action: 'load_sale_details', sale_id: String(saleId) });

      fetch('index.php?page=sales_create', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        credentials: 'same-origin',
        body: params.toString(),
      })
        .then(response => response.json())
        .then(data => {
          if (!data || data.success !== true) {
            const message = data && data.message ? data.message : 'Impossibile recuperare i dettagli dello scontrino.';
            setFeedback(message, 'error');
            return;
          }
          renderSale(data.sale);
        })
        .catch(() => {
          setFeedback('Errore di comunicazione con il server.', 'error');
        })
        .finally(() => {
          loading = false;
          loadButton.disabled = false;
        });
    }

    function renderSale(sale) {
      if (!sale || !sale.items) {
        setFeedback('Formato risposta non valido.', 'error');
        return;
      }

      tableBody.innerHTML = '';

      saleLabel.textContent = String(sale.id);
      setStatusBadge((sale.status || '').toString());
      totalSpan.textContent = formatCurrency(sale.total || 0);
      refundedSpan.textContent = formatCurrency(sale.refunded_amount || 0);
      creditedSpan.textContent = formatCurrency(sale.credited_amount || 0);

      if (customerWrapper && customerName) {
        const name = sale.customer_name ? String(sale.customer_name).trim() : '';
        if (name !== '') {
          customerWrapper.hidden = false;
          customerName.textContent = name;
        } else {
          customerWrapper.hidden = true;
          customerName.textContent = '';
        }
      }

      let refundableRows = 0;

      sale.items.forEach(item => {
        const rowFragment = template.content.cloneNode(true);
        const row = rowFragment.querySelector('.refund-item-row');
        if (!(row instanceof HTMLTableRowElement)) {
          return;
        }

        const idInput = row.querySelector('[data-field="id"]');
        const infoCell = row.querySelector('[data-field="info"]');
        const metaCell = row.querySelector('[data-field="meta"]');
        const qtyInput = row.querySelector('[data-field="quantity"]');
        const availableLabel = row.querySelector('[data-field="available"]');
        const typeSelect = row.querySelector('[data-field="type"]');
        const noteInput = row.querySelector('[data-field="note"]');

        if (idInput instanceof HTMLInputElement) {
          idInput.value = String(item.sale_item_id ?? 0);
        }

        const description = item.description && item.description !== ''
          ? String(item.description)
          : (item.iccid ? 'SIM ' + String(item.iccid) : 'Articolo #' + String(item.sale_item_id ?? ''));
        if (infoCell instanceof HTMLElement) {
          infoCell.textContent = description;
        }

        if (metaCell instanceof HTMLElement) {
          const parts = [];
          const price = typeof item.price === 'number' ? item.price : parseFloat(item.price || '0');
          if (!Number.isNaN(price)) {
            parts.push('Prezzo € ' + formatCurrency(price));
          }
          const quantity = Number.parseInt(item.quantity ?? '0', 10);
          const refunded = Number.parseInt(item.refunded_quantity ?? '0', 10);
          const available = Number.parseInt(item.available_quantity ?? '0', 10);
          parts.push('Vendute: ' + quantity);
          parts.push('Residue: ' + Math.max(available, 0));
          if (refunded > 0) {
            parts.push('Già reso: ' + refunded);
          }
          metaCell.textContent = parts.join(' · ');
        }

        const availableQty = Number.parseInt(item.available_quantity ?? '0', 10);
        if (qtyInput instanceof HTMLInputElement) {
          qtyInput.value = '0';
          qtyInput.min = '0';
          qtyInput.max = String(Math.max(availableQty, 0));
          if (availableQty <= 0) {
            qtyInput.disabled = true;
            row.classList.add('is-disabled');
          } else {
            refundableRows++;
          }
        }

        if (availableLabel instanceof HTMLElement) {
          if (availableQty > 0) {
            availableLabel.textContent = 'Disponibili ' + availableQty;
          } else {
            availableLabel.textContent = 'Tutto già reso';
          }
        }

        if (typeSelect instanceof HTMLSelectElement) {
          typeSelect.value = 'Refund';
          if (availableQty <= 0) {
            typeSelect.disabled = true;
          }
        }

        if (noteInput instanceof HTMLInputElement && availableQty <= 0) {
          noteInput.disabled = true;
        }

        tableBody.appendChild(rowFragment);
      });

      if (panel instanceof HTMLElement) {
        panel.hidden = false;
      }

      if (submitButton instanceof HTMLButtonElement) {
        if (refundableRows === 0) {
          submitButton.disabled = true;
        } else {
          submitButton.disabled = false;
        }
      }

      if (refundableRows > 0) {
        setFeedback('Dettagli caricati. Imposta le quantità da rimborsare e scegli se emettere rimborso o credito.', 'success');
      } else {
        setFeedback('Tutte le righe risultano già rimborsate per questo scontrino.', 'warning');
      }
    }

    function hidePanel() {
      tableBody.innerHTML = '';
      if (panel instanceof HTMLElement) {
        panel.hidden = true;
      }
      if (submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = false;
      }
    }

    function setFeedback(message, variant) {
      if (!(feedbackBox instanceof HTMLElement)) {
        return;
      }
      const baseClass = 'refund-feedback';
      feedbackBox.className = baseClass;
      if (!message) {
        feedbackBox.textContent = '';
        feedbackBox.hidden = true;
        return;
      }
      feedbackBox.hidden = false;
      feedbackBox.textContent = message;
      if (variant) {
        feedbackBox.classList.add(baseClass + '--' + variant);
      }
    }

    function setStatusBadge(status) {
      const badge = statusBadge;
      if (!(badge instanceof HTMLElement)) {
        return;
      }
      badge.textContent = status || 'N/D';
      badge.classList.remove('badge--success', 'badge--warning', 'badge--muted');
      let className = 'badge--muted';
      if (status === 'Completed') {
        className = 'badge--success';
      } else if (status === 'Refunded') {
        className = 'badge--warning';
      }
      badge.classList.add(className);
    }

    function formatCurrency(value) {
      const amount = typeof value === 'number' ? value : parseFloat(value || '0');
      if (Number.isNaN(amount)) {
        return '0,00';
      }
      return new Intl.NumberFormat('it-IT', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(amount);
    }
  }

  function setupLiveRefreshContainers() {
    const containers = document.querySelectorAll('[data-live-refresh]');
    if (containers.length === 0) {
      return;
    }
    containers.forEach(container => {
      if (!(container instanceof HTMLElement)) {
        return;
      }
      initLiveContainer(container);
    });
  }

  function initLiveContainer(container) {
    const resource = container.dataset.liveRefresh || '';
    const renderer = liveRenderers[resource];
    if (typeof renderer !== 'function') {
      return;
    }

    const refreshUrl = container.dataset.refreshUrl || '';
    if (!refreshUrl) {
      return;
    }

    let currentPage = parseInt(container.dataset.refreshPage || '1', 10);
    if (Number.isNaN(currentPage) || currentPage < 1) {
      currentPage = 1;
    }
    let perPage = parseInt(container.dataset.refreshPerPage || '7', 10);
    if (Number.isNaN(perPage) || perPage < 1) {
      perPage = 7;
    }

    const minInterval = 5000;
    let interval = parseInt(container.dataset.refreshInterval || '15000', 10);
    if (Number.isNaN(interval) || interval < minInterval) {
      interval = minInterval;
    }

    let timerId = 0;
    let abortController = null;

    const requestUpdate = (options = {}) => {
      const { page, keepFeedback = false } = options;

      if (abortController) {
        abortController.abort();
      }

      const nextPage = typeof page === 'number' && page >= 1 ? page : currentPage;
      const url = buildRefreshUrl(refreshUrl, nextPage, perPage);

      abortController = new AbortController();
      container.dataset.refreshPending = 'true';

      fetch(url, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        signal: abortController.signal,
      })
        .then(response => response.json().then(json => ({ ok: response.ok, json })))
        .then(({ ok, json }) => {
          if (!ok || !json || json.success !== true || !json.payload) {
            throw new Error(json && json.message ? json.message : 'Aggiornamento non riuscito');
          }

          const payload = json.payload;
          const pagination = payload.pagination || {};
          currentPage = Number.isInteger(pagination.page) ? pagination.page : currentPage;
          perPage = Number.isInteger(pagination.per_page) ? pagination.per_page : perPage;

          container.dataset.refreshPage = String(currentPage);
          container.dataset.refreshPerPage = String(perPage);

          renderer(container, payload);
          syncLiveForms(container, currentPage, perPage);
          updateLiveTimestamp(container, new Date(), false);

          if (!keepFeedback || typeof json.message === 'string') {
            writeLiveFeedback(container, json.message || null, true, json.error);
          }
        })
        .catch(error => {
          if (error && error.name === 'AbortError') {
            return;
          }
          updateLiveTimestamp(container, new Date(), true);
          writeLiveFeedback(container, error && error.message ? error.message : 'Aggiornamento non riuscito.', false);
        })
        .finally(() => {
          container.dataset.refreshPending = 'false';
        });
    };

    const startTimer = () => {
      if (interval <= 0) {
        return;
      }
      timerId = window.setInterval(() => {
        requestUpdate({ keepFeedback: true });
      }, interval);
    };

    const stopTimer = () => {
      if (timerId) {
        window.clearInterval(timerId);
        timerId = 0;
      }
    };

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        stopTimer();
      } else if (document.visibilityState === 'visible' && !timerId) {
        requestUpdate({ keepFeedback: true });
        startTimer();
      }
    });

    container.addEventListener('click', event => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }
      const link = target.closest('[data-live-slot="pagination"] a');
      if (!(link instanceof HTMLAnchorElement)) {
        return;
      }
      const href = link.getAttribute('href');
      if (!href || href === '#') {
        event.preventDefault();
        return;
      }
      event.preventDefault();
      if (link.classList.contains('is-disabled')) {
        return;
      }
      try {
        const url = new URL(href, window.location.href);
        const page = parseInt(url.searchParams.get('page_no') || '1', 10);
        if (!Number.isNaN(page)) {
          currentPage = page;
          container.dataset.refreshPage = String(currentPage);
          requestUpdate({ page: currentPage, keepFeedback: true });
        }
      } catch (_error) {
        requestUpdate({ keepFeedback: true });
      }
    });

    setupLiveForms(container, result => {
      if (result) {
        writeLiveFeedback(container, result.message, result.success, result.error);
      }
      requestUpdate({ keepFeedback: true });
    });

    requestUpdate({ keepFeedback: true });
    startTimer();
  }

  const liveRenderers = {
    sim_stock(container, payload) {
      const rows = Array.isArray(payload.rows) ? payload.rows : [];
      const pagination = payload.pagination || {};

      const tbody = container.querySelector('[data-live-slot="rows"]');
      if (tbody instanceof HTMLElement) {
        if (rows.length === 0) {
          tbody.innerHTML = '<tr><td colspan="5">Nessuna SIM presente.</td></tr>';
        } else {
          const rowHtml = rows.map(row => {
            const iccid = escapeHtml(row.iccid || '');
            const provider = escapeHtml(row.provider_name || '');
            const status = escapeHtml(row.status || '');
            const updated = escapeHtml(row.updated_at || '');
            const notes = escapeHtml(row.notes || '');
            return '<tr>' +
              '<td>' + iccid + '</td>' +
              '<td>' + provider + '</td>' +
              '<td>' + status + '</td>' +
              '<td>' + updated + '</td>' +
              '<td>' + notes + '</td>' +
            '</tr>';
          }).join('');
          tbody.innerHTML = rowHtml;
        }
      }

      const nav = container.querySelector('[data-live-slot="pagination"]');
      if (nav instanceof HTMLElement) {
        updateSimStockPagination(nav, pagination);
      }
    },
    iccid_list(container, payload) {
      const rows = Array.isArray(payload.rows) ? payload.rows : [];
      const pagination = payload.pagination || {};
      const tbody = container.querySelector('[data-live-slot="rows"]');
      if (tbody instanceof HTMLElement) {
        if (rows.length === 0) {
          tbody.innerHTML = '<tr><td colspan="5">Nessun record disponibile.</td></tr>';
        } else {
          const html = rows.map(row => {
            return '<tr>' +
              '<td>' + escapeHtml(row.iccid || '') + '</td>' +
              '<td>' + escapeHtml(row.provider_name || '') + '</td>' +
              '<td>' + escapeHtml(row.status || '') + '</td>' +
              '<td>' + escapeHtml(row.updated_at || '') + '</td>' +
              '<td>' + escapeHtml(row.notes || '') + '</td>' +
            '</tr>';
          }).join('');
          tbody.innerHTML = html;
        }
      }

      const nav = container.querySelector('[data-live-slot="pagination"]');
      if (nav instanceof HTMLElement) {
        updateIccidPagination(nav, pagination);
      }
    },
    sales_list(container, payload) {
      const rows = Array.isArray(payload.rows) ? payload.rows : [];
      const pagination = payload.pagination || {};
      const filters = payload.filters || {};

      const tbody = container.querySelector('[data-live-slot="rows"]');
      if (tbody instanceof HTMLElement) {
        if (rows.length === 0) {
          tbody.innerHTML = '<tr><td colspan="8">Nessuna vendita trovata.</td></tr>';
        } else {
          tbody.innerHTML = rows.map(row => renderSalesRow(row)).join('');
        }
      }

      const nav = container.querySelector('[data-live-slot="pagination"]');
      if (nav instanceof HTMLElement) {
        updateSalesPagination(nav, pagination, filters);
      }

      updateSalesFilters(container, filters);
    },
  };

  function updateSimStockPagination(nav, pagination) {
    const totalPages = Number.isInteger(pagination.pages) ? pagination.pages : 1;
    const current = Number.isInteger(pagination.page) ? pagination.page : 1;
    const total = Number.isInteger(pagination.total) ? pagination.total : 0;

    if (totalPages <= 1) {
      nav.hidden = true;
      nav.innerHTML = '';
      return;
    }

    nav.hidden = false;
    const prevDisabled = current <= 1;
    const nextDisabled = current >= totalPages;

    const link = (page, label, disabled) => {
      const cls = 'pagination__link' + (disabled ? ' is-disabled' : '');
      const href = disabled ? '#' : 'index.php?page=sim_stock&page_no=' + page;
      return '<a class="' + cls + '" href="' + href + '">' + label + '</a>';
    };

    nav.innerHTML = [
      link(1, '&laquo;', prevDisabled),
      link(Math.max(current - 1, 1), '&lsaquo;', prevDisabled),
      '<span class="pagination__info">Pagina ' + current + ' di ' + totalPages + ' (' + total + ' risultati)</span>',
      link(Math.min(current + 1, totalPages), '&rsaquo;', nextDisabled),
      link(totalPages, '&raquo;', nextDisabled),
    ].join('');
  }

  function updateIccidPagination(nav, pagination) {
    const totalPages = Number.isInteger(pagination.pages) ? pagination.pages : 1;
    const current = Number.isInteger(pagination.page) ? pagination.page : 1;
    const total = Number.isInteger(pagination.total) ? pagination.total : 0;

    if (totalPages <= 1) {
      nav.hidden = true;
      nav.innerHTML = '';
      return;
    }

    const perPage = Number.isInteger(pagination.per_page) ? pagination.per_page : 7;
    nav.hidden = false;

    const buildHref = page => 'index.php?page=iccid_list&page_no=' + page + '&per_page=' + perPage;
    const prevDisabled = current <= 1;
    const nextDisabled = current >= totalPages;

    nav.innerHTML = [
      createPaginationLink(buildHref(1), '&laquo;', prevDisabled),
      createPaginationLink(buildHref(Math.max(current - 1, 1)), '&lsaquo;', prevDisabled),
      '<span class="pagination__info">Pagina ' + current + ' di ' + totalPages + ' (' + total + ' risultati)</span>',
      createPaginationLink(buildHref(Math.min(current + 1, totalPages)), '&rsaquo;', nextDisabled),
      createPaginationLink(buildHref(totalPages), '&raquo;', nextDisabled),
    ].join('');
  }

  function updateSalesPagination(nav, pagination, filters) {
    const totalPages = Number.isInteger(pagination.pages) ? pagination.pages : 1;
    const current = Number.isInteger(pagination.page) ? pagination.page : 1;
    const total = Number.isInteger(pagination.total) ? pagination.total : 0;
    const perPage = Number.isInteger(pagination.per_page) ? pagination.per_page : 7;

    if (totalPages <= 1) {
      nav.hidden = true;
      nav.innerHTML = '';
      return;
    }

    nav.hidden = false;

    const buildHref = page => {
      const params = new URLSearchParams();
      params.set('page', 'sales_list');
      params.set('page_no', String(page));
      params.set('per_page', String(perPage));
      Object.entries(filters || {}).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
          params.set(key, String(value));
        }
      });
      return 'index.php?' + params.toString();
    };

    const prevDisabled = current <= 1;
    const nextDisabled = current >= totalPages;

    nav.innerHTML = [
      createPaginationLink(buildHref(1), '&laquo;', prevDisabled),
      createPaginationLink(buildHref(Math.max(current - 1, 1)), '&lsaquo;', prevDisabled),
      '<span class="pagination__info">Pagina ' + current + ' di ' + totalPages + ' (' + total + ' risultati)</span>',
      createPaginationLink(buildHref(Math.min(current + 1, totalPages)), '&rsaquo;', nextDisabled),
      createPaginationLink(buildHref(totalPages), '&raquo;', nextDisabled),
    ].join('');
  }

  function createPaginationLink(href, label, disabled) {
    if (disabled) {
      return '<a class="pagination__link is-disabled" href="#">' + label + '</a>';
    }
    return '<a class="pagination__link" href="' + href + '">' + label + '</a>';
  }

  function renderSalesRow(row) {
    const id = parseInt(row.id, 10) || 0;
    const createdAt = escapeHtml(row.created_at_display || '');
    const customer = escapeHtml(row.customer_display || '');
    const operator = escapeHtml(row.operator_display || '');
    const payment = escapeHtml(row.payment_method || '');
    const total = escapeHtml(row.total_display || '');
    const statusClass = escapeHtml(row.status_class || 'badge--muted');
    const statusLabel = escapeHtml(row.status_label || '');
    const printUrl = escapeHtml(row.print_url || ('print_receipt.php?sale_id=' + id));

    return '' +
      '<tr>' +
        '<td>#' + id + '</td>' +
        '<td>' + createdAt + '</td>' +
        '<td>' + customer + '</td>' +
        '<td>' + operator + '</td>' +
        '<td>' + payment + '</td>' +
        '<td>' + total + '</td>' +
        '<td><span class="badge ' + statusClass + '">' + statusLabel + '</span></td>' +
        '<td class="table-actions-inline"><a class="btn btn--secondary" href="' + printUrl + '" target="_blank" rel="noopener">Stampa</a></td>' +
      '</tr>';
  }

  function updateSalesFilters(container, filters) {
    const form = container.querySelector('form[data-live-form]');
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    const entries = {
      q: '',
      status: '',
      payment: '',
      from: '',
      to: '',
    };

    Object.keys(entries).forEach(key => {
      const value = filters && filters[key] !== undefined ? filters[key] : '';
      const field = form.querySelector('[name="' + key + '"]');
      if (!field) {
        return;
      }
      if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement) {
        field.value = value == null ? '' : String(value);
      }
    });
  }

  function setupLiveForms(container, callback) {
    const forms = container.querySelectorAll('form[data-live-form]');
    forms.forEach(form => {
      if (!(form instanceof HTMLFormElement) || form.dataset.liveBound === 'true') {
        return;
      }
      form.dataset.liveBound = 'true';

      form.addEventListener('submit', event => {
        event.preventDefault();

        const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
        const restore = prepareSubmitButton(submitButton);

        const formData = new FormData(form);
        const page = container.dataset.refreshPage;
        const perPage = container.dataset.refreshPerPage;
        if (page) {
          formData.set('page_no', page);
        }
        if (perPage) {
          formData.set('per_page', perPage);
        }

        const method = (form.getAttribute('method') || 'POST').toUpperCase();
        let requestUrl = form.getAttribute('action') || window.location.href;
        const requestInit = {
          method,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'same-origin',
        };

        if (method === 'GET') {
          const url = new URL(requestUrl, window.location.href);
          const seenKeys = new Set();
          formData.forEach((value, key) => {
            if (!seenKeys.has(key)) {
              url.searchParams.delete(key);
              seenKeys.add(key);
            }
          });
          formData.forEach((value, key) => {
            url.searchParams.append(key, value == null ? '' : String(value));
          });
          if (!url.searchParams.has('page')) {
            url.searchParams.set('page', container.dataset.liveRefresh || 'dashboard');
          }
          url.searchParams.set('action', 'refresh');
          requestUrl = url.toString();
        } else {
          requestInit.body = formData;
        }

        fetch(requestUrl, requestInit)
          .then(response => response.json().then(json => ({ ok: response.ok, json })))
          .then(({ ok, json }) => {
            const success = !!(ok && json && json.success !== false);
            const message = json && typeof json.message === 'string'
              ? json.message
              : (success ? 'Operazione completata.' : 'Operazione non riuscita.');
            const errorDetail = json && typeof json.error === 'string' ? json.error : null;
            const finalMessage = method === 'GET' && success && (!json || typeof json.message !== 'string')
              ? null
              : message;
            if (typeof callback === 'function') {
              callback({ success, message: finalMessage, error: errorDetail });
            }
          })
          .catch(() => {
            if (typeof callback === 'function') {
              callback({ success: false, message: 'Errore di comunicazione con il server.' });
            }
          })
          .finally(() => {
            restore();
          });
      });
    });
  }

  function prepareSubmitButton(node) {
    if (!(node instanceof HTMLButtonElement || node instanceof HTMLInputElement)) {
      return () => {};
    }

    const element = node;
    const originalDisabled = element.disabled;
    let originalLabel = '';

    if (element instanceof HTMLButtonElement) {
      originalLabel = element.textContent || '';
      element.textContent = 'Salvataggio...';
    } else {
      originalLabel = element.value || '';
      element.value = 'Salvataggio...';
    }

    element.disabled = true;

    return () => {
      element.disabled = originalDisabled;
      if (element instanceof HTMLButtonElement) {
        element.textContent = originalLabel;
      } else {
        element.value = originalLabel;
      }
    };
  }

  function buildRefreshUrl(baseUrl, page, perPage) {
    try {
      const url = new URL(baseUrl, window.location.href);
      url.searchParams.set('page_no', String(page));
      url.searchParams.set('per_page', String(perPage));
      return url.toString();
    } catch (_error) {
      const delimiter = baseUrl.includes('?') ? '&' : '?';
      return baseUrl + delimiter + 'page_no=' + page + '&per_page=' + perPage;
    }
  }

  function syncLiveForms(container, page, perPage) {
    const forms = container.querySelectorAll('form[data-live-form]');
    forms.forEach(form => {
      if (!(form instanceof HTMLFormElement)) {
        return;
      }
      const pageInput = form.querySelector('input[name="page_no"]');
      if (pageInput instanceof HTMLInputElement) {
        pageInput.value = String(page);
      }
      const perPageInput = form.querySelector('input[name="per_page"]');
      if (perPageInput instanceof HTMLInputElement) {
        perPageInput.value = String(perPage);
      }
    });
  }

  function updateLiveTimestamp(container, date, isError) {
    const timestamp = container.querySelector('[data-live-slot="timestamp"]');
    if (!(timestamp instanceof HTMLElement)) {
      return;
    }
    if (isError) {
      timestamp.textContent = 'errore';
      timestamp.dataset.state = 'error';
      return;
    }
    timestamp.dataset.state = 'ok';
    timestamp.textContent = date.toLocaleTimeString('it-IT', {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });
  }

  function writeLiveFeedback(container, message, success = true, errorDetail = null) {
    const slot = container.querySelector('[data-live-slot="feedback"]');
    if (!(slot instanceof HTMLElement)) {
      return;
    }

    if (!message) {
      slot.innerHTML = '';
      return;
    }

    const classes = ['alert', success ? 'alert--success' : 'alert--error'];
    let html = '<div class="' + classes.join(' ') + '"><p>' + escapeHtml(message) + '</p>';
    if (!success && errorDetail) {
      html += '<p class="muted">Dettaglio: ' + escapeHtml(errorDetail) + '</p>';
    }
    html += '</div>';
    slot.innerHTML = html;
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
  }

  function findFirstEmptyRow() {
    if (!itemsTable) {
      return null;
    }
    const rows = itemsTable.querySelectorAll('.item-row');
    for (const row of rows) {
      const select = row.querySelector('select[name="item_iccid[]"]');
      if (select && !select.value) {
        return row;
      }
    }
    return null;
  }

  function buildIccidIndex() {
    const byCode = new Map();
    const byId = new Map();
    const options = document.querySelectorAll('#iccids_list option');
    options.forEach(option => {
      const code = option.value.trim();
      const id = option.dataset.id;
      const provider = option.dataset.provider || '';
      if (code && id) {
        const parsedId = parseInt(id, 10);
        if (!Number.isNaN(parsedId)) {
          const info = { id: parsedId, provider, code };
          byCode.set(code, info);
          byId.set(parsedId, info);
        }
      }
    });
    return { byCode, byId };
  }

  function calculateSaleSubtotal() {
    if (!itemsTable) {
      return 0;
    }
    let subtotal = 0;
    itemsTable.querySelectorAll('.item-row').forEach(row => {
      const priceInput = row.querySelector('input[name="item_price[]"]');
      const quantityInput = row.querySelector('input[name="item_quantity[]"]');
      if (!(priceInput instanceof HTMLInputElement) || !(quantityInput instanceof HTMLInputElement)) {
        return;
      }
      const price = parseFloat(priceInput.value || '0');
      const quantity = parseFloat(quantityInput.value || '0');
      if (!Number.isFinite(price) || !Number.isFinite(quantity)) {
        return;
      }
      if (price <= 0 || quantity <= 0) {
        return;
      }
      subtotal += price * quantity;
    });
    return subtotal;
  }

  function applySelectedDiscountCampaign() {
    if (!discountCampaignSelect || !discountInput || !discountCampaignSelect.value) {
      return;
    }
    const option = discountCampaignSelect.selectedOptions[0];
    if (!(option instanceof HTMLOptionElement)) {
      return;
    }

    const subtotal = calculateSaleSubtotal();
    const rawType = option.dataset.type || 'fixed';
    const type = rawType === 'percent' ? 'percent' : 'fixed';
    const parsedValue = parseFloat(option.dataset.value || '0');

    if (!Number.isFinite(parsedValue) || parsedValue <= 0) {
      if (discountCampaignNote) {
        discountCampaignNote.textContent = '';
      }
      return;
    }

    if (subtotal <= 0) {
      discountInput.value = '0.00';
      if (discountCampaignNote) {
        discountCampaignNote.textContent = 'Lo sconto sarà calcolato appena inserisci gli articoli.';
      }
      return;
    }

    let discountValue = type === 'percent' ? subtotal * (parsedValue / 100) : parsedValue;
    if (discountValue > subtotal) {
      discountValue = subtotal;
    }
    if (!Number.isFinite(discountValue)) {
      return;
    }

    discountInput.value = discountValue.toFixed(2);

    if (discountCampaignNote) {
      const currencyFormatter = new Intl.NumberFormat('it-IT', {
        style: 'currency',
        currency: 'EUR',
      });
      if (type === 'percent') {
        const percentFormatter = new Intl.NumberFormat('it-IT', {
          minimumFractionDigits: 0,
          maximumFractionDigits: 2,
        });
        discountCampaignNote.textContent = 'Applicato ' + percentFormatter.format(parsedValue) + '% su ' + currencyFormatter.format(subtotal);
      } else {
        discountCampaignNote.textContent = 'Applicato sconto di ' + currencyFormatter.format(discountValue);
      }
    }
  }

  function scheduleDiscountUpdate() {
    if (!discountCampaignSelect || !discountInput) {
      return;
    }
    if (!discountCampaignSelect.value) {
      if (discountCampaignNote) {
        discountCampaignNote.textContent = '';
      }
      return;
    }
    window.requestAnimationFrame(() => {
      applySelectedDiscountCampaign();
    });
  }

  function isIccidAlreadyUsed(id, currentSelect) {
    if (!itemsTable) {
      return false;
    }
    const rows = itemsTable.querySelectorAll('select[name="item_iccid[]"]');
    for (const select of rows) {
      if (select === currentSelect) {
        continue;
      }
      if (parseInt(select.value, 10) === id) {
        return true;
      }
    }
    return false;
  }

  function addNewItemRow() {
    if (!itemsTable) {
      return null;
    }
    const firstRow = itemsTable.querySelector('.item-row');
    if (!firstRow) {
      return null;
    }
    const clone = firstRow.cloneNode(true);
    resetRow(clone);
    itemsTable.appendChild(clone);
    return clone;
  }

  function resetRow(row) {
    row.querySelectorAll('input').forEach(input => {
      if (input.name.includes('quantity')) {
        input.value = '1';
        return;
      }
      input.value = '';
    });
    const select = row.querySelector('select');
    if (select) {
      select.value = '';
    }
    row.classList.remove('highlight');
    if ('offerProvider' in row.dataset) {
      delete row.dataset.offerProvider;
    }
    scheduleDiscountUpdate();
  }

  function setupDraggableDashboard() {
    const containers = Array.from(document.querySelectorAll('[data-draggable-container]'));
    if (containers.length === 0) {
      return;
    }

    const storageKey = 'dashboard-layout';
    let layout = {};
    try {
      const raw = localStorage.getItem(storageKey);
      if (raw) {
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object') {
          layout = parsed;
        }
      }
    } catch (_error) {
      layout = {};
    }

    containers.forEach(container => {
      const containerId = container.getAttribute('data-draggable-container');
      if (!containerId) {
        return;
      }

      const cards = Array.from(container.querySelectorAll('[data-draggable-card]'));
      cards.forEach(card => {
        card.setAttribute('draggable', 'true');
      });

      const storedOrder = Array.isArray(layout[containerId]) ? layout[containerId] : null;
      if (storedOrder && storedOrder.length) {
        const registry = new Map();
        cards.forEach(card => {
          registry.set(card.getAttribute('data-draggable-card') || '', card);
        });
        storedOrder.forEach(cardId => {
          const element = registry.get(cardId);
          if (element) {
            container.appendChild(element);
            registry.delete(cardId);
          }
        });
        registry.forEach(element => {
          container.appendChild(element);
        });
      }

      container.addEventListener('dragover', event => {
        event.preventDefault();
        const dragging = document.querySelector('[data-draggable-card].is-dragging');
        if (!dragging) {
          return;
        }
        const afterElement = getDragAfterElement(container, event.clientY);
        if (!afterElement) {
          container.appendChild(dragging);
        } else if (afterElement !== dragging) {
          container.insertBefore(dragging, afterElement);
        }
      });

      container.addEventListener('dragenter', () => {
        container.classList.add('is-drag-over');
      });

      container.addEventListener('dragleave', event => {
        const related = event.relatedTarget;
        if (!(related instanceof Node) || !container.contains(related)) {
          container.classList.remove('is-drag-over');
        }
      });

      container.addEventListener('drop', () => {
        container.classList.remove('is-drag-over');
        persistLayout();
      });

      cards.forEach(card => {
        card.addEventListener('dragstart', event => {
          const transfer = event.dataTransfer;
          if (transfer) {
            transfer.effectAllowed = 'move';
            transfer.setData('text/plain', card.getAttribute('data-draggable-card') || '');
          }
          card.classList.add('is-dragging');
        });

        card.addEventListener('dragend', () => {
          card.classList.remove('is-dragging');
          containers.forEach(item => item.classList.remove('is-drag-over'));
          persistLayout();
        });
      });
    });

    function persistLayout() {
      const snapshot = {};
      containers.forEach(container => {
        const containerId = container.getAttribute('data-draggable-container');
        if (!containerId) {
          return;
        }
        snapshot[containerId] = Array.from(container.querySelectorAll('[data-draggable-card]')).map(card => card.getAttribute('data-draggable-card') || '');
      });
      try {
        localStorage.setItem(storageKey, JSON.stringify(snapshot));
      } catch (error) {
        console.warn('Impossibile salvare il layout della dashboard.', error);
      }
    }

    function getDragAfterElement(container, y) {
      const siblings = [...container.querySelectorAll('[data-draggable-card]:not(.is-dragging)')];
      return siblings.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
          return { offset, element: child };
        }
        return closest;
      }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
    }
  }

  function setupAccordions() {
    const accordions = document.querySelectorAll('[data-accordion]');
    accordions.forEach(item => {
      const toggle = item.querySelector('[data-accordion-toggle]');
      const content = item.querySelector('[data-accordion-content]');
      if (!toggle || !content) {
        return;
      }

      const initialOpen = item.dataset.open !== 'false';
      updateState(initialOpen);

      toggle.addEventListener('click', () => {
        const nextState = item.dataset.open === 'true' ? false : true;
        updateState(nextState);
      });

      function updateState(state) {
        item.dataset.open = state ? 'true' : 'false';
        toggle.setAttribute('aria-expanded', state ? 'true' : 'false');
        if (state) {
          content.removeAttribute('hidden');
        } else {
          content.setAttribute('hidden', 'hidden');
        }
      }
    });
  }
});
