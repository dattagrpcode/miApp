<?php
if (!defined('ABSPATH')) exit;

class Miapp_Providers {
  public function init() {
    add_action('init', [$this, 'registerCpt']);
    add_action('add_meta_boxes', [$this, 'metaBoxes']);
    add_action('save_post_miapp_provider', [$this, 'saveMeta'], 10, 2);
  }

  public function registerCpt() {
    register_post_type('miapp_provider', [
      'labels' => [
        'name' => 'Profesionales',
        'singular_name' => 'Profesional',
      ],
      'public' => false,
      'show_ui' => true,
      'menu_icon' => 'dashicons-id',
      'supports' => ['title', 'editor', 'thumbnail'],
      'capability_type' => 'post',
      'show_in_menu' => 'miapp',
    ]);
  }

  public function metaBoxes() {
    add_meta_box('miapp_provider_cfg', 'Configuración', [$this, 'renderMeta'], 'miapp_provider', 'normal', 'high');
  }

  public function renderMeta($post) {
    wp_nonce_field('miapp_provider_save', 'miapp_provider_nonce');

    $email = get_post_meta($post->ID, '_miapp_provider_email', true);
    $cal   = get_post_meta($post->ID, '_miapp_provider_calendar_id', true);
    $meet  = get_post_meta($post->ID, '_miapp_provider_meet', true) ?: 'GOOGLE_MEET';
    $active = get_post_meta($post->ID, '_miapp_provider_active', true);
    $active = ($active === '' ? '1' : $active);

    echo '<p><label>Email profesional</label><br><input class="regular-text" name="miapp_provider_email" value="'.esc_attr($email).'" /></p>';
    echo '<p><label>Google Calendar ID</label><br><input class="regular-text" name="miapp_provider_calendar_id" value="'.esc_attr($cal).'" placeholder="primary o algo@group.calendar.google.com" /></p>';

    echo '<p><label>Teleconsulta</label><br><select name="miapp_provider_meet">
      <option value="GOOGLE_MEET" '.selected($meet,'GOOGLE_MEET',false).'>Google Meet</option>
      <option value="NONE" '.selected($meet,'NONE',false).'>No generar link</option>
    </select></p>';

    echo '<p><label><input type="checkbox" name="miapp_provider_active" value="1" '.checked($active,'1',false).' /> Activo</label></p>';
  }

  public function saveMeta($postId, $post) {
    if (!isset($_POST['miapp_provider_nonce']) || !wp_verify_nonce($_POST['miapp_provider_nonce'], 'miapp_provider_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $postId)) return;

    update_post_meta($postId, '_miapp_provider_email', sanitize_email($_POST['miapp_provider_email'] ?? ''));
    update_post_meta($postId, '_miapp_provider_calendar_id', sanitize_text_field($_POST['miapp_provider_calendar_id'] ?? ''));
    update_post_meta($postId, '_miapp_provider_meet', sanitize_text_field($_POST['miapp_provider_meet'] ?? 'GOOGLE_MEET'));
    update_post_meta($postId, '_miapp_provider_active', isset($_POST['miapp_provider_active']) ? '1' : '0');
  }

  public static function get_default_provider_id() {
    $providers = get_posts([
      'post_type' => 'miapp_provider',
      'numberposts' => 1,
      'meta_key' => '_miapp_provider_active',
      'meta_value' => '1',
      'orderby' => 'ID',
      'order' => 'ASC',
    ]);
    return $providers ? intval($providers[0]->ID) : 0;
  }
}
