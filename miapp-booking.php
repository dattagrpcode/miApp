<?php
/**
 * Plugin Name: Miapp Booking
 * Description: Auto-agendamiento con cuentas, servicios (precio/duración), Google Calendar + Meet, cancel/reagendar.
 * Version: 0.5.0
 * Author: Violet Osorio
 */

if (!defined('ABSPATH')) exit;

define('MIAPP_DIR', plugin_dir_path(__FILE__));
define('MIAPP_URL', plugin_dir_url(__FILE__));

// ✅ Sube este valor cada vez que cambies el schema (tablas/columnas)
define('MIAPP_DB_VERSION', '0.4.2');

require_once MIAPP_DIR . 'includes/activator.php';
require_once MIAPP_DIR . 'includes/crypto.php';
require_once MIAPP_DIR . 'includes/google.php';
require_once MIAPP_DIR . 'includes/slots.php';
require_once MIAPP_DIR . 'includes/ics.php';
require_once MIAPP_DIR . 'includes/mail.php';
require_once MIAPP_DIR . 'includes/templates.php';
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
  wp_register_script('miapp-js', MIAPP_URL . 'assets/app.js', [], '0.4.1', true);
  wp_register_style('miapp-css', MIAPP_URL . 'assets/app.css', [], '0.4.1');
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
  (new Miapp_Patient_Dashboard())->init();

  // ✅ Init new adjustments (providers + branding/settings)
  if (class_exists('Miapp_Providers')) (new Miapp_Providers())->init();
  if (class_exists('Miapp_Settings')) (new Miapp_Settings())->init();

  /**
   * Shortcode principal: agenda embebida (root)
   */
  add_shortcode('miapp_booking', function ($atts) {
    wp_enqueue_script('miapp-js');
    wp_enqueue_style('miapp-css');

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
  add_shortcode('miapp_patient', function () {
    wp_enqueue_script('miapp-js');
    wp_enqueue_style('miapp-css');

    if (!is_user_logged_in()) {
      return '<div class="miapp-card"><h3>Mi cuenta</h3><p>Debes iniciar sesión.</p>'.do_shortcode('[miapp_login]').'</div>';
    }

    return '<div class="miapp-patient" data-api="'.esc_attr(rest_url('miapp/v1')).'" data-nonce="'.esc_attr(wp_create_nonce('wp_rest')).'"></div>';
  });

  /**
   * Shortcode botón + modal
   */
  add_shortcode('miapp_booking_button', function ($atts) {
    wp_enqueue_script('miapp-js');
    wp_enqueue_style('miapp-css');

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
              <div class="miapp-row">
                <label>Rango:</label>
                <input type="date" id="miapp-from" />
                <input type="date" id="miapp-to" />
                <button id="miapp-load">Ver disponibilidad</button>
              </div>
              <div id="miapp-slots"></div>
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
