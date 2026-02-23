// ================================
// MIAPP Booking - assets/app.js
// ================================

// ---- Shared helpers (global) ----
window.MIAPP = window.MIAPP || {};

(() => {
  const pad2 = (n) => String(n).padStart(2, '0');
  const fmtYMD = (d) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;

  const parseDaysEnabled = (raw) => {
    if (!raw) return [1, 2, 3, 4, 5];
    let arr = String(raw)
      .split(',')
      .map(s => parseInt(String(s).trim(), 10))
      .filter(n => !Number.isNaN(n));
    // acepta 7 como domingo y normaliza a 0 (JS)
    arr = arr.map(n => (n === 7 ? 0 : n));
    return Array.from(new Set(arr));
  };

  const nextEnabledDate = (d, enabledDays) => {
    const x = new Date(d);
    x.setHours(0, 0, 0, 0);
    for (let i = 0; i < 60; i++) { // busca hasta 60 días
      if (enabledDays.includes(x.getDay())) return x;
      x.setDate(x.getDate() + 1);
    }
    return x;
  };

  // Regla de fechas:
  // - min = hoy
  // - si ya pasó la hora fin del día (day_end_hour) -> min = mañana (o próximo día habilitado)
  window.MIAPP.applyBookingRules = (containerEl, fromEl, toEl) => {
    if (!containerEl || !fromEl || !toEl) return;

    const endHour = parseInt(containerEl.dataset.dayEndHour || '18', 10);
    const daysEnabled = parseDaysEnabled(containerEl.dataset.daysEnabled || '1,2,3,4,5');

    const now = new Date();

    let min = new Date(now);
    min.setHours(0, 0, 0, 0);

    if (now.getHours() >= endHour) {
      min.setDate(min.getDate() + 1);
    }

    min = nextEnabledDate(min, daysEnabled);

    const minStr = fmtYMD(min);

    fromEl.min = minStr;
    toEl.min = minStr;

    if (!fromEl.value || fromEl.value < minStr) {
      fromEl.value = minStr;
    }

    // default "to" = +21 días
    const dToDefault = new Date(fromEl.value + 'T00:00:00');
    dToDefault.setDate(dToDefault.getDate() + 21);
    const toDefaultStr = fmtYMD(dToDefault);

    if (!toEl.value || toEl.value < fromEl.value) {
      toEl.value = toDefaultStr;
    }

    toEl.min = fromEl.value;

    fromEl.addEventListener('change', () => {
      if (fromEl.value < minStr) fromEl.value = minStr;
      toEl.min = fromEl.value;
      if (toEl.value < fromEl.value) toEl.value = fromEl.value;
    });

    toEl.addEventListener('change', () => {
      if (toEl.value < minStr) toEl.value = minStr;
      if (toEl.value < fromEl.value) toEl.value = fromEl.value;
    });
  };

  // Modal open/close helpers
  window.MIAPP.openModal = (m) => {
    m.setAttribute('aria-hidden', 'false');
    document.body.classList.add('miapp-modal-open');
  };

  window.MIAPP.closeModal = (m) => {
    m.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('miapp-modal-open');
  };
})();

