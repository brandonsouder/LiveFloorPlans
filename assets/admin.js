(function () {
  const config = window.SLFPAdmin || {};
  const app = document.querySelector('[data-slfp-admin-app]');

  const state = {
    suites: [],
    overlays: [],
    buildings: [],
    activeSuiteId: null,
    dragging: null,
    filter: 'all',
    query: '',
  };

  const strings = config.strings || {};

  const els = {
    imageId: document.querySelector('#slfp-image-id'),
    buildingId: document.querySelector('[data-slfp-building-id]'),
    buildingSelect: document.querySelector('[data-slfp-building-select]'),
    imagePreview: document.querySelector('[data-slfp-image-preview]'),
    selectImage: document.querySelector('[data-slfp-select-image]'),
    clearImage: document.querySelector('[data-slfp-clear-image]'),
    sync: document.querySelector('[data-slfp-sync]'),
    search: app ? app.querySelector('[data-slfp-search]') : null,
    filter: app ? app.querySelector('[data-slfp-filter]') : null,
    save: app ? app.querySelector('[data-slfp-save-overlays]') : null,
    status: app ? app.querySelector('[data-slfp-status]') : null,
    map: app ? app.querySelector('[data-slfp-map]') : null,
    image: app ? app.querySelector('[data-slfp-map-image]') : null,
    layer: app ? app.querySelector('[data-slfp-label-layer]') : null,
    list: app ? app.querySelector('[data-slfp-suite-list]') : null,
    count: app ? app.querySelector('[data-slfp-suite-count]') : null,
  };

  const api = async (url, options) => {
    options = options || {};
    const headers = options.headers || {};
    const requestOptions = Object.assign({}, options, {
      headers: Object.assign({}, headers, {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce,
      }),
    });
    const response = await fetch(url, requestOptions);
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error((json.error && json.error.message) || json.message || response.statusText);
    }
    return json;
  };

  const setStatus = (text, isError = false) => {
    if (!els.status) return;
    els.status.textContent = text || '';
    els.status.classList.toggle('is-error', Boolean(isError));
  };

  const overlayFor = id => state.overlays.find(item => Number(item.suite_id) === Number(id));
  const suiteFor = id => state.suites.find(item => Number(item.id) === Number(id));
  const statusLabel = suite => String((suite && suite.status) || 'unknown').replace(/_/g, ' ');

  const money = value => {
    const number = Number(value || 0);
    return number ? `$${number.toLocaleString()}/mo` : 'Price TBD';
  };

  const load = async () => {
    if (!app || !config.postId || !config.restUrl) {
      if (els.list) els.list.innerHTML = '<div class="slfp-editor-note">Save this floor plan first, then sync suites and place labels.</div>';
      if (els.count) els.count.textContent = '';
      return;
    }
    try {
      const payload = await api(config.restUrl, { method: 'GET' });
      state.suites = payload.suites || (payload.data && payload.data.suites) || [];
      state.overlays = payload.overlays || (payload.data && payload.data.overlays) || [];
      render();
    } catch (error) {
      setStatus(error.message, true);
    }
  };

  const loadBuildings = async () => {
    if (!els.buildingSelect || !config.buildingsRestUrl) return;
    try {
      const payload = await api(config.buildingsRestUrl, { method: 'GET' });
      state.buildings = payload.data || [];
      renderBuildingPicker();
    } catch (error) {
      if (els.buildingSelect.options.length <= 1) {
        els.buildingSelect.innerHTML = `<option value="">${escapeHtml(error.message || 'Could not load buildings')}</option>`;
      } else {
        setStatus(error.message || 'Could not refresh building list.', true);
      }
    }
  };

  const renderBuildingPicker = () => {
    if (!els.buildingSelect) return;
    const current = String(els.buildingId ? els.buildingId.value || '' : '');
    const options = [`<option value="">${escapeHtml(strings.chooseBuilding || 'Choose a building')}</option>`];
    state.buildings.forEach(building => {
      const label = `${building.name || `Building ${building.id}`}${building.address ? ` — ${building.address}` : ''} (#${building.id})`;
      options.push(`<option value="${escapeHtml(building.id)}"${String(building.id) === current ? ' selected' : ''}>${escapeHtml(label)}</option>`);
    });
    els.buildingSelect.innerHTML = options.join('');
  };

  const saveOverlays = async () => {
    if (!config.postId) {
      setStatus('Save this floor plan before saving overlay positions.', true);
      return;
    }
    setStatus('Saving...');
    try {
      const payload = await api(`${config.restUrl}/overlays`, {
        method: 'POST',
        body: JSON.stringify({ overlays: state.overlays }),
      });
      const data = payload.data || {};
      state.overlays = data.overlays || state.overlays;
      render();
      setStatus(strings.saved || 'Saved.');
    } catch (error) {
      setStatus(error.message || strings.saveFailed || 'Save failed.', true);
    }
  };

  const syncSuites = async () => {
    if (!config.postId) {
      setStatus('Save this floor plan before syncing suites.', true);
      return;
    }
    setStatus(strings.syncing || 'Syncing...');
    try {
      const payload = await api(`${config.restUrl}/sync`, { method: 'POST', body: JSON.stringify({ building_id: Number(els.buildingId && els.buildingId.value || 0) }) });
      const data = payload.data || {};
      state.suites = data.suites || [];
      state.overlays = data.overlays || state.overlays;
      render();
      setStatus(strings.synced || 'Synced.');
    } catch (error) {
      setStatus(error.message || strings.syncFailed || 'Sync failed.', true);
    }
  };

  const placeSuite = suite => {
    if (!suite || !els.image || !els.layer) return;
    let overlay = overlayFor(suite.id);
    if (!overlay) {
      overlay = { suite_id: Number(suite.id), suite_number: suite.suite_number || '', x: 50, y: 50 };
      state.overlays.push(overlay);
    }
    state.activeSuiteId = Number(suite.id);
    render();
  };

  const removeOverlay = suiteId => {
    state.overlays = state.overlays.filter(item => Number(item.suite_id) !== Number(suiteId));
    if (Number(state.activeSuiteId) === Number(suiteId)) state.activeSuiteId = null;
    render();
  };

  const mapPoint = event => {
    if (!els.map) return { x: 50, y: 50 };
    const rect = els.map.getBoundingClientRect();
    return {
      x: Math.max(0, Math.min(100, ((event.clientX - rect.left) / rect.width) * 100)),
      y: Math.max(0, Math.min(100, ((event.clientY - rect.top) / rect.height) * 100)),
    };
  };

  const renderLabels = () => {
    if (!els.layer) return;
    els.layer.replaceChildren();
    state.overlays.forEach(overlay => {
      const suite = suiteFor(overlay.suite_id) || {};
      const label = document.createElement('button');
      label.type = 'button';
      label.className = `slfp-admin-label status-${suite.status || 'unknown'}`;
      label.classList.toggle('is-active', Number(state.activeSuiteId) === Number(overlay.suite_id));
      label.style.left = `${overlay.x}%`;
      label.style.top = `${overlay.y}%`;
      label.dataset.suiteId = overlay.suite_id;
      label.innerHTML = `<strong>${escapeHtml(overlay.label_override || suite.suite_number || overlay.suite_number || overlay.suite_id)}</strong><span>${escapeHtml(statusLabel(suite))}</span>`;
      label.addEventListener('pointerdown', event => {
        event.preventDefault();
        state.activeSuiteId = Number(overlay.suite_id);
        label.setPointerCapture(event.pointerId);
        state.dragging = { suiteId: Number(overlay.suite_id), pointerId: event.pointerId };
        renderList();
      });
      label.addEventListener('click', () => {
        state.activeSuiteId = Number(overlay.suite_id);
        render();
      });
      els.layer.append(label);
    });
  };

  const renderList = () => {
    if (!els.list || !els.count) return;
    const mapped = new Set(state.overlays.map(item => Number(item.suite_id)));
    const query = state.query.toLowerCase();
    const suites = state.suites.filter(suite => {
      const isMapped = mapped.has(Number(suite.id));
      if (state.filter === 'mapped' && !isMapped) return false;
      if (state.filter === 'unmapped' && isMapped) return false;
      if (query && !String(suite.suite_number || '').toLowerCase().includes(query) && !String(suite.building_name || '').toLowerCase().includes(query)) return false;
      return true;
    });
    els.count.textContent = `${mapped.size} mapped / ${state.suites.length} synced`;
    els.list.replaceChildren();
    suites.forEach(suite => {
      const isMapped = mapped.has(Number(suite.id));
      const row = document.createElement('div');
      row.className = 'slfp-suite-row';
      row.classList.toggle('is-active', Number(state.activeSuiteId) === Number(suite.id));
      row.innerHTML = `
        <button type="button" class="slfp-suite-main">
          <strong>Suite ${escapeHtml(suite.suite_number || suite.id)}</strong>
          <span>${Number(suite.square_feet || 0).toLocaleString()} sq ft · ${escapeHtml(money(suite.monthly_rate))} · ${escapeHtml(statusLabel(suite))}</span>
        </button>
        <button type="button" class="button-link">${isMapped ? 'Remove' : 'Place'}</button>
      `;
      row.querySelector('.slfp-suite-main').addEventListener('click', () => placeSuite(suite));
      row.querySelector('.button-link').addEventListener('click', () => isMapped ? removeOverlay(suite.id) : placeSuite(suite));
      els.list.append(row);
    });
  };

  const render = () => {
    renderLabels();
    renderList();
  };

  const escapeHtml = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  })[char]);

  document.addEventListener('pointermove', event => {
    if (!state.dragging || !els.map) return;
    const overlay = overlayFor(state.dragging.suiteId);
    if (!overlay) return;
    const point = mapPoint(event);
    overlay.x = Number(point.x.toFixed(4));
    overlay.y = Number(point.y.toFixed(4));
    const label = els.layer.querySelector(`[data-suite-id="${state.dragging.suiteId}"]`);
    if (label) {
      label.style.left = `${overlay.x}%`;
      label.style.top = `${overlay.y}%`;
    }
  });

  document.addEventListener('pointerup', () => {
    state.dragging = null;
  });

  if (els.search) {
    els.search.addEventListener('input', event => {
      state.query = event.target.value || '';
      renderList();
    });
  }

  if (els.filter) {
    els.filter.addEventListener('change', event => {
      state.filter = event.target.value || 'all';
      renderList();
    });
  }

  if (els.save) els.save.addEventListener('click', saveOverlays);
  if (els.sync) els.sync.addEventListener('click', syncSuites);

  if (els.buildingSelect) {
    els.buildingSelect.addEventListener('change', event => {
      if (els.buildingId) els.buildingId.value = event.target.value || '';
      setStatus(event.target.value ? 'Building selected. Sync suites to load spaces.' : '');
    });
  }

  if (els.buildingId) {
    els.buildingId.addEventListener('input', renderBuildingPicker);
  }

  if (els.selectImage) els.selectImage.addEventListener('click', () => {
    const frame = wp.media({
      title: strings.selectImage || 'Select floor-plan image',
      button: { text: 'Use this image' },
      multiple: false,
    });
    frame.on('select', () => {
      const attachment = frame.state().get('selection').first().toJSON();
      els.imageId.value = attachment.id || '';
      els.imagePreview.innerHTML = attachment.url ? `<img src="${escapeHtml(attachment.url)}" alt="">` : '';
    });
    frame.open();
  });

  if (els.clearImage) els.clearImage.addEventListener('click', () => {
    els.imageId.value = '';
    els.imagePreview.replaceChildren();
  });

  loadBuildings();
  load();
})();
