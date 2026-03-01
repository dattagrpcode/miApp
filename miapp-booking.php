<?php
/**
 * Plugin Name: Miapp Booking
 * Description: Auto-agendamiento con cuentas, servicios (precio/duración), Google Calendar + Meet, cancel/reagendar.
 * Version: 6.3.0
 * Author: Violet Osorio
 */

if (!defined('ABSPATH')) exit;

define('MIAPP_DIR', plugin_dir_path(__FILE__));
define('MIAPP_URL', plugin_dir_url(__FILE__));

// ✅ Sube este valor cada vez que cambies el schema (tablas/columnas)
define('MIAPP_DB_VERSION', '6.1.5');

require_once MIAPP_DIR . 'includes/activator.php';
require_once MIAPP_DIR . 'includes/crypto.php';
require_once MIAPP_DIR . 'includes/google.php';
require_once MIAPP_DIR . 'includes/slots.php';
require_once MIAPP_DIR . 'includes/ics.php';
require_once MIAPP_DIR . 'includes/mail.php';
require_once MIAPP_DIR . 'includes/templates.php';
require_once MIAPP_DIR . 'includes/views.php';
require_once MIAPP_DIR . 'includes/services.php';
require_once MIAPP_DIR . 'includes/auth.php';
require_once MIAPP_DIR . 'includes/rest.php';
require_once MIAPP_DIR . 'includes/admin.php';
require_once MIAPP_DIR . 'includes/patient-dashboard.php';

// ✅ Estos dos ya los tienes, pero faltaba inicializarlos
require_once MIAPP_DIR . 'includes/providers.php';
require_once MIAPP_DIR . 'includes/settings.php';

register_activation_hook(__FILE__, ['Miapp_Activator', 'activate']);

/**
 * ✅ Registra assets SIEMPRE (no dentro de plugins_loaded)
 */
add_action('wp_enqueue_scripts', function () {
  wp_register_script('miapp-js', MIAPP_URL . 'assets/app.js', [], '6.3.0', true);
  wp_register_style('miapp-css', MIAPP_URL . 'assets/app.css', [], '6.3.0');
  wp_register_style('miapp-modal-css', MIAPP_URL . 'assets/modal.css', [], '6.2.1');
});

/**
 * ✅ DB upgrade + init clases
 */