// -------------------------------
// Root booking UI: [miapp_booking]
// -------------------------------
(() => {
  const root = document.querySelector('.miapp-root');
  if (!root) return;

  const api = root.dataset.api;
  const nonce = root.dataset.nonce;
  const logged = root.dataset.logged === '1';

  const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;

  const fmtCOP = (cents) => {
    const cop = (cents || 0) / 100;
    return new Intl.NumberFormat('es-CO', {
      style: 'currency',
      currency: 'COP',
      maximumFractionDigits: 0,
    }).format(cop);
  };

  root.innerHTML = `
    <div class="miapp-card">
      <h3>Agenda tu cita</h3>

      ${logged ? '' : `
        <div class="miapp-warn">
          Para agendar debes crear una cuenta o iniciar sesión.
          <div style="margin-top:10px">
            <a class="miapp-link" href="/mi-cuenta">Ir a Mi Cuenta</a>
          </div>
        </div>
      `}

      <div class="miapp-row">
        <label>Rango:</label>
        <input type="date" id="miapp-from" />
        <input type="date" id="miapp-to" />
        <button id="miapp-load">Ver disponibilidad</button>
      </div>

      <div id="miapp-services"></div>
      <div id="miapp-slots"></div>
      <div id="miapp-msg"></div>
    </div>
  `;

  const msgEl = root.querySelector('#miapp-msg');

  const fromEl = root.querySelector('#miapp-from');
  const toEl = root.querySelector('#miapp-to');

  // ✅ aplica reglas dinámicas (según settings)
  window.MIAPP.applyBookingRules(root, fromEl, toEl);

  let selectedService = null;
  let selectedSlot = null;

  async function loadServices(intoEl) {
    const r = await fetch(`${api}/services`);
    const j = await r.json();
    const services = j.services || [];

    if (!services.length) {
      intoEl.innerHTML = `<p class="miapp-muted">No hay servicios configurados.</p>`;
      return;
    }

    intoEl.innerHTML = services.map(s => `
      <div class="miapp-appt">
        <label style="display:flex;gap:10px;align-items:flex-start">
          <input type="radio" name="miapp_service" value="${s.id}">
          <div>
            <b>${s.name}</b><br>
            <span class="miapp-muted">${s.description || ''}</span><br>
            <span><b>${fmtCOP(s.price_cents)}</b> — ${s.duration_min} min</span>
          </div>
        </label>
      </div>
    `).join('');

    intoEl.querySelectorAll('input[name="miapp_service"]').forEach(radio => {
      radio.addEventListener('change', () => {
        selectedService = parseInt(radio.value, 10);
      });
    });
  }

  async function loadSlots() {
    msgEl.innerHTML = '';
    const from = fromEl.value;
    const to = toEl.value;
    if (!from || !to) return;

    const serviceId = selectedService;
    if (!serviceId) {
      msgEl.innerHTML = `<div class="miapp-warn">Selecciona un servicio primero.</div>`;
      return;
    }

    const url = `${api}/availability?service_id=${serviceId}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&tz=${encodeURIComponent(tz)}`;
    const r = await fetch(url);
    const j = await r.json();

    const slots = j.slots || [];
    const slotsEl = root.querySelector('#miapp-slots');

    if (!slots.length) {
      slotsEl.innerHTML = `<p class="miapp-muted">No hay horarios disponibles en ese rango.</p>`;
      return;
    }

    slotsEl.innerHTML = slots.map(s => `
      <div class="miapp-appt">
        <label style="display:flex;gap:10px;align-items:center">
          <input type="radio" name="miapp_slot" value="${s.start}">
          <div>
            <b>${new Date(s.start).toLocaleString()}</b>
            <div class="miapp-muted">${s.mode || ''}</div>
          </div>
        </label>
      </div>
    `).join('');

    slotsEl.querySelectorAll('input[name="miapp_slot"]').forEach(radio => {
      radio.addEventListener('change', () => {
        selectedSlot = radio.value;
      });
    });
  }

  // servicios
  loadServices(root.querySelector('#miapp-services'));

  // slots
  root.querySelector('#miapp-load').addEventListener('click', loadSlots);
})();

