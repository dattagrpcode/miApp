<?php
if (!defined('ABSPATH')) exit;

class Miapp_Admin {

  public function init() {
    add_action('admin_menu', function(){
      add_menu_page(
        'Miapp Booking',
        'Miapp Booking',
        'manage_options',
        'miapp-booking',
        [$this,'page'],
        'dashicons-calendar-alt'
      );
    });
  }

  private function sanitize_color($c, $default='#111111') {
    $c = trim((string)$c);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) return $c;
    return $default;
  }

  private function get_tab(): string {
    return sanitize_text_field($_GET['tab'] ?? 'rules');
  }

  public function page() {
    if (!current_user_can('manage_options')) return;

    $msg = '';

    // Callback OAuth Google
    if (isset($_GET['code'])) {
      try {
        $g = new Miapp_Google();
        $g->handleCode(sanitize_text_field($_GET['code']));
        $msg = '<div class="notice notice-success"><p>Google conectado ✅</p></div>';
      } catch (Exception $e) {
        $msg = '<div class="notice notice-error"><p>'.esc_html($e->getMessage()).'</p></div>';
      }
    }

    // Guardar ajustes (por tab)
    if (isset($_POST['miapp_save']) && check_admin_referer('miapp_save')) {

      // Helper: solo actualiza si viene en POST (para no pisar otros tabs)
      $upd = function(string $opt, $value) {
        update_option($opt, $value);
      };

      // Google
      if (array_key_exists('google_client_id', $_POST)) {
        $upd('miapp_google_client_id', sanitize_text_field($_POST['google_client_id']));
      }
      if (array_key_exists('google_client_secret', $_POST)) {
        $upd('miapp_google_client_secret', sanitize_text_field($_POST['google_client_secret']));
      }
      if (array_key_exists('calendar_id', $_POST)) {
        $upd('miapp_calendar_id', sanitize_text_field($_POST['calendar_id'] ?: 'primary'));
      }

      // Reglas
      if (array_key_exists('doctor_name', $_POST)) {
        $upd('miapp_doctor_name', sanitize_text_field($_POST['doctor_name'] ?: 'Mia'));
      }
      if (array_key_exists('min_hours_change', $_POST)) {
        $upd('miapp_min_hours_change', max(0, intval($_POST['min_hours_change'])));
      }
      if (array_key_exists('day_start_hour', $_POST)) {
        $upd('miapp_day_start_hour', max(0, min(23, intval($_POST['day_start_hour']))));
      }
      if (array_key_exists('day_end_hour', $_POST)) {
        $upd('miapp_day_end_hour', max(0, min(23, intval($_POST['day_end_hour']))));
      }
      if (array_key_exists('days_enabled', $_POST)) {
        // 1=Lun..7=Dom (guardado como string CSV)
        $raw = sanitize_text_field($_POST['days_enabled'] ?: '1,2,3,4,5');
        $upd('miapp_days_enabled', $raw);
      }

      // Branding (Miapp_Settings::OPT)
      if (array_key_exists('brand_primary', $_POST) || array_key_exists('brand_bg', $_POST) || array_key_exists('brand_text', $_POST) || array_key_exists('brand_radius', $_POST)) {
        $current = Miapp_Settings::get();
        $current['brand_primary'] = $this->sanitize_color($_POST['brand_primary'] ?? $current['brand_primary'], $current['brand_primary']);
        $current['brand_bg']      = $this->sanitize_color($_POST['brand_bg'] ?? $current['brand_bg'], $current['brand_bg']);
        $current['brand_text']    = $this->sanitize_color($_POST['brand_text'] ?? $current['brand_text'], $current['brand_text']);
        $current['brand_radius']  = (string) max(6, min(24, intval($_POST['brand_radius'] ?? $current['brand_radius'])));
        update_option(Miapp_Settings::OPT, $current);

        // Compat legacy
        update_option('miapp_brand_primary_color', $current['brand_primary']);

        if (array_key_exists('brand_logo', $_POST)) {
          update_option('miapp_brand_logo_url', esc_url_raw($_POST['brand_logo'] ?? ''));
        }
      } else {
        // Logo puede venir solo
        if (array_key_exists('brand_logo', $_POST)) {
          update_option('miapp_brand_logo_url', esc_url_raw($_POST['brand_logo'] ?? ''));
        }
      }

      // Correos
      if (array_key_exists('sub_confirm', $_POST)) $upd('miapp_email_subject_confirm', sanitize_text_field($_POST['sub_confirm'] ?? ''));
      if (array_key_exists('sub_cancel', $_POST)) $upd('miapp_email_subject_cancel', sanitize_text_field($_POST['sub_cancel'] ?? ''));
      if (array_key_exists('sub_reschedule', $_POST)) $upd('miapp_email_subject_reschedule', sanitize_text_field($_POST['sub_reschedule'] ?? ''));
      if (array_key_exists('tpl_confirm', $_POST)) $upd('miapp_email_tpl_confirm', wp_kses_post($_POST['tpl_confirm'] ?? ''));
      if (array_key_exists('tpl_cancel', $_POST)) $upd('miapp_email_tpl_cancel', wp_kses_post($_POST['tpl_cancel'] ?? ''));
      if (array_key_exists('tpl_reschedule', $_POST)) $upd('miapp_email_tpl_reschedule', wp_kses_post($_POST['tpl_reschedule'] ?? ''));

      $msg = '<div class="notice notice-success"><p>Guardado ✅</p></div>';
    }

    $tab = $this->get_tab();

    echo '<div class="wrap"><h1>Miapp Booking</h1>'.$msg;

    // Tabs
    echo '<h2 class="nav-tab-wrapper">';
    $tabs = [
      'rules'     => 'Reglas',
      'google'    => 'Google',
      'branding'  => 'Branding',
      'emails'    => 'Correos',
      'shortcodes'=> 'Shortcodes',
    ];
    foreach ($tabs as $k=>$label) {
      $active = ($tab === $k) ? ' nav-tab-active' : '';
      echo '<a class="nav-tab'.$active.'" href="'.esc_url(admin_url('admin.php?page=miapp-booking&tab='.$k)).'">'.esc_html($label).'</a>';
    }
    echo '</h2>';

    // Render tab
    if ($tab === 'google') $this->render_google();
    else if ($tab === 'branding') $this->render_branding();
    else if ($tab === 'emails') $this->render_emails();
    else if ($tab === 'shortcodes') $this->render_shortcodes();
    else $this->render_rules();

    echo '</div>';
  }

  private function render_rules() {
    $doctor = esc_attr(get_option('miapp_doctor_name','Mia'));
    $minh = intval(get_option('miapp_min_hours_change',24));
    $startH = intval(get_option('miapp_day_start_hour',9));
    $endH = intval(get_option('miapp_day_end_hour',18));
    $days = esc_attr(get_option('miapp_days_enabled','1,2,3,4,5'));

    echo '<form method="post">';
    wp_nonce_field('miapp_save');
    echo '<input type="hidden" name="miapp_save" value="1" />';

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th><label>Nombre doctora</label></th><td><input class="regular-text" name="doctor_name" value="'.$doctor.'"></td></tr>';
    echo '<tr><th><label>Horas mínimas para cancelar/reagendar</label></th><td><input type="number" min="0" name="min_hours_change" value="'.$minh.'"></td></tr>';
    echo '<tr><th><label>Hora inicio (0-23)</label></th><td><input type="number" min="0" max="23" name="day_start_hour" value="'.$startH.'"></td></tr>';
    echo '<tr><th><label>Hora fin (0-23)</label></th><td><input type="number" min="0" max="23" name="day_end_hour" value="'.$endH.'"><p class="description">Si ya pasó esta hora hoy, el calendario arranca desde el siguiente día habilitado.</p></td></tr>';
    echo '<tr><th><label>Días habilitados</label></th><td><input class="regular-text" name="days_enabled" value="'.$days.'"><p class="description">1=Lun..7=Dom. Ej: 1,2,3,4,5</p></td></tr>';
    echo '</table>';

    submit_button('Guardar');
    echo '</form>';
  }

  private function render_google() {
    $cid = esc_attr(get_option('miapp_google_client_id',''));
    $sec = esc_attr(get_option('miapp_google_client_secret',''));
    $cal = esc_attr(get_option('miapp_calendar_id','primary'));

    echo '<form method="post">';
    wp_nonce_field('miapp_save');
    echo '<input type="hidden" name="miapp_save" value="1" />';

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th>Client ID</th><td><input class="regular-text" name="google_client_id" value="'.$cid.'"></td></tr>';
    echo '<tr><th>Client Secret</th><td><input class="regular-text" type="password" name="google_client_secret" value="'.$sec.'"></td></tr>';
    echo '<tr><th>Calendar ID</th><td><input class="regular-text" name="calendar_id" value="'.$cal.'"></td></tr>';
    echo '</table>';

    try {
      $g = new Miapp_Google();
      echo $g->isConnected()
        ? '<p><b>Estado:</b> Conectado ✅</p>'
        : '<p><a class="button button-secondary" href="'.esc_url($g->authUrl()).'">Conectar Google</a></p>';
    } catch (Exception $e) {
      echo '<p class="description">Configura Client ID/Secret y guarda.</p>';
    }

    submit_button('Guardar');
    echo '</form>';
  }

  private function render_branding() {
    $v = Miapp_Settings::get();
    $logo = esc_attr(get_option('miapp_brand_logo_url',''));

    echo '<form method="post">';
    wp_nonce_field('miapp_save');
    echo '<input type="hidden" name="miapp_save" value="1" />';

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th><label>Color primario</label></th><td><input class="regular-text" name="brand_primary" value="'.esc_attr($v['brand_primary']).'"><p class="description">Hex: #RRGGBB</p></td></tr>';
    echo '<tr><th><label>Color fondo</label></th><td><input class="regular-text" name="brand_bg" value="'.esc_attr($v['brand_bg']).'"></td></tr>';
    echo '<tr><th><label>Color texto</label></th><td><input class="regular-text" name="brand_text" value="'.esc_attr($v['brand_text']).'"></td></tr>';
    echo '<tr><th><label>Radio (6–24)</label></th><td><input type="number" min="6" max="24" name="brand_radius" value="'.esc_attr($v['brand_radius']).'"></td></tr>';
    echo '<tr><th><label>Logo URL</label></th><td><input class="regular-text" name="brand_logo" value="'.$logo.'"><p class="description">URL del media uploader.</p></td></tr>';
    echo '</table>';

    submit_button('Guardar');
    echo '</form>';
  }

  private function render_emails() {
    $subC = esc_attr(get_option('miapp_email_subject_confirm',''));
    $subX = esc_attr(get_option('miapp_email_subject_cancel',''));
    $subR = esc_attr(get_option('miapp_email_subject_reschedule',''));

    $tplC = esc_textarea(get_option('miapp_email_tpl_confirm',''));
    $tplX = esc_textarea(get_option('miapp_email_tpl_cancel',''));
    $tplR = esc_textarea(get_option('miapp_email_tpl_reschedule',''));

    echo '<p class="description">Variables: <code>{{patient_name}}</code>, <code>{{doctor_name}}</code>, <code>{{date_human}}</code>, <code>{{meet_block}}</code>, <code>{{buttons_block}}</code></p>';

    echo '<form method="post">';
    wp_nonce_field('miapp_save');
    echo '<input type="hidden" name="miapp_save" value="1" />';

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th>Asunto confirmación</th><td><input class="regular-text" name="sub_confirm" value="'.$subC.'"></td></tr>';
    echo '<tr><th>Plantilla confirmación</th><td><textarea name="tpl_confirm" rows="8" class="large-text">'.$tplC.'</textarea></td></tr>';
    echo '<tr><th>Asunto cancelación</th><td><input class="regular-text" name="sub_cancel" value="'.$subX.'"></td></tr>';
    echo '<tr><th>Plantilla cancelación</th><td><textarea name="tpl_cancel" rows="8" class="large-text">'.$tplX.'</textarea></td></tr>';
    echo '<tr><th>Asunto reagendar</th><td><input class="regular-text" name="sub_reschedule" value="'.$subR.'"></td></tr>';
    echo '<tr><th>Plantilla reagendar</th><td><textarea name="tpl_reschedule" rows="8" class="large-text">'.$tplR.'</textarea></td></tr>';
    echo '</table>';

    submit_button('Guardar');
    echo '</form>';
  }

  private function render_shortcodes() {
    echo '<h2>Shortcodes</h2>';
    echo '<ul style="list-style:disc;padding-left:20px">';
    echo '<li><code>[miapp_booking]</code> — agenda embebida</li>';
    echo '<li><code>[miapp_booking_button]</code> — botón con modal</li>';
    echo '<li><code>[miapp_patient]</code> — panel del paciente</li>';
    echo '<li><code>[miapp_login]</code> — login</li>';
    echo '<li><code>[miapp_register]</code> — registro</li>';
    echo '</ul>';
    echo '<p><b>Ejemplo:</b> <code>[miapp_booking_button label="Agendar" style="primary" modal_title="Agenda tu cita"]</code></p>';
  }
}
