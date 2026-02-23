<?php
if (!defined('ABSPATH')) exit;

class Miapp_Activator {
  const DB_VERSION = 3;

  public static function activate() {
    self::upgrade();
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

    update_option('miapp_db_version', self::DB_VERSION);
  }
}