add_action('plugins_loaded', function () {
  // DB upgrade
  $v = get_option('miapp_db_version', '');
  if ($v !== MIAPP_DB_VERSION) {
    Miapp_Activator::upgrade();
    update_option('miapp_db_version', MIAPP_DB_VERSION);
  }

  // ✅ Init core classes
  (new Miapp_Services())->init();
  (new Miapp_Auth())->init();
  (new Miapp_Admin())->init();
  (new Miapp_Rest())->init();

  // Elementor widgets (opcional)
  if (did_action('elementor/loaded')) {
    require_once MIAPP_DIR . 'includes/elementor/elementor.php';
  }
  (new Miapp_Patient_Dashboard())->init();

  // ✅ Init new adjustments (providers + branding/settings)
  if (class_exists('Miapp_Providers')) (new Miapp_Providers())->init();
  if (class_exists('Miapp_Settings')) (new Miapp_Settings())->init();

  /**
   * Shortcode principal: agenda embebida (root)
   */
  add_shortcode('miapp_booking', function ($atts) {
    wp_enqueue_script('miapp-js');
    wp_enqueue_style('miapp-modal-css');
    if (!get_option('miapp_disable_css','0')) { wp_enqueue_style('miapp-css'); }

    $apiBase = esc_url_raw(rest_url('miapp/v1'));
    $nonce = wp_create_nonce('wp_rest');
    $isLogged = is_user_logged_in() ? '1' : '0';

    $dayStart = intval(get_option('miapp_day_start_hour', 9));
    $dayEnd   = intval(get_option('miapp_day_end_hour', 18));
    $daysEnabled = sanitize_text_field(get_option('miapp_days_enabled', '1,2,3,4,5'));

    return '<div class="miapp-root" data-api="'.esc_attr($apiBase).'" data-nonce="'.esc_attr($nonce).'" data-logged="'.esc_attr($isLogged).'" data-day-start-hour="'.esc_attr($dayStart).'" data-day-end-hour="'.esc_attr($dayEnd).'" data-days-enabled="'.esc_attr($daysEnabled).'"></div>';
  });

  /**
   * ✅ Shortcode para panel paciente
   * IMPORTANTE: encola assets; sin esto el panel puede verse “vacío”
   */
  
  // Shortcode: dashboard paciente (frontend)
  add_shortcode('miapp_patient_dashboard', function () {
    if (!is_user_logged_in()) {
      return '<div class="miapp-card"><h3>Mi cuenta</h3><p>Debes iniciar sesión.</p>' . do_shortcode('[miapp_login]') . '</div>';
    }
    wp_enqueue_script('miapp-js');
    wp_enqueue_style('miapp-modal-css');
    if (!get_option('miapp_disable_css','0')) { wp_enqueue_style('miapp-css'); }
    $apiBase = esc_url_raw(rest_url('miapp/v1'));
    $nonce = wp_create_nonce('wp_rest');
    return Miapp_Views::render('patient-dashboard', [
      'apiBase' => $apiBase,
      'nonce' => $nonce,
    ]);
  });

  // Shortcode: dashboard Mia (frontend privado)
  add_shortcode('miapp_mia_dashboard', function () {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
      return '<div class="miapp-card"><h3>Acceso restringido</h3><p>Debes iniciar sesión como administradora.</p></div>';
    }
    wp_enqueue_script('miapp-js');
    wp_enqueue_style('miapp-modal-css');
    if (!get_option('miapp_disable_css','0')) { wp_enqueue_style('miapp-css'); }
    $apiBase = esc_url_raw(rest_url('miapp/v1'));
    $nonce = wp_create_nonce('wp_rest');
    return Miapp_Views::render('mia-dashboard', [
      'apiBase' => $apiBase,
      'nonce' => $nonce,
    ]);
  });

  add_shortcode('miapp_patient', function () {
    wp_enqueue_script('miapp-js');
    wp_enqueue_style('miapp-modal-css');
    if (!get_option('miapp_disable_css','0')) { wp_enqueue_style('miapp-css'); }

    if (!is_user_logged_in()) {
      return '<div class="miapp-card"><h3>Mi cuenta</h3><p>Debes iniciar sesión.</p>'.do_shortcode('[miapp_login]').'</div>';
    }

    $apiBase = esc_url_raw(rest_url('miapp/v1'));
    $nonce = wp_create_nonce('wp_rest');
    $isLogged = is_user_logged_in() ? '1' : '0';
    $id = 'miapp-reschedule-modal';
    $openOnLoad = '0';

    $loginHtml = do_shortcode('[miapp_login]');
    $registerHtml = do_shortcode('[miapp_register]');

    ob_start(); ?>
      <div class="miapp-patient" data-api="<?php echo esc_attr($apiBase); ?>" data-nonce="<?php echo esc_attr($nonce); ?>"></div>

      <!-- Modal reutilizable para reagendar desde el dashboard -->
      <div class="miapp-modal" id="<?php echo esc_attr($id); ?>" aria-hidden="true"
           data-api="<?php echo esc_attr($apiBase); ?>"
           data-nonce="<?php echo esc_attr($nonce); ?>"
           data-logged="<?php echo esc_attr($isLogged); ?>"
           data-open-on-load="<?php echo esc_attr($openOnLoad); ?>"
           data-day-start-hour="<?php echo esc_attr(intval(get_option('miapp_day_start_hour',9))); ?>"
           data-day-end-hour="<?php echo esc_attr(intval(get_option('miapp_day_end_hour',18))); ?>"
           data-slot-minutes="<?php echo esc_attr(intval(get_option('miapp_slot_minutes',60))); ?>"
           data-buffer-minutes="<?php echo esc_attr(intval(get_option('miapp_buffer_minutes',10))); ?>">
        <div class="miapp-modal__backdrop" data-miapp-close></div>
        <div class="miapp-modal__panel" role="dialog" aria-modal="true" aria-label="Reagendar cita">
          <div class="miapp-modal__header">
            <h3>Reagendar cita</h3>
            <button class="miapp-modal__close" data-miapp-close type="button" aria-label="Cerrar">×</button>
          </div>

          <div class="miapp-modal__body">
            <div class="miapp-step" data-step="1">
              <h4 class="miapp-subtitle">1) Servicio</h4>
              <div id="miapp-services"></div>
              <div class="miapp-actions">
                <button class="miapp-btn miapp-btn--primary" type="button" data-next>Continuar</button>
              </div>
            </div>

            <div class="miapp-step" data-step="2" hidden>
              <h4 class="miapp-subtitle">2) Horario</h4>
              <div class="miapp-agenda">
                <div class="miapp-agenda__left"><div class="miapp-cal"></div></div>
                <div class="miapp-agenda__right"><div id="miapp-slots"></div></div>
              </div>
              <div class="miapp-actions">
                <button class="miapp-btn miapp-btn--outline" type="button" data-back>Volver</button>
                <button class="miapp-btn miapp-btn--primary" type="button" data-next id="miapp-to-auth" disabled>Continuar</button>
              </div>
            </div>

            <div class="miapp-step" data-step="3" hidden>
              <h4 class="miapp-subtitle">3) Confirmación</h4>
              <div id="miapp-summary" class="miapp-summary"></div>

              <div class="miapp-auth" id="miapp-auth" style="display:none;"></div>

              <div class="miapp-actions">
                <button class="miapp-btn miapp-btn--outline" type="button" data-back>Volver</button>
                <button class="miapp-btn miapp-btn--primary" type="button" data-confirm id="miapp-confirm">Confirmar</button>
              </div>
            </div>

            <div class="miapp-step" data-step="4" hidden>
              <h4 class="miapp-subtitle">Listo ✅</h4>
              <div class="miapp-ok">Tu cita fue reagendada. Revisa tu correo para la actualización.</div>
              <div class="miapp-actions">
                <button class="miapp-btn miapp-btn--primary" type="button" data-miapp-close>Cerrar</button>
              </div>
            </div>

            <div id="miapp-msg"></div>

            <template id="miapp-login-template">
              <?php echo $loginHtml; ?>
            </template>

            <template id="miapp-register-template">
              <?php echo $registerHtml; ?>
            </template>
          </div>
        </div>
      </div>
    <?php
    return ob_get_clean();
  });

  /**
   * Shortcode botón + modal
   */
  
  // Shortcode para dashboard de Mia (frontend privado)
  add_shortcode('miapp_mia', function () {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
      return '<div class="miapp-card"><h3>Dashboard</h3><p>No tienes permisos.</p></div>';
    }
    wp_enqueue_script('miapp-js');
    wp_enqueue_style('miapp-modal-css');
    wp_enqueue_style('miapp-css');

    $apiBase = esc_url_raw(rest_url('miapp/v1'));
    $nonce = wp_create_nonce('wp_rest');

    return Miapp_Views::render('mia-dashboard', [
      'apiBase' => $apiBase,
      'nonce' => $nonce,
    ]);
  });

