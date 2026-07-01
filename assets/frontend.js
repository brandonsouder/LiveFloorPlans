(function () {
  const roots = document.querySelectorAll('[data-slfp-public]');
  if (!roots.length) return;

  const escapeHtml = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  })[char]);

  const money = value => {
    const number = Number(value || 0);
    return number ? `$${number.toLocaleString()}/mo` : 'Call for price';
  };

  const statusLabel = suite => String((suite && suite.status) || 'unknown').replace(/_/g, ' ');
  const isAvailable = suite => ['available', 'coming_soon'].includes(String((suite && suite.status) || ''));
  const canOpenSuite = suite => Boolean(suite && suite.url && isAvailable(suite));
  const labelPrice = suite => isAvailable(suite) ? money(suite && suite.monthly_rate) : '';
  const distance = (a, b) => Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
  const midpoint = (a, b) => ({ clientX: (a.clientX + b.clientX) / 2, clientY: (a.clientY + b.clientY) / 2 });

  roots.forEach(async root => {
    let data = {};
    try {
      const response = await fetch(root.dataset.slfpEndpoint, { headers: { Accept: 'application/json' } });
      data = await response.json();
      if (!response.ok) throw new Error(data.message || response.statusText);
    } catch (error) {
      root.innerHTML = '<p class="slfp-stale">Floor plan data is temporarily unavailable.</p>';
      return;
    }

    const stage = root.querySelector('[data-slfp-stage]');
    const canvas = root.querySelector('[data-slfp-canvas]');
    const layer = root.querySelector('[data-slfp-label-layer]');
    const search = root.querySelector('[data-slfp-search]');
    const availableOnly = root.querySelector('[data-slfp-available-only]');
    const detail = root.querySelector('[data-slfp-detail]');
    const suites = new Map((data.suites || []).map(suite => [Number(suite.id), suite]));
    const mobileQuery = window.matchMedia('(max-width: 640px)');
    const minScale = 1;
    const maxScale = 4;
    const tapSlop = 6;
    const panSlack = 48;
    let scale = mobileQuery.matches ? 1.35 : 1;
    let x = 0;
    let y = 0;
    let query = '';
    let onlyAvailable = false;
    let activePointers = new Map();
    let panState = null;
    let pinchState = null;
    let gestureMoved = false;
    let suppressClick = false;

    const defaultScale = () => mobileQuery.matches ? 1.35 : 1;
    const clampScale = value => Math.max(minScale, Math.min(maxScale, Number(value.toFixed(3))));

    const setSuppressClick = () => {
      suppressClick = true;
      window.setTimeout(() => {
        suppressClick = false;
      }, 250);
    };

    const applyTransform = () => {
      const labelScale = mobileQuery.matches
        ? Math.max(0.32, Math.min(0.78, 1 / scale))
        : Math.max(0.65, Math.min(1, 1 / Math.sqrt(scale)));
      canvas.style.transform = `translate(${x}px, ${y}px) scale(${scale})`;
      root.style.setProperty('--slfp-zoom', String(scale));
      root.style.setProperty('--slfp-label-scale', String(labelScale.toFixed(3)));
      root.classList.toggle('is-zoomed-in', scale >= 1.6);
      root.classList.toggle('is-detail-zoom', scale >= 2.2);
    };

    const clampPosition = () => {
      const stageWidth = stage.clientWidth || stage.getBoundingClientRect().width;
      const stageHeight = stage.clientHeight || stage.getBoundingClientRect().height;
      const canvasWidth = canvas.offsetWidth || stageWidth;
      const canvasHeight = canvas.offsetHeight || stageHeight;
      const overflowX = Math.max(0, (canvasWidth * scale - stageWidth) / 2);
      const overflowY = Math.max(0, (canvasHeight * scale - stageHeight) / 2);
      const maxX = overflowX + panSlack;
      const maxY = overflowY + panSlack;
      x = Math.max(-maxX, Math.min(maxX, x));
      y = Math.max(-maxY, Math.min(maxY, y));
    };

    const setScale = next => {
      scale = clampScale(next);
      clampPosition();
      applyTransform();
    };

    const zoomAt = (next, clientX, clientY) => {
      const previous = scale;
      const nextScale = clampScale(next);
      if (nextScale === previous) return;
      const rect = stage.getBoundingClientRect();
      const dx = clientX - rect.left - rect.width / 2;
      const dy = clientY - rect.top - rect.height / 2;
      const ratio = nextScale / previous;
      x += (dx - x) * (1 - ratio);
      y += (dy - y) * (1 - ratio);
      scale = nextScale;
      clampPosition();
      applyTransform();
    };

    const resetView = () => {
      scale = defaultScale();
      x = 0;
      y = 0;
      clampPosition();
      applyTransform();
    };

    const hideDetail = () => {
      if (!detail || detail.hidden) return;
      detail.hidden = true;
      detail.setAttribute('hidden', '');
      detail.style.display = 'none';
      detail.classList.remove('is-expanded');
      detail.style.removeProperty('--slfp-sheet-drag');
      root.classList.remove('has-open-sheet');
      root.classList.remove('has-expanded-sheet');
    };

    const expandDetail = suite => {
      if (!detail || !canOpenSuite(suite)) return;
      detail.classList.add('is-expanded');
      root.classList.add('has-expanded-sheet');
      if (detail.querySelector('[data-slfp-suite-frame]')) return;
      const frameUrl = new URL(suite.url, window.location.href);
      frameUrl.searchParams.set('slfp_embed', '1');
      const frameWrap = document.createElement('div');
      frameWrap.className = 'slfp-suite-frame-wrap';
      frameWrap.innerHTML = `
        <div class="slfp-suite-frame-bar">
          <span>Suite Page</span>
          <a href="${escapeHtml(suite.url)}" target="_blank" rel="noopener">Open in new tab</a>
        </div>
        <iframe data-slfp-suite-frame title="Suite ${escapeHtml(suite.suite_number || suite.id)} page" src="${escapeHtml(frameUrl.toString())}" loading="lazy"></iframe>
      `;
      detail.append(frameWrap);
    };

    const showDetail = suite => {
      if (!detail || !suite) return;
      detail.hidden = false;
      detail.removeAttribute('hidden');
      detail.style.display = '';
      detail.classList.remove('is-expanded');
      detail.dataset.slfpSuiteId = String(suite.id || '');
      root.classList.add('has-open-sheet');
      root.classList.remove('has-expanded-sheet');
      const price = labelPrice(suite);
      const canOpen = canOpenSuite(suite);
      detail.innerHTML = `
        ${canOpen ? '<button type="button" class="slfp-sheet-handle" aria-label="Expand suite page" data-slfp-expand-sheet></button>' : ''}
        <button type="button" class="slfp-sheet-close" aria-label="Close details" data-slfp-close-detail>&times;</button>
        <small>${escapeHtml(statusLabel(suite))}</small>
        <strong>Suite ${escapeHtml(suite.suite_number || suite.id)}</strong>
        <span>${Number(suite.square_feet || 0).toLocaleString()} sq ft${price ? ` · ${escapeHtml(price)}` : ''}</span>
        ${canOpen ? '<button type="button" class="slfp-sheet-action" data-slfp-open-suite>View Suite Page</button>' : ''}
      `;
      const closeButton = detail.querySelector('[data-slfp-close-detail]');
      const closeSheet = event => {
        event.preventDefault();
        event.stopPropagation();
        if (event.stopImmediatePropagation) event.stopImmediatePropagation();
        hideDetail();
      };
      closeButton.addEventListener('pointerdown', closeSheet);
      closeButton.addEventListener('mousedown', closeSheet);
      closeButton.addEventListener('touchstart', closeSheet, { passive: false });
      closeButton.addEventListener('pointerup', closeSheet);
      closeButton.addEventListener('click', closeSheet);
      const openButton = detail.querySelector('[data-slfp-open-suite]');
      if (openButton) {
        openButton.addEventListener('pointerdown', event => {
          event.stopPropagation();
        });
        openButton.addEventListener('click', event => {
          event.preventDefault();
          event.stopPropagation();
          expandDetail(suite);
        });
      }
      const expandButton = detail.querySelector('[data-slfp-expand-sheet]');
      if (expandButton) {
        expandButton.addEventListener('pointerdown', event => {
          event.stopPropagation();
        });
        expandButton.addEventListener('click', event => {
          event.preventDefault();
          event.stopPropagation();
          expandDetail(suite);
        });
      }
    };

    const renderLabels = () => {
      layer.replaceChildren();
      const q = query.toLowerCase();
      (data.overlays || []).forEach(overlay => {
        const suite = suites.get(Number(overlay.suite_id)) || {};
        const number = overlay.label_override || suite.suite_number || overlay.suite_number || overlay.suite_id;
        if (q && !String(number).toLowerCase().includes(q)) return;
        if (onlyAvailable && !isAvailable(suite)) return;
        const price = labelPrice(suite);
        const label = document.createElement('button');
        label.type = 'button';
        label.className = `slfp-label status-${suite.status || 'unknown'}${isAvailable(suite) ? '' : ' is-unavailable'}`;
        label.dataset.slfpSuiteId = String(suite.id || '');
        label.style.left = `${overlay.x}%`;
        label.style.top = `${overlay.y}%`;
        label.innerHTML = `
          <strong>${escapeHtml(number)}</strong>
          <small>${Number(suite.square_feet || 0).toLocaleString()} sq ft</small>
          ${price ? `<small>${escapeHtml(price)}</small>` : `<small>${escapeHtml(statusLabel(suite))}</small>`}
        `;
        label.setAttribute('aria-label', `Suite ${number}, ${statusLabel(suite)}`);
        label.addEventListener('click', event => {
          event.preventDefault();
          event.stopPropagation();
          if (suppressClick) return;
          showDetail(suite);
        });
        layer.append(label);
      });
    };

    const currentPointers = () => Array.from(activePointers.values());

    const startPinch = () => {
      const pointers = currentPointers();
      if (pointers.length < 2) {
        pinchState = null;
        return;
      }
      const first = pointers[0];
      const second = pointers[1];
      const startMid = midpoint(first, second);
      const rect = stage.getBoundingClientRect();
      pinchState = {
        distance: Math.max(1, distance(first, second)),
        scale,
        x,
        y,
        midX: startMid.clientX - rect.left - rect.width / 2,
        midY: startMid.clientY - rect.top - rect.height / 2,
      };
      gestureMoved = true;
      setSuppressClick();
    };

    const updatePinch = () => {
      if (!pinchState) return;
      const pointers = currentPointers();
      if (pointers.length < 2) return;
      const first = pointers[0];
      const second = pointers[1];
      const currentDistance = Math.max(1, distance(first, second));
      const currentMid = midpoint(first, second);
      const rect = stage.getBoundingClientRect();
      const dx = currentMid.clientX - rect.left - rect.width / 2;
      const dy = currentMid.clientY - rect.top - rect.height / 2;
      const nextScale = clampScale(pinchState.scale * (currentDistance / pinchState.distance));
      const localX = (pinchState.midX - pinchState.x) / pinchState.scale;
      const localY = (pinchState.midY - pinchState.y) / pinchState.scale;
      scale = nextScale;
      x = dx - localX * scale;
      y = dy - localY * scale;
      clampPosition();
      applyTransform();
    };

    const zoomIn = root.querySelector('[data-slfp-zoom-in]');
    const zoomOut = root.querySelector('[data-slfp-zoom-out]');
    const reset = root.querySelector('[data-slfp-reset]');
    if (zoomIn) zoomIn.addEventListener('click', () => setScale(scale + 0.25));
    if (zoomOut) zoomOut.addEventListener('click', () => setScale(scale - 0.25));
    if (reset) reset.addEventListener('click', resetView);

    if (search) {
      search.addEventListener('input', event => {
        query = event.target.value || '';
        root.classList.toggle('has-suite-search', Boolean(query.trim()));
        renderLabels();
      });
    }

    if (availableOnly) {
      availableOnly.addEventListener('change', event => {
        onlyAvailable = Boolean(event.target.checked);
        renderLabels();
      });
    }

    if (detail) {
      let sheetGesture = null;
      const delegatedClose = event => {
        if (!event.target.closest('[data-slfp-close-detail]')) return;
        event.preventDefault();
        event.stopPropagation();
        if (event.stopImmediatePropagation) event.stopImmediatePropagation();
        hideDetail();
      };
      detail.addEventListener('pointerdown', delegatedClose);
      detail.addEventListener('mousedown', delegatedClose);
      detail.addEventListener('touchstart', delegatedClose, { passive: false });
      detail.addEventListener('click', delegatedClose);
      document.addEventListener('pointerdown', delegatedClose, true);
      document.addEventListener('mousedown', delegatedClose, true);
      document.addEventListener('touchstart', delegatedClose, { capture: true, passive: false });
      document.addEventListener('click', delegatedClose, true);

      detail.addEventListener('click', event => {
        if (event.target.closest('[data-slfp-close-detail], [data-slfp-open-suite], a, iframe')) return;
        const suite = suites.get(Number(detail.dataset.slfpSuiteId || 0));
        if (canOpenSuite(suite) && !detail.classList.contains('is-expanded')) {
          expandDetail(suite);
        }
      });

      detail.addEventListener('pointerdown', event => {
        if (event.target.closest('[data-slfp-close-detail], [data-slfp-open-suite], iframe')) return;
        sheetGesture = {
          id: event.pointerId,
          startY: event.clientY,
          latestY: event.clientY,
        };
        try {
          detail.setPointerCapture(event.pointerId);
        } catch (error) {
          // Pointer capture is best-effort; swipe still works without it in most browsers.
        }
      });

      detail.addEventListener('pointermove', event => {
        if (!sheetGesture || sheetGesture.id !== event.pointerId) return;
        sheetGesture.latestY = event.clientY;
        const dy = Math.max(-80, Math.min(120, event.clientY - sheetGesture.startY));
        if (Math.abs(dy) > 6) {
          event.preventDefault();
          detail.style.setProperty('--slfp-sheet-drag', `${dy}px`);
        }
      });

      const endSheetGesture = event => {
        if (!sheetGesture || sheetGesture.id !== event.pointerId) return;
        const dy = sheetGesture.latestY - sheetGesture.startY;
        sheetGesture = null;
        detail.style.removeProperty('--slfp-sheet-drag');
        if (dy <= -45) {
          const suite = suites.get(Number(detail.dataset.slfpSuiteId || 0));
          if (canOpenSuite(suite)) {
            expandDetail(suite);
          }
        } else if (dy >= 45) {
          hideDetail();
        }
      };

      detail.addEventListener('pointerup', endSheetGesture);
      detail.addEventListener('pointercancel', endSheetGesture);
    }

    stage.addEventListener('wheel', event => {
      event.preventDefault();
      setSuppressClick();
      const amount = Math.max(-0.35, Math.min(0.35, -event.deltaY * 0.0018));
      zoomAt(scale * (1 + amount), event.clientX, event.clientY);
    }, { passive: false });

    stage.addEventListener('pointerdown', event => {
      if (event.button && event.button !== 0) return;
      if (!event.target.closest('.slfp-detail')) {
        hideDetail();
      }
      const point = {
        id: event.pointerId,
        clientX: event.clientX,
        clientY: event.clientY,
        startX: event.clientX,
        startY: event.clientY,
      };
      const label = event.target.closest('.slfp-label');
      activePointers.set(event.pointerId, point);
      panState = {
        id: event.pointerId,
        x,
        y,
        startX: event.clientX,
        startY: event.clientY,
        suiteId: label ? Number(label.dataset.slfpSuiteId || 0) : 0,
      };
      gestureMoved = false;
      root.classList.add('is-panning');
      try {
        stage.setPointerCapture(event.pointerId);
      } catch (error) {
        // Some browsers may already have reassigned capture during multi-touch.
      }
      if (activePointers.size >= 2) {
        startPinch();
      }
    });

    stage.addEventListener('pointermove', event => {
      const point = activePointers.get(event.pointerId);
      if (!point) return;
      point.clientX = event.clientX;
      point.clientY = event.clientY;

      if (activePointers.size >= 2) {
        gestureMoved = true;
        setSuppressClick();
        updatePinch();
        return;
      }

      if (!panState || panState.id !== event.pointerId) return;
      const dx = event.clientX - panState.startX;
      const dy = event.clientY - panState.startY;
      if (Math.hypot(dx, dy) > tapSlop) {
        gestureMoved = true;
        setSuppressClick();
      }
      x = panState.x + dx;
      y = panState.y + dy;
      clampPosition();
      applyTransform();
    });

    const endPointer = (event, allowTap = false) => {
      const point = activePointers.get(event.pointerId);
      const wasTap = allowTap && !gestureMoved && panState && panState.id === event.pointerId && panState.suiteId && activePointers.size === 1;
      const suite = wasTap ? suites.get(Number(panState.suiteId)) : null;
      activePointers.delete(event.pointerId);
      if (gestureMoved && point) {
        setSuppressClick();
      }
      if (activePointers.size >= 2) {
        startPinch();
        return;
      }
      pinchState = null;
      const remaining = currentPointers()[0];
      if (remaining) {
        panState = { id: remaining.id, x, y, startX: remaining.clientX, startY: remaining.clientY };
      } else {
        panState = null;
        gestureMoved = false;
        root.classList.remove('is-panning');
      }
      if (suite) {
        showDetail(suite);
        setSuppressClick();
      }
    };

    stage.addEventListener('pointerup', event => endPointer(event, true));
    stage.addEventListener('pointercancel', event => endPointer(event, false));
    stage.addEventListener('lostpointercapture', event => {
      if (activePointers.has(event.pointerId)) {
        endPointer(event, false);
      }
    });

    window.addEventListener('resize', () => {
      clampPosition();
      applyTransform();
    });

    if (data.sync && data.sync.stale) {
      const stale = document.createElement('p');
      stale.className = 'slfp-stale';
      stale.textContent = 'Suite data may be slightly out of date. Please confirm details with leasing.';
      root.append(stale);
    }

    renderLabels();
    resetView();
  });
})();
