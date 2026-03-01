<?php
if (!defined('ABSPATH')) exit;

class Miapp_Activator {
  const DB_VERSION = 4;

  public static function activate() {
    self::upgrade();
    self::ensureRoles();
  }

  private static function ensureRoles() {
    // Capability used across Miapp admin + REST practitioner endpoints
    $caps = [
      'read' => true,
      'miapp_manage' => true,
    ];

    // Create / update practitioner role
    $role = get_role('miapp_practitioner');
    if (!$role) {
      $role = add_role('miapp_practitioner', 'Miapp Practitioner', $caps);
    } else {
      foreach ($caps as $k=>$v) { $role->add_cap($k, $v); }
    }

    // Grant caps to administrators too
    $admin = get_role('administrator');
    if ($admin) {
      $admin->add_cap('miapp_manage');
    }

    // Grant CPT caps (services + providers) to practitioner + admin
    $cptCaps = [
      // Services
      'edit_miapp_service','read_miapp_service','delete_miapp_service',
      'edit_miapp_services','edit_others_miapp_services','publish_miapp_services',
      'read_private_miapp_services','delete_miapp_services','delete_private_miapp_services',
      'delete_published_miapp_services','delete_others_miapp_services',
      'edit_private_miapp_services','edit_published_miapp_services',
      // Providers
      'edit_miapp_provider','read_miapp_provider','delete_miapp_provider',
      'edit_miapp_providers','edit_others_miapp_providers','publish_miapp_providers',
      'read_private_miapp_providers','delete_miapp_providers','delete_private_miapp_providers',
      'delete_published_miapp_providers','delete_others_miapp_providers',
      'edit_private_miapp_providers','edit_published_miapp_providers',
    ];

    foreach (['miapp_practitioner','administrator'] as $rname) {
      $r = get_role($rname);
      if ($r) { foreach ($cptCaps as $c) { $r->add_cap($c); } }
    }
  }


  public static function upgrade() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $appt = $wpdb->prefix.'miapp_appointments';
    $opt  = $wpdb->prefix.'miapp_options';

    // ✅ Tabla de citas (incluye provider_id + session_number)
    $sql1 = "CREATE TABLE $appt (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      status VARCHAR(20) NOT NULL,
      payment_status VARCHAR(20) NOT NULL DEFAULT 'UNPAID',
      attendance_status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
      user_id BIGINT UNSIGNED NOT NULL,
      service_id BIGINT UNSIGNED NOT NULL,
      provider_id BIGINT UNSIGNED NULL,
      session_number INT NOT NULL DEFAULT 1,
      service_name VARCHAR(200) NOT NULL,
      service_price_cents INT NOT NULL,
      start_at DATETIME NOT NULL,
      end_at DATETIME NOT NULL,
      timezone VARCHAR(64) NOT NULL,
      mode VARCHAR(20) NOT NULL,
      calendar_provider VARCHAR(20) NOT NULL,
      calendar_id VARCHAR(200) NOT NULL,
      calendar_event_id VARCHAR(200) NULL,
      calendar_ical_uid VARCHAR(200) NULL,
      conference_provider VARCHAR(40) NULL,
      conference_join_url TEXT NULL,
      cancel_token VARCHAR(128) NULL,
      reschedule_token VARCHAR(128) NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NULL,
      last_status_changed_at DATETIME NULL,
      last_rescheduled_at DATETIME NULL,
      reschedule_count INT NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      KEY idx_user (user_id),
      KEY idx_start (start_at),
      KEY idx_cancel (cancel_token),
      KEY idx_reschedule (reschedule_token),
      KEY idx_user_session (user_id, session_number)
    ) $charset;";

    // ✅ Tabla de opciones
    $sql2 = "CREATE TABLE $opt (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      option_key VARCHAR(200) NOT NULL,
      option_value LONGTEXT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_key (option_key)
    ) $charset;";

    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta($sql1);
    dbDelta($sql2);

    
    // Defaults for email templates (only if not set)
    if (!get_option('miapp_email_subject_confirm')) update_option('miapp_email_subject_confirm', 'Confirmación de cita');
    if (!get_option('miapp_email_subject_cancel')) update_option('miapp_email_subject_cancel', 'Cita cancelada');
    if (!get_option('miapp_email_subject_reschedule')) update_option('miapp_email_subject_reschedule', 'Cita reagendada');
    if (!get_option('miapp_email_subject_status')) update_option('miapp_email_subject_status', 'Actualización de tu cita');

    if (!get_option('miapp_email_tpl_confirm')) update_option('miapp_email_tpl_confirm',
      '<p>Hola {{patient_name}},</p><p>Tu cita está confirmada.</p><p><b>Servicio:</b> {{service_name}}<br><b>Fecha:</b> {{date_human}}<br><b>Sesión:</b> #{{session_number}}</p>{{meet_block}}{{buttons_block}}<p>— {{doctor_name}}</p>');
    if (!get_option('miapp_email_tpl_cancel')) update_option('miapp_email_tpl_cancel',
      '<p>Hola {{patient_name}},</p><p>Tu cita ha sido cancelada.</p><p><b>Servicio:</b> {{service_name}}<br><b>Fecha:</b> {{date_human}}<br><b>Sesión:</b> #{{session_number}}</p><p>— {{doctor_name}}</p>');
    if (!get_option('miapp_email_tpl_reschedule')) update_option('miapp_email_tpl_reschedule',
      '<p>Hola {{patient_name}},</p><p>Tu cita fue reagendada.</p><p><b>Servicio:</b> {{service_name}}<br><b>Nueva fecha:</b> {{date_human}}<br><b>Sesión:</b> #{{session_number}}</p>{{meet_block}}<p>— {{doctor_name}}</p>');
    if (!get_option('miapp_email_tpl_status')) update_option('miapp_email_tpl_status',
      '<p>Hola {{patient_name}},</p><p>Actualización de tu cita: <b>{{status_label}}</b>.</p><p><b>Servicio:</b> {{service_name}}<br><b>Fecha:</b> {{date_human}}<br><b>Sesión:</b> #{{session_number}}</p>{{meet_block}}<p>— {{doctor_name}}</p>');

update_option('miapp_db_version', self::DB_VERSION);
  }
}
