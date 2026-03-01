<?php if (!defined('ABSPATH')) exit; ?>
<div class="miapp-modal" id="<?php echo esc_attr($id); ?>" aria-hidden="true"
     data-api="<?php echo esc_attr($apiBase); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-logged="<?php echo esc_attr($isLogged); ?>"
     data-open-on-load="<?php echo esc_attr($openOnLoad); ?>"
     data-day-start-hour="<?php echo esc_attr($dayStartHour); ?>"
     data-day-end-hour="<?php echo esc_attr($dayEndHour); ?>"
     data-days-enabled="<?php echo esc_attr($daysEnabled); ?>">
  <div class="miapp-modal__backdrop" data-miapp-close></div>
  <div class="miapp-modal__panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr($modalTitle); ?>">
    <div class="miapp-modal__header">
      <h3 class="miapp-title"><?php echo esc_html($modalTitle); ?></h3>
      <button class="miapp-modal__close" type="button" aria-label="Cerrar" data-miapp-close>&times;</button>
    </div>

    <div class="miapp-modal__body">
      <div class="miapp-step" data-step="1">
        <h4 class="miapp-subtitle">1) Elige tu profesional y servicio</h4>
        <div id="miapp-providers" class="miapp-providers"></div>
<div id="miapp-services" class="miapp-services"></div>
        <div class="miapp-actions">
          <button class="miapp-btn miapp-btn--primary" type="button" data-next disabled>Continuar</button>
        </div>
      </div>

      <div class="miapp-step" data-step="2" hidden>
        <h4 class="miapp-subtitle">2) Elige un horario</h4>

        <div class="miapp-agenda">
          <div class="miapp-agenda__left">
<div class="miapp-cal">
          <div class="miapp-cal__head">
            <button class="miapp-btn miapp-btn--outline" type="button" id="miapp-prev-month" aria-label="Mes anterior">‹</button>
            <div class="miapp-cal__label" id="miapp-month-label"></div>
            <button class="miapp-btn miapp-btn--outline" type="button" id="miapp-next-month" aria-label="Mes siguiente">›</button>
          </div>
          <div class="miapp-cal__dow">
            <span>L</span><span>M</span><span>X</span><span>J</span><span>V</span><span>S</span><span>D</span>
          </div>
          <div class="miapp-cal__grid" id="miapp-cal-grid"></div>
          <div class="miapp-cal__legend">
            <span class="miapp-legend"><i class="miapp-dot miapp-dot--good"></i>Hay cupos</span>
            <span class="miapp-legend"><i class="miapp-dot miapp-dot--low"></i>Quedan pocos</span>
            <span class="miapp-legend"><i class="miapp-dot miapp-dot--none"></i>Sin cupos</span>
          </div>
        </div>

                  </div>
          <div class="miapp-agenda__right">
            <div class="miapp-slots-title">Horarios disponibles</div>
                      </div>
        </div>

        <input type="hidden" id="miapp-from" />
        <input type="hidden" id="miapp-to" />
<input type="hidden" id="miapp-from" />
        <input type="hidden" id="miapp-to" />
        <div id="miapp-slots"></div>
        <div class="miapp-actions">
          <button class="miapp-btn miapp-btn--outline" type="button" data-back>Volver</button>
          <button class="miapp-btn miapp-btn--primary" type="button" data-next disabled id="miapp-to-auth">Continuar</button>
        </div>
      </div>

      <div class="miapp-step" data-step="3" hidden>
        <h4 class="miapp-subtitle">3) Tu cuenta</h4>
        <div id="miapp-summary" class="miapp-summary"></div>
<div class="miapp-auth" id="miapp-auth">
          <?php echo $authHtml ?? ''; ?>
        </div>
        <div class="miapp-actions">
          <button class="miapp-btn miapp-btn--outline" type="button" data-back>Volver</button>
          <button class="miapp-btn miapp-btn--primary" type="button" data-confirm id="miapp-confirm">Confirmar</button>
        </div>
      </div>

      <div class="miapp-step" data-step="4" hidden>
        <h4 class="miapp-subtitle">Listo ✅</h4>
        <div class="miapp-ok">Revisa tu correo: te llega invitación de calendario y el enlace de la sesión (si es virtual).</div>
        <div class="miapp-actions">
          <button class="miapp-btn miapp-btn--primary" type="button" data-miapp-close>Cerrar</button>
        </div>
      </div>

      <div id="miapp-msg"></div>
    </div>
  </div>
</div>
