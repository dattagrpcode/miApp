<?php
if (!defined('ABSPATH')) exit;

class Miapp_Admin {

  public function init() {
    add_action('admin_menu', function(){
      add_menu_page(
        'Miapp Booking',
        'Miapp Booking',
        'miapp_manage',
        'miapp-booking',
        [$this,'page'],
        'dashicons-calendar-alt'
      );
    });
  }

  private function get($key, $default=null) {
    $v = get_option(Miapp_Settings::OPT, []);
    if (!is_array($v)) $v = [];
    $v = array_merge(Miapp_Settings::defaults(), $v);
    return array_key_exists($key, $v) ? $v[$key] : $default;
  }

  private function sanitize_color($c, $default='#111111') {
    $c = trim((string)$c);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $default;
  }

  public function page() {
    if (!current_user_can('manage_options')) return;

    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'rules';

    // Handle POST per tab
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['miapp_tab']) && check_admin_referer('miapp_save_'.$tab)) {
      $postedTab = sanitize_key($_POST['miapp_tab']);
      if ($postedTab === 'branding') {
        $settings = get_option(Miapp_Settings::OPT, []);
        if (!is_array($settings)) $settings = [];
        $settings['brand_primary'] = $this->sanitize_color($_POST['brand_primary'] ?? '#111111', '#111111');
        $settings['brand_bg']      = $this->sanitize_color($_POST['brand_bg'] ?? '#ffffff', '#ffffff');
        $settings['brand_text']    = $this->sanitize_color($_POST['brand_text'] ?? '#111111', '#111111');
        $settings['brand_radius']  = strval(intval($_POST['brand_radius'] ?? 14));
        update_option(Miapp_Settings::OPT, $settings);

        // legacy compatibility
        update_option('miapp_brand_primary_color', $settings['brand_primary']);
      }

      if ($postedTab === 'rules') {
        update_option('miapp_day_start_hour', intval($_POST['day_start_hour'] ?? 9));
        update_option('miapp_day_end_hour', intval($_POST['day_end_hour'] ?? 18));
        update_option('miapp_days_enabled', sanitize_text_field($_POST['days_enabled'] ?? '1,2,3,4,5'));
        update_option('miapp_min_hours_change', intval($_POST['min_hours_change'] ?? 24));
      }

      if ($postedTab === 'general') {
        update_option('miapp_doctor_name', sanitize_text_field($_POST['doctor_name'] ?? 'Mia'));
        update_option('miapp_disable_css', isset($_POST['disable_css']) ? '1' : '0');
      }

      if ($postedTab === 'shortcodes') {
        // nothing to save
      }


      if ($postedTab === 'emails') {
        update_option('miapp_email_subject_confirm', sanitize_text_field($_POST['email_subject_confirm'] ?? 'Confirmación de cita'));
        update_option('miapp_email_subject_cancel', sanitize_text_field($_POST['email_subject_cancel'] ?? 'Cita cancelada'));
        update_option('miapp_email_subject_reschedule', sanitize_text_field($_POST['email_subject_reschedule'] ?? 'Cita reagendada'));
        update_option('miapp_email_subject_status', sanitize_text_field($_POST['email_subject_status'] ?? 'Actualización de tu cita'));

        // Templates: allow safe HTML
        update_option('miapp_email_tpl_confirm', wp_kses_post($_POST['email_tpl_confirm'] ?? ''));
        update_option('miapp_email_tpl_cancel', wp_kses_post($_POST['email_tpl_cancel'] ?? ''));
        update_option('miapp_email_tpl_reschedule', wp_kses_post($_POST['email_tpl_reschedule'] ?? ''));
        update_option('miapp_email_tpl_status', wp_kses_post($_POST['email_tpl_status'] ?? ''));
      }


      echo '<div class="updated"><p>Guardado.</p></div>';
    }

    $tabs = [
      'general'   => 'General',
      'rules'     => 'Reglas',
      'branding'  => 'Branding',
      'google'    => 'Google',
      'emails'    => 'Correos',
      'shortcodes'=> 'Shortcodes',
    ];

    echo '<div class="wrap"><h1>Miapp Booking</h1>';
    echo '<h2 class="nav-tab-wrapper">';
    foreach ($tabs as $k => $label) {
      $class = ($k === $tab) ? 'nav-tab nav-tab-active' : 'nav-tab';
      $url = admin_url('admin.php?page=miapp-booking&tab='.$k);
      echo '<a class="'.esc_attr($class).'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
    }
    echo '</h2>';

    if ($tab === 'general') $this->tabGeneral();
    if ($tab === 'rules') $this->tabRules();
    if ($tab === 'branding') $this->tabBranding();
    if ($tab === 'google') $this->tabGoogle();
    if ($tab === 'emails') $this->tabEmails();
    if ($tab === 'shortcodes') $this->tabShortcodes();

    echo '</div>';
  }

  private function tabGoogle() {
    // Save settings
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['miapp_tab']) && sanitize_key($_POST['miapp_tab']) === 'google') {
      // nonce already validated in page()
      update_option('miapp_google_client_id', sanitize_text_field($_POST['google_client_id'] ?? ''));
      update_option('miapp_google_client_secret', sanitize_text_field($_POST['google_client_secret'] ?? ''));
      update_option('miapp_google_calendar_id', sanitize_text_field($_POST['google_calendar_id'] ?? 'primary'));
    }

    $clientId = get_option('miapp_google_client_id','');
    $clientSecret = get_option('miapp_google_client_secret','');
    $calId = get_option('miapp_google_calendar_id','primary');

    $connected = false;
    $authUrl = '';
    $err = '';

    // Handle OAuth code
    if (!empty($_GET['code'])) {
      try {
        $g = new Miapp_Google();
        $g->handleCode(sanitize_text_field($_GET['code']));
        $connected = true;
        echo '<div class="updated"><p>Google conectado ✅</p></div>';
      } catch (Throwable $e) {
        $err = $e->getMessage();
      }
    }

    // Disconnect
    if (isset($_POST['miapp_google_disconnect']) && check_admin_referer('miapp_google_disconnect')) {
      delete_option('miapp_google_token');
      $connected = false;
      echo '<div class="updated"><p>Google desconectado.</p></div>';
    }

    try {
      $g = new Miapp_Google();
      $connected = $g->isConnected();
      if (!$connected && $clientId && $clientSecret) {
        $authUrl = $g->authUrl();
      }
    } catch (Throwable $e) {
      $connected = (bool)get_option('miapp_google_token','');
      if (!$err) $err = $e->getMessage();
    }

    ?>
    <div class="miapp-card" style="max-width:980px">
      <h2>Google Calendar + Meet</h2>
      <p class="description">
        Conecta la cuenta de Mia para crear eventos en Google Calendar y generar enlace de Google Meet para teleconsulta.
      </p>

      <?php if ($err): ?>
        <div class="notice notice-warning"><p><?php echo esc_html($err); ?></p></div>
      <?php endif; ?>

      <form method="post">
        <?php wp_nonce_field('miapp_save_google'); ?>
        <input type="hidden" name="miapp_tab" value="google">
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label>Client ID</label></th>
            <td><input type="text" name="google_client_id" value="<?php echo esc_attr($clientId); ?>" class="large-text"></td>
          </tr>
          <tr>
            <th scope="row"><label>Client Secret</label></th>
            <td><input type="text" name="google_client_secret" value="<?php echo esc_attr($clientSecret); ?>" class="large-text"></td>
          </tr>
          <tr>
            <th scope="row"><label>Calendar ID</label></th>
            <td>
              <input type="text" name="google_calendar_id" value="<?php echo esc_attr($calId); ?>" class="regular-text">
              <p class="description">Usa <code>primary</code> o el ID del calendario.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('Guardar'); ?>
      </form>

      <hr>

      <h3>Estado</h3>
      <?php if ($connected): ?>
        <p>✅ Conectado. Se crearán eventos al confirmar citas.</p>
        <form method="post">
          <?php wp_nonce_field('miapp_google_disconnect'); ?>
          <input type="hidden" name="miapp_google_disconnect" value="1">
          <?php submit_button('Desconectar', 'delete'); ?>
        </form>
      <?php else: ?>
        <p>🔌 No conectado.</p>
        <?php if ($authUrl): ?>
          <p><a class="button button-primary" href="<?php echo esc_url($authUrl); ?>">Conectar Google</a></p>
          <p class="description">Redirect URI esperado: <code><?php echo esc_html(admin_url('admin.php?page=miapp-booking&tab=google')); ?></code></p>
        <?php else: ?>
          <p class="description">Guarda Client ID/Secret primero.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
  }

  private function tabGeneral() {
    $doctor = get_option('miapp_doctor_name', 'Mia');
    $disableCss = get_option('miapp_disable_css','0') === '1';
    ?>
    <form method="post">
      <?php wp_nonce_field('miapp_save_general'); ?>
      <input type="hidden" name="miapp_tab" value="general">
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label>Nombre profesional</label></th>
          <td><input type="text" name="doctor_name" value="<?php echo esc_attr($doctor); ?>" class="regular-text"></td>
        </tr>
        <tr>
          <th scope="row">Diseño</th>
          <td>
            <label>
              <input type="checkbox" name="disable_css" value="1" <?php checked($disableCss); ?>>
              No cargar estilos del plugin (para diseñar con Elementor/Gutenberg/Theme)
            </label>
          </td>
        </tr>
      </table>
      <?php submit_button('Guardar'); ?>
    </form>
    <?php
  }

  private function tabRules() {
    $start = intval(get_option('miapp_day_start_hour', 9));
    $end = intval(get_option('miapp_day_end_hour', 18));
    $days = get_option('miapp_days_enabled', '1,2,3,4,5');
    $minh = intval(get_option('miapp_min_hours_change', 24));
    ?>
    <form method="post">
      <?php wp_nonce_field('miapp_save_rules'); ?>
      <input type="hidden" name="miapp_tab" value="rules">
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label>Hora inicio</label></th>
          <td><input type="number" min="0" max="23" name="day_start_hour" value="<?php echo esc_attr($start); ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label>Hora fin</label></th>
          <td><input type="number" min="0" max="23" name="day_end_hour" value="<?php echo esc_attr($end); ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label>Días habilitados</label></th>
          <td>
            <input type="text" name="days_enabled" value="<?php echo esc_attr($days); ?>" class="regular-text">
            <p class="description">Formato: 1=Lun … 6=Sáb, 7=Dom. Ej: 1,2,3,4,5</p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label>Horas mínimas para cambiar (cancelar/reagendar)</label></th>
          <td><input type="number" min="1" name="min_hours_change" value="<?php echo esc_attr($minh); ?>"></td>
        </tr>
      </table>
      <?php submit_button('Guardar'); ?>
    </form>
    <?php
  }

  private function tabBranding() {
    $primary = $this->get('brand_primary', '#111111');
    $bg = $this->get('brand_bg', '#ffffff');
    $text = $this->get('brand_text', '#111111');
    $radius = $this->get('brand_radius', '14');
    ?>
    <form method="post">
      <?php wp_nonce_field('miapp_save_branding'); ?>
      <input type="hidden" name="miapp_tab" value="branding">
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label>Color primario</label></th>
          <td><input type="text" name="brand_primary" value="<?php echo esc_attr($primary); ?>" class="regular-text"></td>
        </tr>
        <tr>
          <th scope="row"><label>Fondo</label></th>
          <td><input type="text" name="brand_bg" value="<?php echo esc_attr($bg); ?>" class="regular-text"></td>
        </tr>
        <tr>
          <th scope="row"><label>Texto</label></th>
          <td><input type="text" name="brand_text" value="<?php echo esc_attr($text); ?>" class="regular-text"></td>
        </tr>
        <tr>
          <th scope="row"><label>Radio</label></th>
          <td><input type="number" min="0" name="brand_radius" value="<?php echo esc_attr($radius); ?>"></td>
        </tr>
      </table>
      <?php submit_button('Guardar'); ?>
      <p class="description">Tip: si vas a diseñar todo con Elementor, puedes activar “No cargar estilos del plugin” en General.</p>
    </form>
    <?php
  }

  private function tabShortcodes() {
    ?>
    <div class="miapp-card" style="max-width:920px">
      <h2>Shortcodes disponibles</h2>
      <ul style="line-height:1.9">
        <li><code>[miapp_booking_button label="Agendar cita"]</code> — Botón que abre el modal.</li>
        <li><code>[miapp_booking]</code> — Vista simple de agenda embebida (no modal).</li>
        <li><code>[miapp_patient]</code> — Panel paciente (legacy).</li>
        <li><code>[miapp_patient_dashboard]</code> — Panel paciente (nuevo, recomendado).</li>
        <li><code>[miapp_mia_dashboard]</code> — Dashboard privado para Mia (administradora).</li>
      </ul>
      <p class="description">Recomendación: crea una página “Mi cuenta” con el panel paciente y agrega también <code>[miapp_booking_button]</code> para permitir reagendar desde el panel.</p>
    </div>
    <?php
  }

  private function tabEmails() {
    $subConfirm = get_option('miapp_email_subject_confirm','Confirmación de cita');
    $subCancel = get_option('miapp_email_subject_cancel','Cita cancelada');
    $subRes = get_option('miapp_email_subject_reschedule','Cita reagendada');
    $subStatus = get_option('miapp_email_subject_status','Actualización de tu cita');

    $tplConfirm = get_option('miapp_email_tpl_confirm', '');
    $tplCancel = get_option('miapp_email_tpl_cancel', '');
    $tplRes = get_option('miapp_email_tpl_reschedule', '');
    $tplStatus = get_option('miapp_email_tpl_status', '');

    $help = '<p class="description">Variables disponibles: <code>{{patient_name}}</code>, <code>{{doctor_name}}</code>, <code>{{date_human}}</code>, <code>{{service_name}}</code>, <code>{{session_number}}</code>, <code>{{status_label}}</code>, <code>{{meet_block}}</code>, <code>{{buttons_block}}</code>.</p>';

    ?>
    <form method="post">
      <?php wp_nonce_field('miapp_save_emails'); ?>
      <input type="hidden" name="miapp_tab" value="emails"/>

      <h3>Asuntos</h3>
      <table class="form-table">
        <tr><th><label>Confirmación</label></th><td><input class="regular-text" type="text" name="email_subject_confirm" value="<?php echo esc_attr($subConfirm); ?>"></td></tr>
        <tr><th><label>Cancelación</label></th><td><input class="regular-text" type="text" name="email_subject_cancel" value="<?php echo esc_attr($subCancel); ?>"></td></tr>
        <tr><th><label>Reagendamiento</label></th><td><input class="regular-text" type="text" name="email_subject_reschedule" value="<?php echo esc_attr($subRes); ?>"></td></tr>
        <tr><th><label>Cambio de estado (por Mia)</label></th><td><input class="regular-text" type="text" name="email_subject_status" value="<?php echo esc_attr($subStatus); ?>"></td></tr>
      </table>

      <h3>Plantillas (HTML)</h3>
      <?php echo $help; ?>

      <h4>Confirmación</h4>
      <?php wp_editor($tplConfirm, 'email_tpl_confirm', ['textarea_name'=>'email_tpl_confirm','textarea_rows'=>10]); ?>

      <h4>Cancelación</h4>
      <?php wp_editor($tplCancel, 'email_tpl_cancel', ['textarea_name'=>'email_tpl_cancel','textarea_rows'=>10]); ?>

      <h4>Reagendamiento</h4>
      <?php wp_editor($tplRes, 'email_tpl_reschedule', ['textarea_name'=>'email_tpl_reschedule','textarea_rows'=>10]); ?>

      <h4>Cambio de estado</h4>
      <?php wp_editor($tplStatus, 'email_tpl_status', ['textarea_name'=>'email_tpl_status','textarea_rows'=>10]); ?>

      <?php submit_button('Guardar'); ?>
    </form>
    <?php
  }


}
