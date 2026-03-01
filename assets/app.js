(function(){
  function qs(sel, root){ return (root||document).querySelector(sel); }
  function qsa(sel, root){ return Array.from((root||document).querySelectorAll(sel)); }

  function fmtMoneyCOP(cents){
    try{
      return new Intl.NumberFormat('es-CO', {style:'currency', currency:'COP', maximumFractionDigits:0}).format((cents||0)/100);
    }catch(e){
      return '$' + Math.round((cents||0)/100).toString();
    }
  }


function renderSummary(modal, service, slot){
  const box = qs('#miapp-summary', modal);
  if(!box) return;
  const serviceName = service ? service.name : '—';
  const servicePrice = service ? fmtMoneyCOP(service.price_cents) : '—';
  const serviceDur = service ? (parseInt(service.duration_min||60,10) + ' min') : '—';
  let when = '—';
  if(slot && slot.start){
    try{
      const d = new Date(slot.start);
      when = d.toLocaleString('es-CO', {weekday:'long', year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit'});
    }catch(e){ when = slot.start; }
  }
  const mode = slot && slot.mode ? slot.mode : (slot && slot.is_virtual ? 'VIRTUAL' : '—');

  box.innerHTML = `
    <div class="miapp-summary__row"><div class="miapp-summary__label">Servicio</div><div class="miapp-summary__value">${escapeHtml(serviceName)}</div></div>
    <div class="miapp-summary__row"><div class="miapp-summary__label">Valor</div><div class="miapp-summary__value">${escapeHtml(servicePrice)}</div></div>
    <div class="miapp-summary__row"><div class="miapp-summary__label">Duración</div><div class="miapp-summary__value">${escapeHtml(serviceDur)}</div></div>
    <div class="miapp-summary__row"><div class="miapp-summary__label">Fecha y hora</div><div class="miapp-summary__value">${escapeHtml(when)}</div></div>
    <div class="miapp-summary__row"><div class="miapp-summary__label">Modalidad</div><div class="miapp-summary__value">${escapeHtml(mode)}</div></div>
    ${service && service.indications ? `<div class="miapp-summary__row"><div class="miapp-summary__label">Indicaciones</div><div class="miapp-summary__value">${escapeHtml(service.indications)}</div></div>` : ``}
  `;
}

  async function apiGet(base, path, nonce){
    const headers = {};
    if(nonce){ headers['X-WP-Nonce']=nonce; }
    const r = await fetch(base + path, { credentials: 'same-origin', headers });
const j = await r.json().catch(()=>({}));
    if(!r.ok) throw new Error(j.error || 'Error');
    return j;
  }

  async function apiPost(base, path, nonce, body, method){
    const r = await fetch(base + path, {
      method: (method||'POST'),
      credentials:'same-origin',
      headers:{
        'Content-Type':'application/json',
        'X-WP-Nonce': nonce
      },
      body: JSON.stringify(body||{})
    });
    const j = await r.json().catch(()=>({}));
    if(!r.ok) throw new Error(j.error || 'Error');
    return j;
  }

  function setMsg(modal, text, kind){
    const box = qs('#miapp-msg', modal);
    if(!box) return;
    box.className = 'miapp-msg ' + (kind ? ('miapp-msg--'+kind) : '');
    box.textContent = text || '';
  }

  function openModal(modal){
    modal.setAttribute('aria-hidden','false');
    document.body.classList.add('miapp-modal-open');
  }
  function closeModal(modal){
    modal.setAttribute('aria-hidden','true');
    document.body.classList.remove('miapp-modal-open');
  }

  function showStep(modal, n){
    qsa('.miapp-step', modal).forEach(st=>{
      const sn = parseInt(st.getAttribute('data-step'),10);
      st.hidden = sn !== n;
    });
  }

  function initModal(modal){
    if(modal.dataset.miappInit === '1') return;
    modal.dataset.miappInit = '1';

    const apiBase = modal.getAttribute('data-api') || '';
    const nonce = modal.getAttribute('data-nonce') || '';
    const logged = modal.getAttribute('data-logged') === '1';
    const dayEnd = parseInt(modal.getAttribute('data-day-end-hour') || modal.getAttribute('data-day-end') || '18', 10);

    const providersBox = qs('#miapp-providers', modal);
    const servicesBox = qs('#miapp-services', modal);
    let providers = [];
    let selectedProviderId = 0;
    const slotsBox = qs('#miapp-slots', modal);
    const fromEl = qs('#miapp-from', modal);
    const toEl = qs('#miapp-to', modal);
    const loadBtn = qs('#miapp-load', modal);

    const calGrid = qs('#miapp-cal-grid', modal);
    const monthLabel = qs('#miapp-month-label', modal);
    const prevMonthBtn = qs('#miapp-prev-month', modal);
    const nextMonthBtn = qs('#miapp-next-month', modal);
    const toAuthBtn = qs('#miapp-to-auth', modal);
    const confirmBtn = qs('#miapp-confirm', modal);
    const step1NextBtn = qs('.miapp-step[data-step="1"] [data-next]', modal);

    let services = [];
    let selectedService = null;
    let selectedSlot = null;

    // fecha mínima: hoy o mañana si ya pasó la hora final
    const now = new Date();
    const minDate = new Date(now);
    if(now.getHours() >= dayEnd) minDate.setDate(minDate.getDate() + 1);
    const minIso = minDate.toISOString().slice(0,10);

    // Mes visible del calendario
    let viewMonth = new Date(minDate.getFullYear(), minDate.getMonth(), 1);
    let selectedDayIso = null;

    
function renderServices(){
  if(!servicesBox) return;
  servicesBox.innerHTML = '';

  // For reschedule: force preselect service
  const forceServiceId = parseInt((modal && modal.dataset && modal.dataset.forceServiceId) ? modal.dataset.forceServiceId : '0', 10) || 0;
  if(forceServiceId && (!selectedService || selectedService.id !== forceServiceId)){
    const found = services.find(x=> parseInt(x.id,10)===forceServiceId);
    if(found) selectedService = found;
  }

  if(!services.length){
    servicesBox.innerHTML = '<div class="miapp-muted">No hay servicios publicados aún.</div>';
    if(step1NextBtn) step1NextBtn.disabled = true;
    return;
  }

  const wrap = document.createElement('div');
  wrap.className = 'miapp-services';

  services.forEach(s=>{
    const card = document.createElement('button');
    card.type = 'button';
    card.className = 'miapp-service' + (selectedService && selectedService.id === s.id ? ' is-selected' : '');

    const desc = (s.description || '').trim();
    card.innerHTML = `
      <div class="miapp-service__left">
        <div class="miapp-service__name">${escapeHtml(s.name)}</div>
        ${desc ? `<div class="miapp-service__desc">${escapeHtml(desc)}</div>` : ``}
      </div>
      <div class="miapp-service__meta">
        ${fmtMoneyCOP(s.price_cents)}
        <span class="miapp-service__dur">${parseInt(s.duration_min||60,10)} min</span>
      </div>
    `;

    card.addEventListener('click', ()=>{
      selectedService = s;
      selectedSlot = null;
      if(step1NextBtn) step1NextBtn.disabled = false;
      if(toAuthBtn) toAuthBtn.disabled = true;
      if(slotsBox) slotsBox.innerHTML = '';
      setMsg(modal,'','');
      renderServices();
    });

    wrap.appendChild(card);
  });

  servicesBox.appendChild(wrap);
  if(step1NextBtn) step1NextBtn.disabled = !selectedService;
}


    async function loadProviders(){
      if(!providersBox){
        // if modal template doesn't include providers box, keep provider 0
        return;
      }
      try{
        const j = await apiGet(apiBase, '/providers');
        providers = j.providers || [];
        providersBox.innerHTML = '';
        if(!providers.length){
          providersBox.innerHTML = '<div class="miapp-muted">No hay profesionales activos.</div>';
          return;
        }
        // auto-select if only one
        if(providers.length === 1){
          selectedProviderId = parseInt(providers[0].id,10) || 0;
        }
        const wrap = document.createElement('div');
        wrap.className = 'miapp-list';
        providers.forEach(p=>{
          const item = document.createElement('button');
          item.type='button';
          item.className='miapp-list__item';
          item.setAttribute('data-provider', String(p.id));
          item.innerHTML = `<div><strong>${escapeHtml(p.name||'Profesional')}</strong>${p.specialty?`<div class="miapp-muted">${escapeHtml(p.specialty)}</div>`:''}</div>`;
          if(String(p.id)===String(selectedProviderId)) item.classList.add('is-selected');
          item.addEventListener('click', ()=>{
            selectedProviderId = parseInt(p.id,10)||0;
            qsa('.miapp-list__item', wrap).forEach(b=>b.classList.remove('is-selected'));
            item.classList.add('is-selected');
            // reload services when provider changes
            loadServices();
          });
          wrap.appendChild(item);
        });
        providersBox.appendChild(wrap);
      }catch(e){
        providersBox.innerHTML = '<div class="miapp-muted">No pude cargar profesionales.</div>';
      }
    }

async function loadServices(){
      try{
        setMsg(modal,'Cargando servicios…','info');
        const q = selectedProviderId ? ('?provider_id=' + encodeURIComponent(String(selectedProviderId))) : '';
        const j = await apiGet(apiBase, '/services' + q);
        services = j.services || [];
        setMsg(modal,'','');
        renderServices();
      }catch(e){
        setMsg(modal, e.message || 'No pude cargar los servicios', 'error');
      }
    }

    function renderSlots(slots){
      if(!slotsBox) return;
      slotsBox.innerHTML = '';
      if(!slots || !slots.length){
        slotsBox.innerHTML = '<div class="miapp-muted">No hay cupos en este día.</div>';
        return;
      }

      const list = document.createElement('div');
      list.className = 'miapp-slot-list';

      slots.forEach(s=>{
        const item = document.createElement('div');
        item.className = 'miapp-slot-item' + (selectedSlot && selectedSlot.start === s.start ? ' is-active' : '');
        const d = new Date(s.start);
        item.innerHTML = `
          <div class="miapp-slot-item__time">${d.toLocaleString('es-CO',{hour:'2-digit',minute:'2-digit'})}</div>
          <div class="miapp-slot-item__meta">${d.toLocaleDateString('es-CO',{weekday:'long', year:'numeric', month:'short', day:'numeric'})}</div>
        `;
        item.addEventListener('click', ()=>{
          selectedSlot = s;
          toAuthBtn && (toAuthBtn.disabled = false);
          renderSlots(slots);
        });
        list.appendChild(item);
      });

      slotsBox.appendChild(list);
    }

    async function loadAvailability(){
      if(!selectedService){ setMsg(modal,'Elige un servicio primero.', 'error'); return; }

      const from = fromEl ? fromEl.value : '';
      const to = toEl ? toEl.value : '';
      if(!from || !to){ setMsg(modal,'Selecciona un día.', 'error'); return; }

      const fromIso = from + 'T00:00:00';
      const toIso = to + 'T23:59:59';

      try{
        setMsg(modal,'Buscando disponibilidad…','info');
        const j = await apiGet(apiBase, `/availability?serviceId=${encodeURIComponent(selectedService.id)}&provider_id=${encodeURIComponent(String(selectedProviderId||''))}&from=${encodeURIComponent(fromIso)}&to=${encodeURIComponent(toIso)}&tz=${encodeURIComponent(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC')}`);
        setMsg(modal,'','');
        const slots = j.slots || [];
        selectedSlot = null;
        toAuthBtn && (toAuthBtn.disabled = true);
        renderSlots(slots);
      }catch(e){
        setMsg(modal, e.message || 'No pude cargar la disponibilidad', 'error');
      }
    }

    function monthKey(d){
      const y = d.getFullYear();
      const m = String(d.getMonth()+1).padStart(2,'0');
      return `${y}-${m}`;
    }

    function monthLabelEs(d){
      try{
        return d.toLocaleDateString('es-CO', {month:'long', year:'numeric'});
      }catch(e){
        return monthKey(d);
      }
    }

    function startOfCalendarGrid(monthDate){
      // Lunes como inicio
      const first = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1);
      const dow = (first.getDay() + 6) % 7; // 0=Lunes
      const start = new Date(first);
      start.setDate(first.getDate() - dow);
      return start;
    }

    async function loadMonthDays(){
      if(!selectedService) return;
      const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
      const m = monthKey(viewMonth);
      const j = await apiGet(apiBase, `/availability/days?serviceId=${encodeURIComponent(selectedService.id)}&provider_id=${encodeURIComponent(String(selectedProviderId||''))}&month=${encodeURIComponent(m)}&tz=${encodeURIComponent(tz)}`);
      const map = {};
      (j.days || []).forEach(d=>{ map[d.date] = d; });
      return map;
    }

    function renderCalendar(daysMap){
      if(!calGrid) return;
      calGrid.innerHTML = '';
      if(monthLabel) monthLabel.textContent = monthLabelEs(viewMonth);

      const gridStart = startOfCalendarGrid(viewMonth);
      const todayMonth = viewMonth.getMonth();
      for(let i=0;i<42;i++){
        const d = new Date(gridStart);
        d.setDate(gridStart.getDate() + i);
        const iso = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');

        const info = daysMap && daysMap[iso] ? daysMap[iso] : null;
        const inMonth = d.getMonth() === todayMonth;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'miapp-day';

        if(!inMonth){
          btn.classList.add('miapp-day--disabled');
          btn.disabled = true;
        }

        // bloquear pasado / antes del min
        if(iso < minIso){
          btn.classList.add('miapp-day--disabled');
          btn.disabled = true;
        }

        // reglas por días habilitados
        if(info && info.enabled === false){
          btn.classList.add('miapp-day--disabled');
          btn.disabled = true;
        }

        // color por remaining
        let dotClass = 'miapp-dot--none';
        let meta = 'Sin cupos';
        if(info && info.enabled !== false){
          const rem = parseInt(info.remaining||0,10);
          const total = parseInt(info.total||0,10);
          if(rem <= 0){ dotClass = 'miapp-dot--none'; meta = 'Sin cupos'; }
          else if(rem <= 2){ dotClass = 'miapp-dot--low'; meta = `${rem}/${total}`; }
          else { dotClass = 'miapp-dot--good'; meta = `${rem}/${total}`; }
          if(rem <= 0) btn.disabled = true;
        } else {
          // sin info: lo tratamos como sin cupos
          btn.disabled = true;
          btn.classList.add('miapp-day--disabled');
        }

        if(selectedDayIso === iso) btn.classList.add('miapp-day--selected');

        btn.innerHTML = `
          <div class="miapp-day__num">${d.getDate()}</div>
          <div class="miapp-day__meta"><i class="miapp-dot ${dotClass}"></i><span>${meta}</span></div>
        `;

        btn.addEventListener('click', async ()=>{
          if(btn.disabled) return;
          selectedDayIso = iso;
          // set hidden inputs
          if(fromEl) fromEl.value = iso;
          if(toEl) toEl.value = iso;
          // UI
          qsa('.miapp-day', calGrid).forEach(x=>x.classList.remove('miapp-day--selected'));
          btn.classList.add('miapp-day--selected');
          await loadAvailability();
        });

        calGrid.appendChild(btn);
      }
    }

    async function refreshCalendar(){
      if(!selectedService){
        renderCalendar({});
        return;
      }
      try{
        setMsg(modal,'Cargando calendario…','info');
        const daysMap = await loadMonthDays();
        setMsg(modal,'','');
        renderCalendar(daysMap || {});
      }catch(e){
        setMsg(modal, e.message || 'No pude cargar el calendario', 'error');
        renderCalendar({});
      }
    }

    async function confirmBooking(){
      if(!logged){
        setMsg(modal,'Debes iniciar sesión para confirmar.','error');
        return;
      }
      if(!selectedService || !selectedSlot){
        setMsg(modal,'Elige servicio y horario.','error');
        return;
      }
      try{
        confirmBtn && (confirmBtn.disabled = true);
        setMsg(modal,'Confirmando…','info');
        await apiPost(apiBase, '/book', nonce, {
          serviceId: selectedService.id,
          provider_id: selectedProviderId,
          start: selectedSlot.start,
          end: selectedSlot.end,
          tz: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
          mode: 'VIRTUAL'
        });
        setMsg(modal,'','');
        showStep(modal, 4);
      }catch(e){
        setMsg(modal, e.message || 'No pude confirmar', 'error');
      }finally{
        confirmBtn && (confirmBtn.disabled = !logged);
      }
    }

    // open/close bindings
    qsa('[data-miapp-close]', modal).forEach(el=>el.addEventListener('click', ()=>closeModal(modal)));

    // step navigation
    qsa('[data-next]', modal).forEach(btn=>btn.addEventListener('click', ()=>{
      const current = qsa('.miapp-step', modal).find(st=>!st.hidden);
      const curN = current ? parseInt(current.getAttribute('data-step'),10) : 1;
      if(curN === 1){
        if(!selectedService){ setMsg(modal,'Selecciona un servicio.','error'); return; }
        showStep(modal, 2);
        refreshCalendar();
        return;
      }
      if(curN === 2){
        if(!selectedSlot){ setMsg(modal,'Selecciona un horario.','error'); return; }
        showStep(modal, 3);
        renderSummary(modal, selectedService, selectedSlot);
        confirmBtn && (confirmBtn.disabled = !logged);
        if(!logged){
          // muestra login/registro (simple)
          const auth = qs('#miapp-auth', modal);
          const tpl = qs('#miapp-login-template', modal);
          if(auth && tpl) auth.innerHTML = tpl.innerHTML;
        }
        return;
      }
    }));

    qsa('[data-back]', modal).forEach(btn=>btn.addEventListener('click', ()=>{
      const current = qsa('.miapp-step', modal).find(st=>!st.hidden);
      const curN = current ? parseInt(current.getAttribute('data-step'),10) : 1;
      if(curN === 2) showStep(modal, 1);
      if(curN === 3) showStep(modal, 2);
    }));

    if(loadBtn) loadBtn.addEventListener('click', loadAvailability);
    if(confirmBtn) confirmBtn.addEventListener('click', confirmBooking);

    if(prevMonthBtn) prevMonthBtn.addEventListener('click', ()=>{
      viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth()-1, 1);
      refreshCalendar();
    });
    if(nextMonthBtn) nextMonthBtn.addEventListener('click', ()=>{
      viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth()+1, 1);
      refreshCalendar();
    });

    // initial
    showStep(modal, 1);
    loadProviders().then(()=>loadServices());

    const openOnLoad = modal.getAttribute('data-open-on-load') === '1';
    if(openOnLoad) openModal(modal);
  }

  function escapeHtml(str){
    return String(str||'').replace(/[&<>"]/g, s=>({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'
    }[s]));
  }


  // ===== Dashboards (Paciente y Mia) =====
  function isoDateLocalFromUtc(utcStr){
    // utcStr: 'YYYY-mm-dd HH:MM:SS' in UTC
    const d = new Date(utcStr.replace(' ', 'T') + 'Z');
    return d.toISOString().slice(0,10);
  }

  function renderMiniCalendar(root, viewMonth, dayInfoMap, minIso, onSelectDay){
    const grid = qs('.miapp-mini-cal__grid', root);
    const label = qs('.miapp-mini-cal__label', root);
    if(!grid) return;
    grid.innerHTML = '';
    if(label){
      try{ label.textContent = viewMonth.toLocaleDateString('es-CO',{month:'long',year:'numeric'}); }
      catch(e){ label.textContent = viewMonth.getFullYear()+'-'+String(viewMonth.getMonth()+1).padStart(2,'0'); }
    }

    // start monday grid
    const first = new Date(viewMonth.getFullYear(), viewMonth.getMonth(), 1);
    const dow = (first.getDay()+6)%7;
    const start = new Date(first); start.setDate(first.getDate()-dow);

    const monthIdx = viewMonth.getMonth();

    for(let i=0;i<42;i++){
      const d = new Date(start); d.setDate(start.getDate()+i);
      const iso = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
      const inMonth = d.getMonth()===monthIdx;

      const info = dayInfoMap[iso] || {count:0};
      let dotClass = 'miapp-dot--none';
      if(info.count>=3) dotClass='miapp-dot--good';
      else if(info.count>=1) dotClass='miapp-dot--low';

      const btn = document.createElement('button');
      btn.type='button';
      btn.className='miapp-day';
      if(info.count>=1) btn.classList.add('miapp-day--has-appt');
      if(!inMonth || iso < minIso){
        btn.classList.add('miapp-day--disabled');
        btn.disabled=true;
      }
      btn.innerHTML = `<div class="miapp-day__num">${d.getDate()}</div>
        <div class="miapp-day__meta"><i class="miapp-dot ${dotClass}"></i><span>${info.count||0}</span></div>`;
      btn.addEventListener('click', ()=>{ if(btn.disabled) return; onSelectDay(iso); });
      grid.appendChild(btn);
    }
  }

  function ensureDashboardShell(root, title){
    if(root.dataset.miappDash === '1') return;
    root.dataset.miappDash = '1';
    root.innerHTML = `
      <div class="miapp-card">
        <div class="miapp-dash-header">
          <h3>${escapeHtml(title)}</h3>
        </div>

        <div class="miapp-kpis" hidden></div>

        <div class="miapp-dash-tabs" hidden>
          <button type="button" class="miapp-tab miapp-tab--active" data-tab="agenda">Agenda</button>
          <button type="button" class="miapp-tab" data-tab="services">Servicios</button>
        </div>

        <div class="miapp-dash-grid" data-pane="agenda">
          <div class="miapp-mini-cal">
            <div class="miapp-mini-cal__header">
              <button type="button" class="miapp-mini-cal__prev">‹</button>
              <div class="miapp-mini-cal__label"></div>
              <button type="button" class="miapp-mini-cal__next">›</button>
            </div>
            <div class="miapp-mini-cal__week"><div>L</div><div>M</div><div>X</div><div>J</div><div>V</div><div>S</div><div>D</div></div>
            <div class="miapp-mini-cal__grid"></div>
            <div class="miapp-muted miapp-mini-cal__legend">● verde = 3+ citas · ● amarillo = 1-2 · ● rojo = 0</div>
          </div>
          <div class="miapp-dash-main">
            <div class="miapp-day-title"></div>
            <div class="miapp-day-list"></div>
          </div>
        </div>

        <div class="miapp-dash-pane" data-pane="services" hidden>
          <div class="miapp-services-panel">
            <div class="miapp-services-panel__header">
              <h4>Mis servicios</h4>
              <button type="button" class="miapp-btn miapp-btn--primary" data-miapp-add-service>Nuevo servicio</button>
            </div>
            <div class="miapp-services-panel__list"></div>
          </div>
        </div>

      </div>
    `;
  }

  async function initPatientDashboard(root){
    ensureDashboardShell(root, 'Tus citas');
    const apiBase = root.getAttribute('data-api') || '';
    const nonce = root.getAttribute('data-nonce') || '';
    async function refreshServices(){
      const list = root.querySelector('.miapp-services-panel__list');
      if(!list) return;
      // Only load when pane is visible or when requested
      const j = await apiGet(apiBase, '/practitioner/services', nonce);
      const services = (j.services||[]);
      if(!services.length){
        list.innerHTML = '<div class="miapp-muted">Aún no tienes servicios. Crea uno para habilitar el agendamiento.</div>';
      }else{
        list.innerHTML = '';
        services.forEach(s=>{
          const row = document.createElement('div');
          row.className = 'miapp-service-row';
          row.innerHTML = `
            <div class="miapp-service-row__main">
              <strong>${escapeHtml(s.name||'Servicio')}</strong>
              <div class="miapp-muted">${fmtMoneyCOP(s.price_cents)} · ${escapeHtml(String(s.duration_min||60))} min · buffer ${escapeHtml(String(s.buffer_min||0))} min</div>
            </div>
            <div class="miapp-service-row__actions">
              <button type="button" class="miapp-btn miapp-btn--outline" data-edit="${s.id}">Editar</button>
              <button type="button" class="miapp-btn miapp-btn--outline" data-del="${s.id}">Desactivar</button>
            </div>
          `;
          list.appendChild(row);
        });
      }

      // handlers
      qsa('[data-edit]', list).forEach(btn=>{
        btn.addEventListener('click', async ()=>{
          const id = btn.getAttribute('data-edit');
          const svc = (j.services||[]).find(x=>String(x.id)===String(id));
          if(!svc) return;
          const name = prompt('Nombre del servicio', svc.name||'');
          if(name===null) return;
          const price = prompt('Precio COP (solo número)', String(Math.round((svc.price_cents||0)/100)));
          if(price===null) return;
          const dur = prompt('Duración (min)', String(svc.duration_min||60));
          if(dur===null) return;
          const buf = prompt('Buffer (min)', String(svc.buffer_min||0));
          if(buf===null) return;
          const modes = prompt('Modalidades (VIRTUAL,PRESENTIAL)', (svc.modes||[]).join(',') || 'VIRTUAL,PRESENTIAL');
          if(modes===null) return;
          const indications = prompt('Indicaciones (se verán en el resumen)', svc.indications||'') ?? svc.indications;
          const payload = {name, price_cop: parseInt(price,10)||0, duration_min: parseInt(dur,10)||60, buffer_min: parseInt(buf,10)||0, modes, indications};
          await apiPost(apiBase, `/practitioner/services/${encodeURIComponent(id)}`, nonce, payload, 'PUT');
          await refreshServices();
        });
      });
      qsa('[data-del]', list).forEach(btn=>{
        btn.addEventListener('click', async ()=>{
          const id = btn.getAttribute('data-del');
          if(!confirm('¿Desactivar este servicio?')) return;
          await apiPost(apiBase, `/practitioner/services/${encodeURIComponent(id)}`, nonce, {}, 'DELETE');
          await refreshServices();
        });
      });
    }

    // Add new service
    const addBtn = root.querySelector('[data-miapp-add-service]');
    if(addBtn){
      addBtn.addEventListener('click', async ()=>{
        const name = prompt('Nombre del servicio');
        if(!name) return;
        const price = prompt('Precio COP (solo número)','0');
        if(price===null) return;
        const dur = prompt('Duración (min)','60');
        if(dur===null) return;
        const buf = prompt('Buffer (min)','10');
        if(buf===null) return;
        const modes = prompt('Modalidades (VIRTUAL,PRESENTIAL)','VIRTUAL,PRESENTIAL');
        if(modes===null) return;
        const indications = prompt('Indicaciones (se verán en el resumen)','') || '';
        const payload = {name, price_cop: parseInt(price,10)||0, duration_min: parseInt(dur,10)||60, buffer_min: parseInt(buf,10)||0, modes, indications};
        await apiPost(apiBase, '/practitioner/services', nonce, payload, 'POST');
        await refreshServices();
      });
    }


    let viewMonth = new Date(); viewMonth.setDate(1);
    const now = new Date(); const minIso = now.toISOString().slice(0,10);

    const listBox = qs('.miapp-day-list', root);
    const dayTitle = qs('.miapp-day-title', root);

    async function loadAll(){
      const j = await apiGet(apiBase, '/me/appointments', nonce);
      return j.appointments || [];
    }

    function buildDayMap(appts){
      const map = {};
      appts.forEach(a=>{
        const day = isoDateLocalFromUtc(a.start_at);
        map[day] = map[day] || {count:0, items:[]};
        map[day].count += 1;
        map[day].items.push(a);
      });
      return map;
    }

    function renderDay(apptsDay, dayIso){
      if(dayTitle) dayTitle.textContent = dayIso;
      if(!listBox) return;
      listBox.innerHTML = '';
      const items = (apptsDay && apptsDay.items) ? apptsDay.items : [];
      if(!items.length){
        listBox.innerHTML = '<div class="miapp-muted">No tienes citas ese día.</div>';
        return;
      }
      items.sort((a,b)=> (a.start_at>b.start_at?1:-1));
      items.forEach(a=>{
        const d = new Date(a.start_at.replace(' ','T')+'Z');
        const row = document.createElement('div');
        row.className = 'miapp-appt';
        row.innerHTML = `
          <div class="miapp-appt__top">
            <strong>${d.toLocaleString('es-CO',{hour:'2-digit',minute:'2-digit'})}</strong>
            <span class="miapp-badge">${escapeHtml(a.status||'')}</span>
          </div>
          <div class="miapp-appt__meta">${escapeHtml(a.service_name||('Servicio #' + a.service_id))}</div>
          <div class="miapp-appt__actions">
            <button type="button" class="miapp-btn miapp-btn--outline" data-cancel="${a.id}">Cancelar</button>
          </div>
        `;
        listBox.appendChild(row);
      });

      
      // reagendar
      qsa('[data-reschedule]', listBox).forEach(btn=>{
        btn.addEventListener('click', async ()=>{
          const id = btn.getAttribute('data-reschedule');
          const serviceId = parseInt(btn.getAttribute('data-service')||'0',10) || 0;
          window.MIAPP_RESCHEDULE = {id, serviceId};
          // abrir modal de reagendamiento
          const modal = document.getElementById('miapp-reschedule-modal');
          if(modal){
            // preseleccionar servicio si existe
            try{
              modal.dataset.forceServiceId = String(serviceId||'');
            }catch(e){}
            window.MIAPP.openModal(modal);
          }else{
            alert('No se encontró el modal de reagendamiento.');
          }
        });
      });

// cancelar
      qsa('[data-cancel]', listBox).forEach(btn=>{
        btn.addEventListener('click', async ()=>{
          const id = btn.getAttribute('data-cancel');
          try{
            btn.disabled = true;
            await apiPost(apiBase, `/me/appointments/${encodeURIComponent(id)}/cancel`, nonce, {});
            // recarga
            await refresh();
          }catch(e){
            alert(e.message||'No pude cancelar');
          }finally{
            btn.disabled = false;
          }
        });
      });
    }

    let apptsCache = [];
    let dayMap = {};

    async function refresh(){
      apptsCache = await loadAll();
      dayMap = buildDayMap(apptsCache);
      renderMiniCalendar(root, viewMonth, dayMap, minIso, (dayIso)=>{
        renderDay(dayMap[dayIso], dayIso);
      });
      // default: hoy
      renderDay(dayMap[minIso], minIso);
    }

    // month nav
    qs('.miapp-mini-cal__prev', root).addEventListener('click', ()=>{ viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth()-1, 1); refresh(); });
    qs('.miapp-mini-cal__next', root).addEventListener('click', ()=>{ viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth()+1, 1); refresh(); });

    await refresh();
  }

  async function initMiaDashboard(root){
    ensureDashboardShell(root, 'Panel profesional');
    const apiBase = root.getAttribute('data-api') || '';
    const nonce = root.getAttribute('data-nonce') || '';
    const kpiBox = root.querySelector('.miapp-kpis');
    if(kpiBox){ kpiBox.hidden = false; }

    const tabs = root.querySelector('.miapp-dash-tabs');
    const paneAgenda = root.querySelector('[data-pane="agenda"]');
    const paneServices = root.querySelector('[data-pane="services"]');
    if(tabs && paneAgenda && paneServices){
      tabs.hidden = false;
      tabs.querySelectorAll('.miapp-tab').forEach(btn=>{
        btn.addEventListener('click', ()=>{
          const tab = btn.getAttribute('data-tab');
          tabs.querySelectorAll('.miapp-tab').forEach(b=>b.classList.remove('miapp-tab--active'));
          btn.classList.add('miapp-tab--active');
          if(tab==='services'){
            paneAgenda.hidden = true;
            paneServices.hidden = false;
            refreshServices();
          }else{
            paneAgenda.hidden = false;
            paneServices.hidden = true;
          }
        });
      });
    }

    async function refreshKpis(){
      if(!kpiBox) return;
      const k = await apiGet(apiBase, '/practitioner/kpis', nonce);
      kpiBox.innerHTML = `
        <div class="miapp-kpi"><div class="miapp-kpi__num">${k.scheduled_today||0}</div><div class="miapp-kpi__label">Programadas hoy</div></div>
        <div class="miapp-kpi"><div class="miapp-kpi__num">${k.confirmed_today||0}</div><div class="miapp-kpi__label">Confirmadas hoy</div></div>
        <div class="miapp-kpi"><div class="miapp-kpi__num">${k.paid_today||0}</div><div class="miapp-kpi__label">Pagadas hoy</div></div>
        <div class="miapp-kpi"><div class="miapp-kpi__num">${k.closed_today||0}</div><div class="miapp-kpi__label">Cerradas hoy</div></div>
        <div class="miapp-kpi"><div class="miapp-kpi__num">${k.pending_month||0}</div><div class="miapp-kpi__label">Pendientes mes</div></div>
      `;
    }

    async function refreshServices(){
      const list = root.querySelector('.miapp-services-panel__list');
      if(!list) return;
      // Only load when pane is visible or when requested
      const j = await apiGet(apiBase, '/practitioner/services', nonce);
      const services = (j.services||[]);
      if(!services.length){
        list.innerHTML = '<div class="miapp-muted">Aún no tienes servicios. Crea uno para habilitar el agendamiento.</div>';
      }else{
        list.innerHTML = '';
        services.forEach(s=>{
          const row = document.createElement('div');
          row.className = 'miapp-service-row';
          row.innerHTML = `
            <div class="miapp-service-row__main">
              <strong>${escapeHtml(s.name||'Servicio')}</strong>
              <div class="miapp-muted">${fmtMoneyCOP(s.price_cents)} · ${escapeHtml(String(s.duration_min||60))} min · buffer ${escapeHtml(String(s.buffer_min||0))} min</div>
            </div>
            <div class="miapp-service-row__actions">
              <button type="button" class="miapp-btn miapp-btn--outline" data-edit="${s.id}">Editar</button>
              <button type="button" class="miapp-btn miapp-btn--outline" data-del="${s.id}">Desactivar</button>
            </div>
          `;
          list.appendChild(row);
        });
      }

      // handlers
      qsa('[data-edit]', list).forEach(btn=>{
        btn.addEventListener('click', async ()=>{
          const id = btn.getAttribute('data-edit');
          const svc = (j.services||[]).find(x=>String(x.id)===String(id));
          if(!svc) return;
          const name = prompt('Nombre del servicio', svc.name||'');
          if(name===null) return;
          const price = prompt('Precio COP (solo número)', String(Math.round((svc.price_cents||0)/100)));
          if(price===null) return;
          const dur = prompt('Duración (min)', String(svc.duration_min||60));
          if(dur===null) return;
          const buf = prompt('Buffer (min)', String(svc.buffer_min||0));
          if(buf===null) return;
          const modes = prompt('Modalidades (VIRTUAL,PRESENTIAL)', (svc.modes||[]).join(',') || 'VIRTUAL,PRESENTIAL');
          if(modes===null) return;
          const indications = prompt('Indicaciones (se verán en el resumen)', svc.indications||'') ?? svc.indications;
          const payload = {name, price_cop: parseInt(price,10)||0, duration_min: parseInt(dur,10)||60, buffer_min: parseInt(buf,10)||0, modes, indications};
          await apiPost(apiBase, `/practitioner/services/${encodeURIComponent(id)}`, nonce, payload, 'PUT');
          await refreshServices();
        });
      });
      qsa('[data-del]', list).forEach(btn=>{
        btn.addEventListener('click', async ()=>{
          const id = btn.getAttribute('data-del');
          if(!confirm('¿Desactivar este servicio?')) return;
          await apiPost(apiBase, `/practitioner/services/${encodeURIComponent(id)}`, nonce, {}, 'DELETE');
          await refreshServices();
        });
      });
    }

    // Add new service
    const addBtn = root.querySelector('[data-miapp-add-service]');
    if(addBtn){
      addBtn.addEventListener('click', async ()=>{
        const name = prompt('Nombre del servicio');
        if(!name) return;
        const price = prompt('Precio COP (solo número)','0');
        if(price===null) return;
        const dur = prompt('Duración (min)','60');
        if(dur===null) return;
        const buf = prompt('Buffer (min)','10');
        if(buf===null) return;
        const modes = prompt('Modalidades (VIRTUAL,PRESENTIAL)','VIRTUAL,PRESENTIAL');
        if(modes===null) return;
        const indications = prompt('Indicaciones (se verán en el resumen)','') || '';
        const payload = {name, price_cop: parseInt(price,10)||0, duration_min: parseInt(dur,10)||60, buffer_min: parseInt(buf,10)||0, modes, indications};
        await apiPost(apiBase, '/practitioner/services', nonce, payload, 'POST');
        await refreshServices();
      });
    }


    let viewMonth = new Date(); viewMonth.setDate(1);
    const now = new Date(); const minIso = '0000-01-01'; // Mia ve todo el mes

    const listBox = qs('.miapp-day-list', root);
    const dayTitle = qs('.miapp-day-title', root);

    async function loadRange(){
      const from = new Date(viewMonth.getFullYear(), viewMonth.getMonth(), 1).toISOString();
      const to = new Date(viewMonth.getFullYear(), viewMonth.getMonth()+1, 0, 23, 59, 59).toISOString();
      const j = await apiGet(apiBase, `/practitioner/appointments?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`, nonce);
      return j.appointments || [];
    }

    function buildDayMap(appts){
      const map = {};
      appts.forEach(a=>{
        const day = isoDateLocalFromUtc(a.start_at);
        map[day] = map[day] || {count:0, items:[]};
        map[day].count += 1;
        map[day].items.push(a);
      });
      return map;
    }

    function renderDay(apptsDay, dayIso){
      if(dayTitle) dayTitle.textContent = dayIso;
      if(!listBox) return;
      listBox.innerHTML = '';
      const items = (apptsDay && apptsDay.items) ? apptsDay.items : [];
      if(!items.length){
        listBox.innerHTML = '<div class="miapp-muted">No hay citas ese día.</div>';
        return;
      }
      items.sort((a,b)=> (a.start_at>b.start_at?1:-1));
      items.forEach(a=>{
        const d = new Date(a.start_at.replace(' ','T')+'Z');
        const row = document.createElement('div');
        row.className = 'miapp-appt';
        row.innerHTML = `
          <div class="miapp-appt__top">
            <strong>${d.toLocaleString('es-CO',{hour:'2-digit',minute:'2-digit'})}</strong>
            <span class="miapp-badge">${escapeHtml(a.status||'')}</span>
          </div>
          <div class="miapp-appt__meta">${escapeHtml(a.display_name||a.user_email||'Paciente')}</div>
          <div class="miapp-appt__actions">
            <button type="button" class="miapp-btn miapp-btn--outline" data-att="${a.id}" data-v="ATTENDED">Asistió</button>
            <button type="button" class="miapp-btn miapp-btn--outline" data-att="${a.id}" data-v="NO_SHOW">No vino</button>
          </div>
        `;
        listBox.appendChild(row);
      });

      qsa('[data-att]', listBox).forEach(btn=>{
        btn.addEventListener('click', async ()=>{
          const id = btn.getAttribute('data-att');
          const v = btn.getAttribute('data-v');
          try{
            btn.disabled = true;
            await apiPost(apiBase, `/practitioner/appointments/${encodeURIComponent(id)}/attendance`, nonce, {attendance_status:v});
            await refresh();
          }catch(e){
            alert(e.message||'No pude actualizar');
          }finally{
            btn.disabled = false;
          }
        });
      });
    }

    let dayMap = {};
    async function refresh(){
      try{
        const appts = await loadRange();
        dayMap = buildDayMap(appts);
      }catch(e){
        dayMap = {};
        // keep UI visible even if API fails (nonce/permissions/etc.)
      }
      renderMiniCalendar(root, viewMonth, dayMap, minIso, (dayIso)=>renderDay(dayMap[dayIso], dayIso));
      const todayIso = now.toISOString().slice(0,10);
      renderDay(dayMap[todayIso], todayIso);
    }

    qs('.miapp-mini-cal__prev', root).addEventListener('click', ()=>{ viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth()-1, 1); refresh(); });
    qs('.miapp-mini-cal__next', root).addEventListener('click', ()=>{ viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth()+1, 1); refresh(); });

    await refresh();
  }

  function scan(){
    qsa('.miapp-modal').forEach(initModal);
    qsa('.miapp-patient').forEach(el=>{ if(el.dataset.miappDashInit!=='1'){ el.dataset.miappDashInit='1'; initPatientDashboard(el).catch(()=>{});} });
    qsa('.miapp-mia').forEach(el=>{ if(el.dataset.miappDashInit!=='1'){ el.dataset.miappDashInit='1'; initMiaDashboard(el).catch(()=>{});} });
    qsa('[data-miapp-open]').forEach(btn=>{
      if(btn.dataset.miappBound === '1') return;
      btn.dataset.miappBound = '1';
      btn.addEventListener('click', ()=>{
        const id = btn.getAttribute('data-miapp-open');
        const modal = id ? document.getElementById(id) : null;
        if(modal){ initModal(modal); openModal(modal); }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', scan);
  // Elementor: re-scan cuando renderiza widgets
  document.addEventListener('elementor/popup/show', scan);
  document.addEventListener('elementor/frontend/init', function(){
    if(window.elementorFrontend){
      window.elementorFrontend.hooks.addAction('frontend/element_ready/global', scan);
    }
  });

  window.MIAPP = window.MIAPP || {};
  window.MIAPP.scan = scan;
})();


document.addEventListener('keydown', (e)=>{ if(e.key==='Escape'){ const open=document.querySelector('.miapp-modal[aria-hidden="false"]'); if(open && window.MIAPP && window.MIAPP.closeModal) window.MIAPP.closeModal(open);} });