add_shortcode('miapp_booking_button', function ($atts) {
    wp_enqueue_script('miapp-js');
    wp_enqueue_style('miapp-modal-css');
    if (!get_option('miapp_disable_css','0')) { wp_enqueue_style('miapp-css'); }

    $a = shortcode_atts([
      'label' => 'Agendar cita',
      'style' => 'primary', // primary | outline | link
      'modal_title' => 'Agenda tu cita',
    ], $atts);

    $apiBase = esc_url_raw(rest_url('miapp/v1'));
    $nonce = wp_create_nonce('wp_rest');
    $isLogged = is_user_logged_in() ? '1' : '0';

    // Un id único para permitir múltiples botones en una página
    $id = 'miapp-btn-' . wp_generate_password(8, false, false);

    $btnClass = 'miapp-btn miapp-btn--' . esc_attr($a['style']);

    // Esto permite reabrir modal tras login/registro (usa ?miapp_open=1)
    $openOnLoad = (isset($_GET['miapp_open']) && $_GET['miapp_open'] === '1') ? '1' : '0';

    $loginHtml = do_shortcode('[miapp_login]');
    $registerHtml = do_shortcode('[miapp_register]');

    ob_start(); ?>
      <button class="<?php echo $btnClass; ?>" data-miapp-open="<?php echo esc_attr($id); ?>">
        <?php echo esc_html($a['label']); ?>
      </button>

      <div class="miapp-modal" id="<?php echo esc_attr($id); ?>" aria-hidden="true"
           data-api="<?php echo esc_attr($apiBase); ?>"
           data-nonce="<?php echo esc_attr($nonce); ?>"
           data-logged="<?php echo esc_attr($isLogged); ?>"
           data-open-on-load="<?php echo esc_attr($openOnLoad); ?>"
           data-day-start-hour="<?php echo esc_attr(intval(get_option('miapp_day_start_hour',9))); ?>"
           data-day-end-hour="<?php echo esc_attr(intval(get_option('miapp_day_end_hour',18))); ?>"
           data-days-enabled="<?php echo esc_attr(sanitize_text_field(get_option('miapp_days_enabled','1,2,3,4,5'))); ?>">
        <div class="miapp-modal__backdrop" data-miapp-close></div>
        <div class="miapp-modal__panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr($a['modal_title']); ?>">
          <div class="miapp-modal__header">
            <h3><?php echo esc_html($a['modal_title']); ?></h3>
            <button class="miapp-modal__close" data-miapp-close>&times;</button>
          </div>

          <div class="miapp-modal__body">
            <div class="miapp-step" data-step="1">
              <h4>1) Elige tu servicio</h4>
              <div id="miapp-services"></div>
              <div class="miapp-actions">
                <button class="miapp-next" data-next>Continuar</button>
              </div>
            </div>

            <div class="miapp-step" data-step="2" hidden>
<h4>2) Elige un horario</h4>

