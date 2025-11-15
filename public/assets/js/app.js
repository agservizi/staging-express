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
  document
    .querySelectorAll('[data-qr-image]')
    .forEach(img => {
      const fallback = img.getAttribute('data-qr-fallback');
      if (!fallback) {
        return;
      }
      const markError = () => {
        img.classList.add('is-qr-error');
        const host = img.closest('[data-qr-container]') || img.parentElement;
        if (host && !host.querySelector('[data-qr-error-message]')) {
          const message = document.createElement('p');
          message.dataset.qrErrorMessage = 'true';
          message.className = 'muted';
          message.textContent = 'Impossibile caricare il codice QR. Usa il codice segreto riportato oppure apri il link otpauth.';
          host.appendChild(message);
        }
      };
      img.addEventListener('error', () => {
        if (img.dataset.qrSwapped === '1') {
          markError();
          return;
        }
        img.dataset.qrSwapped = '1';
        img.src = fallback;
      });
      img.addEventListener('load', () => {
        img.classList.remove('is-qr-error');
      });
    });

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

  const notificationRoot = document.querySelector('[data-notification]');
  const notificationDisplayLimit = 20;
  const notificationStorageLimit = notificationDisplayLimit * 2;
  const notificationChannelLabels = {
    sales: 'Vendite',
    stock: 'Scorte SIM',
    product_stock: 'Magazzino prodotti',
    system: 'Sistema',
  };

  if (notificationRoot) {
    const toggleBtn = notificationRoot.querySelector('[data-notification-toggle]');
    const panel = notificationRoot.querySelector('[data-notification-panel]');
    const badge = notificationRoot.querySelector('[data-notification-badge]');
    const counter = notificationRoot.querySelector('[data-notification-counter]');
    const markForm = notificationRoot.querySelector('[data-notification-mark]');
    const listNode = notificationRoot.querySelector('[data-notification-list]');
    let isOpen = false;
    let notificationSource = null;

    const normalizeNotification = raw => {
      if (!raw) {
        return null;
      }
      const id = Number.parseInt(raw.id, 10);
      if (!Number.isFinite(id) || id <= 0) {
        return null;
      }
      const link = typeof raw.link === 'string' ? raw.link.trim() : '';
      return {
        id,
        type: typeof raw.type === 'string' ? raw.type : 'system',
        title: typeof raw.title === 'string' ? raw.title : '',
        body: typeof raw.body === 'string' ? raw.body : '',
        level: typeof raw.level === 'string' ? raw.level : 'info',
        channel: typeof raw.channel === 'string' ? raw.channel : '',
        source: typeof raw.source === 'string' ? raw.source : 'system',
        link: link !== '' ? link : null,
        meta: typeof raw.meta === 'object' && raw.meta !== null ? raw.meta : {},
        is_read: Boolean(raw.is_read),
        created_at: typeof raw.created_at === 'string' ? raw.created_at : '',
      };
    };

    const initialPayload = typeof window.AppNotifications === 'object' && window.AppNotifications !== null
      ? window.AppNotifications
      : { items: [], unread_count: 0 };

    let notificationsState = {
      items: Array.isArray(initialPayload.items)
        ? initialPayload.items.map(item => normalizeNotification(item)).filter(item => item !== null)
        : [],
      unread_count: Number.parseInt(initialPayload.unread_count, 10) || 0,
    };

    let notificationLastId = notificationsState.items.reduce((max, item) => Math.max(max, item.id), 0);

    delete window.AppNotifications;

    const attentionClass = 'topbar__notification-toggle--ping';
    let attentionTimeoutId = 0;

    const clearBellAttention = () => {
      if (!toggleBtn) {
        return;
      }
      toggleBtn.classList.remove(attentionClass);
      if (attentionTimeoutId) {
        window.clearTimeout(attentionTimeoutId);
        attentionTimeoutId = 0;
      }
    };

    const triggerBellAttention = () => {
      if (!toggleBtn || isOpen) {
        return;
      }
      toggleBtn.classList.add(attentionClass);
      if (attentionTimeoutId) {
        window.clearTimeout(attentionTimeoutId);
      }
      attentionTimeoutId = window.setTimeout(clearBellAttention, 1400);
    };

    const setOpen = open => {
      if (!panel || !toggleBtn) {
        return;
      }
      isOpen = open;
      panel.setAttribute('data-open', open ? 'true' : 'false');
      toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open && typeof panel.focus === 'function') {
        panel.focus();
      }
      if (open) {
        clearBellAttention();
      }
    };

    if (toggleBtn && panel) {
      toggleBtn.addEventListener('click', () => {
        setOpen(!isOpen);
      });

      document.addEventListener('click', event => {
        if (!notificationRoot.contains(event.target) && isOpen) {
          setOpen(false);
        }
      });

      document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && isOpen) {
          setOpen(false);
        }
      });
    }

    const updateUnreadUi = count => {
      const safeCount = Math.max(0, Number.parseInt(count, 10) || 0);
      if (badge) {
        if (safeCount > 0) {
          badge.textContent = Math.min(safeCount, 99).toString();
          badge.hidden = false;
        } else {
          badge.textContent = '';
          badge.hidden = true;
        }
      }
      if (counter) {
        counter.textContent = `${safeCount} non lett${safeCount === 1 ? 'a' : 'e'}`;
      }
    };

    const formatNotificationTime = timestamp => {
      if (!timestamp) {
        return '';
      }
      const date = new Date(timestamp);
      if (Number.isNaN(date.getTime())) {
        return '';
      }
      const diffSeconds = Math.floor((Date.now() - date.getTime()) / 1000);
      if (diffSeconds < 60) {
        return 'Pochi secondi fa';
      }
      if (diffSeconds < 3600) {
        const minutes = Math.floor(diffSeconds / 60);
        return `${minutes} min fa`;
      }
      if (diffSeconds < 86400) {
        const hours = Math.floor(diffSeconds / 3600);
        return `${hours} h fa`;
      }
      const day = String(date.getDate()).padStart(2, '0');
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const hours = String(date.getHours()).padStart(2, '0');
      const minutes = String(date.getMinutes()).padStart(2, '0');
      return `${day}/${month} ${hours}:${minutes}`;
    };

    const buildNotificationLink = item => {
      if (!item || !item.id) {
        return null;
      }
      return `index.php?page=notifications&focus=${encodeURIComponent(item.id)}`;
    };

    const buildNotificationHtml = item => {
      if (!item) {
        return '';
      }
      const title = item.title || item.body || '';
      const body = item.body && item.body !== title ? item.body : '';
      const normalizedLevel = (item.level || 'info').toString().toLowerCase().replace(/[^a-z0-9_-]/g, '') || 'info';
      const itemClasses = ['topbar__notification-item', `level-${normalizedLevel}`];
      if (!item.is_read) {
        itemClasses.push('is-unread');
      }
      const channelKey = (item.channel || item.type || 'system').toString().toLowerCase();
      const channelLabel = notificationChannelLabels[channelKey] || (channelKey ? channelKey.charAt(0).toUpperCase() + channelKey.slice(1) : 'Sistema');
      const timeLabel = formatNotificationTime(item.created_at);
      const metaHtml = `<span class="topbar__notification-meta"><span class="topbar__notification-channel">${escapeHtml(channelLabel)}</span>${timeLabel ? `<span class="topbar__notification-time">${escapeHtml(timeLabel)}</span>` : ''}</span>`;
      const titleHtml = `<span class="topbar__notification-title">${escapeHtml(title)}</span>`;
      const bodyHtml = body ? `<p class="topbar__notification-body">${escapeHtml(body)}</p>` : '';
      const content = `${titleHtml}${bodyHtml}${metaHtml}`;
      const href = buildNotificationLink(item);

      if (href) {
        return `<li class="${itemClasses.join(' ')}"><a href="${escapeHtml(href)}" class="topbar__notification-link">${content}</a></li>`;
      }
      return `<li class="${itemClasses.join(' ')}">${content}</li>`;
    };

    const renderNotificationState = () => {
      if (listNode instanceof HTMLElement) {
        const unreadItems = notificationsState.items.filter(item => item && !item.is_read);
        const sortedItems = unreadItems.sort((a, b) => b.id - a.id);
        const visibleItems = sortedItems.slice(0, notificationDisplayLimit);
        if (visibleItems.length === 0) {
          listNode.innerHTML = '<li class="topbar__notification-empty">Nessuna notifica recente.</li>';
        } else {
          listNode.innerHTML = visibleItems.map(buildNotificationHtml).join('');
        }
      }
      updateUnreadUi(notificationsState.unread_count);
    };

    const mergeNotifications = incoming => {
      if (!Array.isArray(incoming) || incoming.length === 0) {
        return false;
      }
      const byId = new Map();
      notificationsState.items.forEach(item => {
        if (item) {
          byId.set(item.id, item);
        }
      });
      let hasNewUnread = false;
      incoming.forEach(raw => {
        const normalized = normalizeNotification(raw);
        if (!normalized) {
          return;
        }
        const already = byId.get(normalized.id);
        byId.set(normalized.id, normalized);
        if (normalized.id > notificationLastId) {
          notificationLastId = normalized.id;
        }
        if (!already || (!normalized.is_read && already.is_read)) {
          hasNewUnread = hasNewUnread || !normalized.is_read;
        }
      });
      const merged = Array.from(byId.values()).sort((a, b) => b.id - a.id);
      notificationsState = {
        ...notificationsState,
        items: merged.slice(0, notificationStorageLimit),
      };
      return hasNewUnread;
    };

    const connectNotificationStream = () => {
      if (!('EventSource' in window)) {
        return;
      }

      if (notificationSource) {
        notificationSource.close();
      }

      const streamUrl = `index.php?page=notifications_stream&last_id=${encodeURIComponent(notificationLastId)}`;
      const source = new EventSource(streamUrl);
      notificationSource = source;

      source.addEventListener('notifications', event => {
        try {
          const payload = JSON.parse(event.data);
          let hasNewUnread = false;
          if (payload && Array.isArray(payload.items)) {
            hasNewUnread = mergeNotifications(payload.items);
          }
          if (payload && typeof payload.unread_count === 'number') {
            notificationsState.unread_count = payload.unread_count;
          }
          if (payload && typeof payload.last_id === 'number' && payload.last_id > notificationLastId) {
            notificationLastId = payload.last_id;
          }
          renderNotificationState();
          if (hasNewUnread) {
            triggerBellAttention();
          }
        } catch (error) {
          console.error('Notifiche live non elaborate', error);
        }
      });

      source.addEventListener('error', () => {
        source.close();
        notificationSource = null;
        window.setTimeout(connectNotificationStream, 5000);
      });
    };

    renderNotificationState();
    connectNotificationStream();

    if (markForm) {
      markForm.addEventListener('submit', event => {
        event.preventDefault();
        const formData = new FormData(markForm);
        fetch(markForm.action, {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
          .then(response => {
            if (!response.ok) {
              throw new Error('Request failed');
            }
            return response.json();
          })
          .then(payload => {
            if (payload && payload.success) {
              notificationsState = {
                ...notificationsState,
                items: notificationsState.items.map(item => ({ ...item, is_read: true })),
                unread_count: 0,
              };
              renderNotificationState();
              setOpen(false);
              clearBellAttention();
            } else {
              markForm.submit();
            }
          })
          .catch(() => {
            markForm.submit();
          });
      });
    }

    if (listNode instanceof HTMLElement) {
      listNode.addEventListener('click', event => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
          return;
        }
        const link = target.closest('.topbar__notification-link');
        if (!(link instanceof HTMLAnchorElement)) {
          return;
        }
        event.preventDefault();
        const href = link.href;
        if (!href) {
          return;
        }
        setOpen(false);
        clearBellAttention();
        window.location.href = href;
      });
    }
  }

  const receiptModal = document.querySelector('[data-receipt-modal]');
  if (receiptModal instanceof HTMLElement) {
    const receiptFrame = receiptModal.querySelector('[data-receipt-frame]');
    const receiptLoader = receiptModal.querySelector('[data-receipt-loader]');
    const dismissControls = receiptModal.querySelectorAll('[data-receipt-dismiss]');
    let restoreFocusElement = null;
    let previousBodyOverflow = '';

    const setOpenState = open => {
      receiptModal.setAttribute('aria-hidden', open ? 'false' : 'true');
      if (open) {
        receiptModal.dataset.open = 'true';
        receiptModal.style.display = 'flex';
      } else {
        delete receiptModal.dataset.open;
        receiptModal.style.display = 'none';
      }
    };

    const showLoader = () => {
      if (receiptLoader instanceof HTMLElement) {
        receiptLoader.removeAttribute('hidden');
      }
    };

    const hideLoader = () => {
      if (receiptLoader instanceof HTMLElement) {
        receiptLoader.setAttribute('hidden', 'true');
      }
    };

    const resetFrame = () => {
      if (receiptFrame instanceof HTMLIFrameElement) {
        receiptFrame.src = 'about:blank';
      }
    };

    const closeReceiptModal = () => {
      setOpenState(false);
      resetFrame();
      showLoader();
      document.removeEventListener('keydown', handleKeydown);
      document.removeEventListener('click', handleBackdropClick, true);
      document.body.style.overflow = previousBodyOverflow;
      if (restoreFocusElement instanceof HTMLElement) {
        restoreFocusElement.focus({ preventScroll: true });
      }
    };

    const handleKeydown = event => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeReceiptModal();
      }
    };

    const handleBackdropClick = event => {
      if (!receiptModal.contains(event.target)) {
        return;
      }
      if (event.target instanceof HTMLElement && event.target.hasAttribute('data-receipt-dismiss')) {
        event.preventDefault();
        closeReceiptModal();
      }
    };

    const openReceiptModal = url => {
      if (!(receiptFrame instanceof HTMLIFrameElement)) {
        window.open(url, '_blank', 'noopener');
        return;
      }
      restoreFocusElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      previousBodyOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      showLoader();
      setOpenState(true);
      document.addEventListener('keydown', handleKeydown);
      document.addEventListener('click', handleBackdropClick, true);
      const closeBtn = receiptModal.querySelector('[data-receipt-dismiss]');
      if (closeBtn instanceof HTMLElement) {
        closeBtn.focus({ preventScroll: true });
      }
      try {
        receiptFrame.src = url;
      } catch (_error) {
        hideLoader();
        window.open(url, '_blank', 'noopener');
        closeReceiptModal();
      }
    };

    if (receiptFrame instanceof HTMLIFrameElement) {
      receiptFrame.addEventListener('load', () => {
        if (receiptModal.dataset.open === 'true') {
          hideLoader();
        }
      });
    }

    dismissControls.forEach(control => {
      control.addEventListener('click', event => {
        event.preventDefault();
        closeReceiptModal();
      });
    });

    document.addEventListener('click', event => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }
      const link = target.closest('[data-print-receipt]');
      if (!(link instanceof HTMLAnchorElement)) {
        return;
      }
      const href = link.href;
      if (!href) {
        return;
      }
      event.preventDefault();
      openReceiptModal(href);
    });

    window.addEventListener('app:openReceipt', event => {
      if (!event || !event.detail || !event.detail.url) {
        return;
      }
      openReceiptModal(event.detail.url);
    });
  }

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
    const updateSidebarToggle = collapsed => {
      const value = collapsed ? 'true' : 'false';
      sidebar.dataset.collapsed = value;
      document.body.dataset.sidebarCollapsed = value;
      toggle.setAttribute('aria-expanded', (!collapsed).toString());
      toggle.setAttribute('aria-label', collapsed ? 'Espandi menu' : 'Comprimi menu');
    };

    const saved = localStorage.getItem(collapseKey) === 'true';
    updateSidebarToggle(saved);

    toggle.addEventListener('click', () => {
      const nextState = !(sidebar.dataset.collapsed === 'true');
      updateSidebarToggle(nextState);
      localStorage.setItem(collapseKey, nextState.toString());
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
  const productsTable = document.querySelector('#products-table tbody');
  const addProductBtn = document.querySelector('[data-action="add-product-item"]');
  const productBarcodeInput = document.querySelector('#product_barcode_input');
  const productIndex = buildProductIndex();

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

      const priceInput = row.querySelector('input[name="item_price[]"]');
      if (priceInput instanceof HTMLInputElement) {
        priceInput.required = target.value !== '';
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
        if (priceInput instanceof HTMLInputElement) {
          priceInput.required = false;
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

  if (productsTable) {
    if (addProductBtn) {
      addProductBtn.addEventListener('click', () => {
        addNewProductRow();
      });
    }

    productsTable.addEventListener('click', event => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }
      if (!target.matches('[data-action="remove-product-item"]')) {
        return;
      }
      const row = target.closest('.product-row');
      if (!row) {
        return;
      }
      if (productsTable.querySelectorAll('.product-row').length === 1) {
        resetProductRow(row);
        scheduleDiscountUpdate();
        return;
      }
      row.remove();
      scheduleDiscountUpdate();
    });

    productsTable.addEventListener('change', event => {
      const target = event.target;
      if (!(target instanceof HTMLSelectElement)) {
        return;
      }
      if (target.name !== 'product_id[]') {
        return;
      }
      handleProductSelection(target);
    });

    productsTable.addEventListener('input', event => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement)) {
        return;
      }
      if (target.name === 'product_price[]' || target.name === 'product_quantity[]') {
        if (target.name === 'product_quantity[]') {
          const max = parseInt(target.getAttribute('max') || '', 10);
          const min = parseInt(target.getAttribute('min') || '0', 10);
          let nextValue = parseInt(target.value || '0', 10);
          if (!Number.isNaN(max)) {
            nextValue = Math.min(nextValue, max);
          }
          if (!Number.isNaN(min)) {
            nextValue = Math.max(nextValue, min);
          }
          if (Number.isNaN(nextValue)) {
            nextValue = !Number.isNaN(min) ? min : 0;
          }
          target.value = String(nextValue);
        }
        scheduleDiscountUpdate();
      }
    });
  }

  if (productBarcodeInput && productsTable) {
    productBarcodeInput.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        const code = productBarcodeInput.value.trim();
        handleProductBarcode(code);
        productBarcodeInput.value = '';
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
  setupFileUploads();
  setupAccordions();

  if (typeof window.PdaPrefill === 'object' && window.PdaPrefill) {
    applyPdaPrefill(window.PdaPrefill);
    window.PdaPrefill = null;
  }

  if (discountCampaignSelect && discountCampaignSelect.value) {
    scheduleDiscountUpdate();
  }

  function applyPdaPrefill(payload) {
    if (!payload || typeof payload !== 'object') {
      return;
    }

    if (itemsTable) {
      resetAllItemRows();
      const rows = Array.isArray(payload.items) ? payload.items : [];
      rows.forEach((item, index) => {
        let row = itemsTable.querySelectorAll('.item-row')[index] || null;
        if (!row) {
          row = addNewItemRow();
        }
        if (!row) {
          return;
        }
        setRowFromPrefill(row, item || {});
      });
      scheduleDiscountUpdate();
    }

    const customerSelect = document.querySelector('#customer_id');
    if (customerSelect instanceof HTMLSelectElement && typeof payload.customer_id === 'number') {
      customerSelect.value = String(payload.customer_id);
    }

    const customerNameInput = document.querySelector('#customer_name');
    if (customerNameInput instanceof HTMLInputElement && typeof payload.customer_name === 'string') {
      if (!customerNameInput.value) {
        customerNameInput.value = payload.customer_name;
      }
    }

    const customerEmailInput = document.querySelector('#customer_email');
    if (customerEmailInput instanceof HTMLInputElement && typeof payload.customer_email === 'string') {
      if (!customerEmailInput.value) {
        customerEmailInput.value = payload.customer_email;
      }
    }

    const customerPhoneInput = document.querySelector('#customer_phone');
    if (customerPhoneInput instanceof HTMLInputElement && typeof payload.customer_phone === 'string') {
      if (!customerPhoneInput.value) {
        customerPhoneInput.value = payload.customer_phone;
      }
    }

    const customerTaxCodeInput = document.querySelector('#customer_tax_code');
    if (customerTaxCodeInput instanceof HTMLInputElement && typeof payload.customer_tax_code === 'string') {
      if (!customerTaxCodeInput.value) {
        customerTaxCodeInput.value = payload.customer_tax_code;
      }
    }

    const customerNoteInput = document.querySelector('#customer_note');
    if (customerNoteInput instanceof HTMLInputElement && typeof payload.customer_note === 'string') {
      if (!customerNoteInput.value) {
        customerNoteInput.value = payload.customer_note;
      }
    }

    if (window.notify && typeof window.notify.success === 'function') {
      window.notify.success('Dati PDA importati. Controlla gli articoli prima di salvare.');
    }
  }

  function resetAllItemRows() {
    if (!itemsTable) {
      return;
    }
    const rows = itemsTable.querySelectorAll('.item-row');
    rows.forEach((row, index) => {
      if (index === 0) {
        resetRow(row);
      } else {
        row.remove();
      }
    });
  }

  function setRowFromPrefill(row, item) {
    if (!row) {
      return;
    }

    const iccidId = typeof item.iccid_id === 'number' ? item.iccid_id : null;
    const iccidCode = typeof item.iccid_code === 'string' ? item.iccid_code : null;
    let description = typeof item.description === 'string' ? item.description : '';
    let priceValue = typeof item.price === 'number' && Number.isFinite(item.price) ? item.price : null;
    const quantityValue = typeof item.quantity === 'number' && Number.isFinite(item.quantity) ? Math.max(1, Math.round(item.quantity)) : 1;
    const offerId = typeof item.offer_id === 'number' ? item.offer_id : null;
    const offerTitle = typeof item.offer_title === 'string' ? item.offer_title : null;

    const select = row.querySelector('select[name="item_iccid[]"]');
    const hidden = row.querySelector('input[name="item_iccid_code[]"]');
    const descInput = row.querySelector('input[name="item_description[]"]');
    const priceInput = row.querySelector('input[name="item_price[]"]');
    const qtyInput = row.querySelector('input[name="item_quantity[]"]');

    let offerOption = null;
    if (offerId !== null && offerSelect instanceof HTMLSelectElement) {
      offerOption = offerSelect.querySelector(`option[value="${offerId}"]`);
    }

    if (offerOption) {
      const titleFromOption = offerOption.dataset.title || (offerOption.textContent ? offerOption.textContent.trim() : '');
      const priceFromOption = parseFloat(offerOption.dataset.price || '');
      const normalizedDescription = description.toLowerCase();
      if (titleFromOption && (!description || normalizedDescription === 'attivazione sim')) {
        description = titleFromOption;
      }
      if (Number.isFinite(priceFromOption) && (priceValue === null || priceValue <= 0)) {
        priceValue = priceFromOption;
      }
      row.dataset.offerId = String(offerId);
      if (offerOption.dataset.provider) {
        row.dataset.offerProvider = offerOption.dataset.provider;
      } else {
        delete row.dataset.offerProvider;
      }
    } else if (offerTitle) {
      const normalizedDescription = description.toLowerCase();
      if (!description || normalizedDescription === 'attivazione sim') {
        description = offerTitle;
      }
      if (offerId !== null) {
        row.dataset.offerId = String(offerId);
      } else {
        delete row.dataset.offerId;
      }
    } else {
      delete row.dataset.offerId;
      delete row.dataset.offerProvider;
    }

    if (select instanceof HTMLSelectElement) {
      if (iccidId !== null) {
        select.value = String(iccidId);
      } else {
        select.value = '';
      }
    }

    if (hidden instanceof HTMLInputElement) {
      hidden.value = iccidCode ?? '';
    }

    if (descInput instanceof HTMLInputElement) {
      if (description) {
        descInput.value = description;
      }
    }

    if (priceInput instanceof HTMLInputElement) {
      if (priceValue !== null && Number.isFinite(priceValue)) {
        priceInput.value = priceValue.toFixed(2);
      }
      priceInput.required = iccidId !== null;
    }

    if (qtyInput instanceof HTMLInputElement) {
      qtyInput.value = String(quantityValue);
    }

    row.classList.add('highlight');
    window.setTimeout(() => row.classList.remove('highlight'), 1200);
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
        if (priceInput instanceof HTMLInputElement) {
          priceInput.required = true;
        }
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
        '<td class="table-actions-inline"><a class="btn btn--secondary" href="' + printUrl + '" target="_blank" rel="noopener" data-print-receipt>Stampa</a></td>' +
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

  function parseStockValue(value) {
    if (value == null || value === '') {
      return null;
    }
    const parsed = parseInt(String(value), 10);
    if (Number.isNaN(parsed)) {
      return null;
    }
    return parsed;
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

  function buildProductIndex() {
    const byId = new Map();
    const byBarcode = new Map();

    const templateSelect = document.querySelector('[data-products-select]');
    if (templateSelect instanceof HTMLSelectElement) {
      const options = templateSelect.querySelectorAll('option[value]');
      options.forEach(option => {
        const rawId = option.value;
        const id = parseInt(rawId, 10);
        if (!rawId || Number.isNaN(id) || id <= 0) {
          return;
        }
        const info = {
          id,
          name: option.dataset.name || option.textContent.trim(),
          price: parseFloat(option.dataset.price || '0') || 0,
          taxRate: parseFloat(option.dataset.tax || '0') || 0,
          barcode: (option.dataset.barcode || '').replace(/\s+/g, ''),
          sku: option.dataset.sku || '',
          stock: parseStockValue(option.dataset.stock),
        };
        byId.set(id, info);
        if (info.barcode) {
          byBarcode.set(info.barcode, info);
        }
      });
    }

    const barcodeOptions = document.querySelectorAll('#products_barcodes option');
    barcodeOptions.forEach(option => {
      const rawCode = option.value ? option.value.replace(/\s+/g, '') : '';
      const rawId = option.dataset.id || '';
      const id = parseInt(rawId, 10);
      if (!rawCode || Number.isNaN(id) || id <= 0) {
        return;
      }

      let info = byId.get(id);
      if (!info) {
        info = {
          id,
          name: option.dataset.name || option.textContent.trim(),
          price: parseFloat(option.dataset.price || '0') || 0,
          taxRate: parseFloat(option.dataset.tax || '0') || 0,
          barcode: rawCode,
            sku: option.dataset.sku || '',
            stock: parseStockValue(option.dataset.stock),
        };
        byId.set(id, info);
      }
      if (!byBarcode.has(rawCode)) {
        byBarcode.set(rawCode, info);
      }
    });

    return { byId, byBarcode };
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

  function findFirstEmptyProductRow() {
    if (!productsTable) {
      return null;
    }
    const rows = productsTable.querySelectorAll('.product-row');
    for (const row of rows) {
      const select = row.querySelector('select[name="product_id[]"]');
      if (select && !select.value) {
        return row;
      }
    }
    return null;
  }

  function addNewProductRow() {
    if (!productsTable) {
      return null;
    }
    const firstRow = productsTable.querySelector('.product-row');
    if (!firstRow) {
      return null;
    }
    const clone = firstRow.cloneNode(true);
    resetProductRow(clone);
    productsTable.appendChild(clone);
    return clone;
  }

  function resetProductRow(row) {
    row.querySelectorAll('input').forEach(input => {
      if (input.name === 'product_quantity[]') {
        input.value = '1';
        input.min = '1';
        input.removeAttribute('max');
        return;
      }
      input.value = '';
    });
    const select = row.querySelector('select[name="product_id[]"]');
    if (select) {
      select.value = '';
    }
    updateProductTaxLabel(row, null);
    updateProductStockLabel(row, null);
    delete row.dataset.productBarcode;
    if ('productStock' in row.dataset) {
      delete row.dataset.productStock;
    }
    row.classList.remove('product-row--out-of-stock');
  }

  function updateProductTaxLabel(row, value) {
    const label = row.querySelector('[data-product-tax-label]');
    if (!(label instanceof HTMLElement)) {
      return;
    }
    const taxRate = typeof value === 'number' && Number.isFinite(value) ? value : null;
    label.classList.remove('badge--success');
    label.classList.remove('badge--muted');
    if (taxRate === null || taxRate <= 0) {
      label.textContent = 'IVA n/d';
      label.classList.add('badge--muted');
    } else {
      const formatter = new Intl.NumberFormat('it-IT', {
        minimumFractionDigits: taxRate % 1 === 0 ? 0 : 2,
        maximumFractionDigits: 2,
      });
      label.textContent = 'IVA ' + formatter.format(taxRate) + '%';
      label.classList.add('badge--success');
    }
  }

  function updateProductStockLabel(row, value) {
    const label = row.querySelector('[data-product-stock-label]');
    if (!(label instanceof HTMLElement)) {
      return;
    }

    const stock = typeof value === 'number' && Number.isFinite(value) ? value : null;
    label.classList.remove('badge--warning');
    label.classList.remove('badge--muted');

    if (stock === null) {
      label.textContent = 'Stock n/d';
      label.classList.add('badge--muted');
      return;
    }

    if (stock <= 0) {
      label.textContent = 'Stock esaurito';
      label.classList.add('badge--warning');
      return;
    }

    label.textContent = 'Stock ' + stock;
    label.classList.add('badge--muted');
  }

  function setProductRowFromInfo(row, info, options = {}) {
    const settings = Object.assign({ force: false }, options);
    const select = row.querySelector('select[name="product_id[]"]');
    if (select) {
      select.value = info.id.toString();
    }
    const hiddenTax = row.querySelector('input[name="product_tax_rate[]"]');
    if (hiddenTax instanceof HTMLInputElement) {
      hiddenTax.value = Number.isFinite(info.taxRate) ? info.taxRate.toFixed(2) : '';
    }
    updateProductTaxLabel(row, info.taxRate);
    updateProductStockLabel(row, typeof info.stock === 'number' ? info.stock : null);

    const descInput = row.querySelector('input[name="product_description[]"]');
    if (descInput instanceof HTMLInputElement) {
      if (!descInput.value || settings.force) {
        descInput.value = info.name || '';
      }
    }

    const priceInput = row.querySelector('input[name="product_price[]"]');
    if (priceInput instanceof HTMLInputElement) {
      if (!priceInput.value || settings.force) {
        priceInput.value = Number.isFinite(info.price) ? info.price.toFixed(2) : '';
      }
    }

    const qtyInput = row.querySelector('input[name="product_quantity[]"]');
    if (qtyInput instanceof HTMLInputElement) {
      const parsedQty = parseInt(qtyInput.value || '0', 10);
      if (Number.isNaN(parsedQty) || parsedQty <= 0) {
        qtyInput.value = '1';
      }

      if (typeof info.stock === 'number' && Number.isFinite(info.stock)) {
        const available = Math.max(info.stock, 0);
        qtyInput.max = available.toString();
        qtyInput.min = available > 0 ? '1' : '0';
        if (available === 0) {
          qtyInput.value = '0';
        } else if (parseInt(qtyInput.value || '0', 10) > available) {
          qtyInput.value = available.toString();
        }
      } else {
        if (qtyInput.hasAttribute('max')) {
          qtyInput.removeAttribute('max');
        }
        qtyInput.min = '1';
      }
    }

    if (info.barcode) {
      row.dataset.productBarcode = info.barcode;
    } else if ('productBarcode' in row.dataset) {
      delete row.dataset.productBarcode;
    }
    if (typeof info.stock === 'number' && Number.isFinite(info.stock)) {
      row.dataset.productStock = info.stock.toString();
    } else if ('productStock' in row.dataset) {
      delete row.dataset.productStock;
    }

    if (typeof info.stock === 'number' && Number.isFinite(info.stock) && info.stock <= 0) {
      row.classList.add('product-row--out-of-stock');
    } else {
      row.classList.remove('product-row--out-of-stock');
    }
  }

  function handleProductSelection(select) {
    const row = select.closest('.product-row');
    if (!row) {
      return;
    }

    const parsedId = parseInt(select.value || '0', 10);
    if (!select.value || Number.isNaN(parsedId) || parsedId <= 0) {
      const hiddenTax = row.querySelector('input[name="product_tax_rate[]"]');
      if (hiddenTax instanceof HTMLInputElement) {
        hiddenTax.value = '';
      }
      updateProductTaxLabel(row, null);
      updateProductStockLabel(row, null);
      if ('productBarcode' in row.dataset) {
        delete row.dataset.productBarcode;
      }
      if ('productStock' in row.dataset) {
        delete row.dataset.productStock;
      }
      const qtyInput = row.querySelector('input[name="product_quantity[]"]');
      if (qtyInput instanceof HTMLInputElement) {
        qtyInput.min = '1';
        qtyInput.removeAttribute('max');
        if (!qtyInput.value || parseInt(qtyInput.value || '0', 10) <= 0) {
          qtyInput.value = '1';
        }
      }
      row.classList.remove('product-row--out-of-stock');
      scheduleDiscountUpdate();
      return;
    }

    const info = productIndex.byId.get(parsedId);
    if (!info) {
      updateProductTaxLabel(row, null);
      updateProductStockLabel(row, null);
      const qtyInput = row.querySelector('input[name="product_quantity[]"]');
      if (qtyInput instanceof HTMLInputElement) {
        qtyInput.min = '1';
        qtyInput.removeAttribute('max');
      }
      row.classList.remove('product-row--out-of-stock');
      scheduleDiscountUpdate();
      return;
    }

    setProductRowFromInfo(row, info);
    scheduleDiscountUpdate();
  }

  function handleProductBarcode(code) {
    if (!code) {
      return;
    }
    const normalized = code.replace(/\s+/g, '');
    if (!normalized) {
      return;
    }
    const info = productIndex.byBarcode.get(normalized);
    if (!info) {
      notify.danger('Prodotto non trovato in catalogo: ' + code);
      return;
    }

    let row = findFirstEmptyProductRow();
    if (!row) {
      row = addNewProductRow();
    }
    if (!row) {
      notify.danger('Impossibile creare una nuova riga prodotto.');
      return;
    }

    setProductRowFromInfo(row, info, { force: true });
    row.classList.add('highlight');
    window.setTimeout(() => row.classList.remove('highlight'), 1200);
    scheduleDiscountUpdate();
  }

  function calculateSaleSubtotal() {
    let subtotal = 0;
    if (itemsTable) {
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
    }
    if (productsTable) {
      productsTable.querySelectorAll('.product-row').forEach(row => {
        const priceInput = row.querySelector('input[name="product_price[]"]');
        const quantityInput = row.querySelector('input[name="product_quantity[]"]');
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
    }
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
      if (input instanceof HTMLInputElement && 'itemPrice' in input.dataset) {
        input.required = false;
      }
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

  function setupFileUploads() {
    const widgets = document.querySelectorAll('[data-file-upload]');
    widgets.forEach(widget => {
      const input = widget.querySelector('input[type="file"]');
      const nameTarget = widget.querySelector('[data-file-upload-name]');
      if (!input || !nameTarget) {
        return;
      }

      const placeholder = nameTarget.getAttribute('data-placeholder') || 'Nessun file selezionato';

      const updateLabel = () => {
        const files = input.files;
        const nextLabel = files && files.length > 0 ? files[0].name : placeholder;
        nameTarget.textContent = nextLabel;
      };

      const trigger = widget.querySelector('[data-file-upload-trigger]');
      if (trigger) {
        trigger.addEventListener('click', event => {
          event.preventDefault();
          input.click();
        });
      }

      input.addEventListener('change', updateLabel);
      input.addEventListener('blur', updateLabel);
      updateLabel();
    });
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
