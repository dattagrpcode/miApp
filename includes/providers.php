<?php
if (!defined('ABSPATH')) exit;

class Miapp_Providers {
  public function init() {
    add_action('init', [$this, 'registerCpt']);
    add_action('init', [$this, 'registerTax']);
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

  
  public function registerTax() {
    register_taxonomy('miapp_specialty', ['miapp_provider'], [
      'labels' => [
        'name' => 'Especialidades',
        'singular_name' => 'Especialidad',
      ],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => true,
      'show_admin_column' => true,
      'hierarchical' => false,
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
    $userId = get_post_meta($post->ID, '_miapp_provider_user_id', true);


    echo '<p><label>Email profesional</label><br><input class="regular-text" name="miapp_provider_email" value="'.esc_attr($email).'" /></p>';
    // Vincular a usuario WP (rol miapp_practitioner)
    $users = get_users(['role__in'=>['miapp_practitioner','administrator'], 'orderby'=>'display_name', 'order'=>'ASC']);
    echo '<p><label>Usuario (panel profesional)</label><br><select name="miapp_provider_user_id"><option value="">— Sin asignar —</option>';
    foreach($users as $u){ echo '<option value="'.esc_attr($u->ID).'" '.selected((string)$userId,(string)$u->ID,false).'>'.esc_html($u->display_name.' ('.$u->user_email.')').'</option>'; }
    echo '</select><br><span class="description">Este usuario verá y gestionará sus citas/servicios en el dashboard.</span></p>';
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
    $uid = intval($_POST['miapp_provider_user_id'] ?? 0);
    update_post_meta($postId, '_miapp_provider_user_id', $uid ? (string)$uid : '');
    if($uid){ update_user_meta($uid, 'miapp_provider_id', (string)$postId); }

  }

  public static function listProviders($onlyActive=true): array {
    $args = [
      'post_type' => 'miapp_provider',
      'numberposts' => -1,
      'post_status' => 'publish',
      'orderby' => 'title',
      'order' => 'ASC',
    ];
    if($onlyActive){
      $args['meta_key'] = '_miapp_provider_active';
      $args['meta_value'] = '1';
    }
    $posts = get_posts($args);
    return array_map(function($p){
      $spec = wp_get_post_terms($p->ID, 'miapp_specialty', ['fields'=>'names']);
      return [
        'id' => intval($p->ID),
        'name' => get_the_title($p),
        'specialty' => $spec ? $spec[0] : '',
      ];
    }, $posts);
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