// -----------------------------------
// Modal booking: [miapp_booking_button]
// -----------------------------------
(() => {
  const modals = document.querySelectorAll('.miapp-modal');
  if (!modals.length) return;

  // abrir/cerrar por delegación
  document.addEventListener('click', (e) => {
    const openBtn = e.target.closest('[data-miapp-open]');
    if (openBtn) {
      const id = openBtn.getAttribute('data-miapp-open');
      const m = document.getElementById(id);
      if (m) window.MIAPP.openModal(m);
    }

    const closeBtn = e.target.closest('[data-miapp-close]');
    if (closeBtn) {
      const m = closeBtn.closest('.miapp-modal');
      if (m) window.MIAPP.closeModal(m);
    }
  });

  modals.forEach(m => {
    const api = m.dataset.api;
    const nonce = m.dataset.nonce;
    const logged = m.dataset.logged === '1';
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;

    const servicesEl = m.querySelector('#miapp-services');
    const slotsEl = m.querySelector('#miapp-slots');
    const msgEl = m.querySelector('#miapp-msg');

    const fromEl = m.querySelector('#miapp-from');
    const toEl = m.querySelector('#miapp-to');

    // ✅ IMPORTANTE: ahora funciona, porque applyBookingRules es global
    window.MIAPP.applyBookingRules(m, fromEl, toEl);

    let step = 1;
    let selectedService = null;
    let selectedSlot = null;

    const steps = Array.from(m.querySelectorAll('.miapp-step'));

    const showStep = (n) => {
      step = n;
      steps.forEach(s => {
        const sn = parseInt(s.dataset.step, 10);
        s.hidden = sn !== step;
      });
    };

    const fmtCOP = (cents) => {
      const cop = (cents || 0) / 100;
      return new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: 'COP',
        maximumFractionDigits: 0,
      }).format(cop);
    };

    async function loadServices() {
      msgEl.innerHTML = '';
      servicesEl.innerHTML = `<p class="miapp-muted">Cargando servicios…</p>`;

      const r = await fetch(`${api}/services`);
      const j = await r.json();
      const services = j.services || [];

      if (!services.length) {
        servicesEl.innerHTML = `<p class="miapp-muted">No hay servicios configurados.</p>`;
        return;
      }

      servicesEl.innerHTML = services.map(s => `
        <div class="miapp-appt">
          <label style="display:flex;gap:10px;align-items:flex-start">
            <input type="radio" name="miapp_service_modal" value="${s.id}">
            <div>
              <b>${s.name}</b><br>
              <span class="miapp-muted">${s.description || ''}</span><br>
              <span><b>${fmtCOP(s.price_cents)}</b> — ${s.duration_min} min</span>
            </div>
          </label>
        </div>
      `).join('');

      servicesEl.querySelectorAll('input[name="miapp_service_modal"]').forEach(radio => {
        radio.addEventListener('change', () => {
          selectedService = parseInt(radio.value, 10);
          const btn = m.querySelector('[data-next]');
          if (btn) btn.disabled = false;
        });
      });

      const btn = m.querySelector('[data-next]');
      if (btn) btn.disabled = true;
    }

    async function loadSlots() {
      msgEl.innerHTML = '';
      slotsEl.innerHTML = '';

      if (!selectedService) {
        msgEl.innerHTML = `<div class="miapp-warn">Selecciona un servicio.</div>`;
        return;
      }

      const from = fromEl.value;
      const to = toEl.value;

      const url = `${api}/availability?service_id=${selectedService}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&tz=${encodeURIComponent(tz)}`;
      const r = await fetch(url);
      const j = await r.json();

      const slots = j.slots || [];
      if (!slots.length) {
        slotsEl.innerHTML = `<p class="miapp-muted">No hay horarios disponibles en ese rango.</p>`;
        return;
      }

      slotsEl.innerHTML = slots.map(s => `
        <div class="miapp-appt">
          <label style="display:flex;gap:10px;align-items:center">
            <input type="radio" name="miapp_slot_modal" value="${s.start}">
            <div>
              <b>${new Date(s.start).toLocaleString()}</b>
              <div class="miapp-muted">${s.mode || ''}</div>
            </div>
          </label>
        </div>
      `).join('');

      const btnNext = m.querySelector('#miapp-to-auth');
      if (btnNext) btnNext.disabled = true;

      slotsEl.querySelectorAll('input[name="miapp_slot_modal"]').forEach(radio => {
        radio.addEventListener('change', () => {
          selectedSlot = radio.value;
          if (btnNext) btnNext.disabled = false;
        });
      });
    }

    // Navegación (next/back)
    m.querySelectorAll('[data-next]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (step === 1) {
          if (!selectedService) {
            msgEl.innerHTML = `<div class="miapp-warn">Selecciona un servicio.</div>`;
            return;
          }
          showStep(2);
        } else if (step === 2) {
          if (!selectedSlot) {
            msgEl.innerHTML = `<div class="miapp-warn">Selecciona un horario.</div>`;
            return;
          }
          showStep(3);
        }
      });
    });

    m.querySelectorAll('[data-back]').forEach(btn => {
      btn.addEventListener('click', () => {
        if (step > 1) showStep(step - 1);
      });
    });

    // Cargar disponibilidad
    const loadBtn = m.querySelector('#miapp-load');
    if (loadBtn) loadBtn.addEventListener('click', loadSlots);

    // Confirmar (book o reschedule)
    const confirmBtn = m.querySelector('#miapp-confirm');
    if (confirmBtn) {
      confirmBtn.addEventListener('click', async () => {
        msgEl.innerHTML = '';

        if (!selectedService || !selectedSlot) {
          msgEl.innerHTML = `<div class="miapp-warn">Faltan datos: servicio u horario.</div>`;
          return;
        }

        if (!logged) {
          msgEl.innerHTML = `<div class="miapp-warn">Debes iniciar sesión para confirmar.</div>`;
          return;
        }

        // slot start viene en ISO. El backend ya calcula end según duración (según tu implementación),
        // pero si tu API requiere end, aquí podrías enviarlo.
        const start = selectedSlot;

        const resId = window.__MIAPP_RESCHEDULE_ID__;
        const endpoint = resId ? `${api}/me/appointments/${resId}/reschedule` : `${api}/book`;

        const payload = resId
          ? { start, tz }
          : { service_id: selectedService, start, tz };

        const r = await fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': nonce
          },
          body: JSON.stringify(payload)
        });

        const j = await r.json();
        if (!r.ok) {
          msgEl.innerHTML = `<div class="miapp-warn">${j.error || 'No se pudo confirmar.'}</div>`;
          return;
        }

        window.__MIAPP_RESCHEDULE_ID__ = null;
        showStep(4);
      });
    }

    // Auto-open if requested
    if (m.dataset.openOnLoad === '1') {
      window.MIAPP.openModal(m);
    }

    // init
    showStep(1);
    loadServices();
  });
})();