<!-- Inputs ocultos: el JS los usa como "fecha seleccionada" -->
<input type="date" id="miapp-from" hidden />
<input type="date" id="miapp-to" hidden />

<div class="miapp-cal">
  <div class="miapp-cal__header">
    <button type="button" id="miapp-prev-month" class="miapp-cal__nav" aria-label="Mes anterior">‹</button>
    <div id="miapp-month-label" class="miapp-cal__label"></div>
    <button type="button" id="miapp-next-month" class="miapp-cal__nav" aria-label="Mes siguiente">›</button>
  </div>

  <div class="miapp-cal__week">
    <div>L</div><div>M</div><div>X</div><div>J</div><div>V</div><div>S</div><div>D</div>
  </div>

  <div id="miapp-cal-grid" class="miapp-cal__grid"></div>

  <div class="miapp-cal__legend">
    <span><i class="miapp-dot miapp-dot--good"></i> Disponible</span>
    <span><i class="miapp-dot miapp-dot--low"></i> Pocos cupos</span>
    <span><i class="miapp-dot miapp-dot--none"></i> Sin cupos</span>
  </div>
</div>

<div class="miapp-slots-wrap">
  <h4 class="miapp-slots-title">Horas disponibles</h4>
  <div id="miapp-slots"></div>
</div>

<div class="miapp-actions">
  <button class="miapp-back" data-back>Volver</button>
  <button class="miapp-next" data-next disabled id="miapp-to-auth">Continuar</button>
</div>
            
            </div>

            <div class="miapp-step" data-step="3" hidden>
              <h4>3) Tu cuenta</h4>
              <div class="miapp-auth" id="miapp-auth"></div>

              <div class="miapp-actions">
                <button class="miapp-back" data-back>Volver</button>
                <button class="miapp-next" data-confirm id="miapp-confirm" disabled>Confirmar</button>
              </div>
            </div>

            <div class="miapp-step" data-step="4" hidden>
              <h4>Listo ✅</h4>
              <div class="miapp-ok">Revisa tu correo: te llega invitación de calendario y el enlace de la sesión (si es virtual).</div>
              <div class="miapp-actions">
                <button class="miapp-next" data-miapp-close>Cerrar</button>
              </div>
            </div>

            <div id="miapp-msg"></div>

            <template id="miapp-login-template">
              <?php echo $loginHtml; ?>
              <div class="miapp-muted">
                Si acabas de iniciar sesión o crear cuenta, vuelve aquí y continúa.
                <br>Tip: si se recarga la página, el modal puede reabrirse.
              </div>
            </template>

            <template id="miapp-register-template">
              <?php echo $registerHtml; ?>
            </template>
          </div>
        </div>
      </div>
    <?php
    return ob_get_clean();
  });
});