// -------------------------------
// Patient panel: [miapp_patient]
// -------------------------------
(() => {
  const el = document.querySelector('.miapp-patient');
  if (!el) return;

  const api = el.dataset.api;
  const nonce = el.dataset.nonce;

  const fmtCOP = (cents) => {
    const cop = (cents || 0) / 100;
    return new Intl.NumberFormat('es-CO', {
      style: 'currency',
      currency: 'COP',
      maximumFractionDigits: 0,
    }).format(cop);
  };

  async function refresh() {
    el.innerHTML = `<div class="miapp-card"><h3>Mis citas</h3><p class="miapp-muted">Cargando…</p></div>`;

    const r = await fetch(`${api}/my-appointments`, {
      headers: { 'X-WP-Nonce': nonce }
    });
    const j = await r.json();

    if (!r.ok) {
      el.innerHTML = `<div class="miapp-card"><h3>Mis citas</h3><div class="miapp-warn">${j.error || 'No se pudo cargar.'}</div></div>`;
      return;
    }

    const appts = j.appointments || [];

    el.innerHTML = `
      <div class="miapp-card">
        <h3>Mis citas</h3>
        ${appts.length ? appts.map(x => {
          const d = new Date(x.start_at + 'Z'); // guardado UTC
          return `
            <div class="miapp-appt" data-appt-id="${x.id}">
              <div><b>${x.service_name}</b> — ${fmtCOP(x.service_price_cents)} ${x.session_number ? `(Sesión #${x.session_number})` : ''}</div>
              <div class="miapp-muted">${d.toLocaleString()}</div>
              ${x.meet ? `<div><a class="miapp-link" href="${x.meet}" target="_blank" rel="noopener">Entrar a la sesión</a></div>` : ''}
              <div class="miapp-muted">Estado: ${x.status}</div>
              <div class="miapp-actions">
                <button class="miapp-btn miapp-btn--outline" data-miapp-cancel>Cancelar</button>
                <button class="miapp-btn miapp-btn--primary" data-miapp-reschedule>Reagendar</button>
              </div>
            </div>
          `;
        }).join('') : `<p class="miapp-muted">Aún no tienes citas.</p>`}
        <div id="miapp-msg"></div>
      </div>
    `;

    // Cancel
    el.querySelectorAll('[data-miapp-cancel]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const card = btn.closest('[data-appt-id]');
        const id = card.getAttribute('data-appt-id');

        if (!confirm('¿Seguro que quieres cancelar esta cita?')) return;

        const rr = await fetch(`${api}/me/appointments/${id}/cancel`, {
          method: 'POST',
          headers: { 'X-WP-Nonce': nonce }
        });
        const jj = await rr.json();

        if (!rr.ok) {
          alert(jj.error || 'No se pudo cancelar.');
          return;
        }
        refresh();
      });
    });

    // Reschedule
    el.querySelectorAll('[data-miapp-reschedule]').forEach(btn => {
      btn.addEventListener('click', () => {
        const card = btn.closest('[data-appt-id]');
        const id = card.getAttribute('data-appt-id');

        window.__MIAPP_RESCHEDULE_ID__ = id;

        // Requiere que en esta misma página exista un modal de booking
        // (agrega [miapp_booking_button] en la página del panel)
        const modal = document.querySelector('.miapp-modal');
        if (!modal) {
          alert('No encontré el modal de agendamiento en esta página. Agrega el shortcode [miapp_booking_button].');
          return;
        }
        window.MIAPP.openModal(modal);
      });
    });
  }

  refresh();
})();
