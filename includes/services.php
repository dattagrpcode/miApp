<?php
if (!defined('ABSPATH')) exit;

class Miapp_Services {

  public function init() {

    add_action('init', function () {
      register_post_type('miapp_service', [
        'labels' => [
          'name' => 'Servicios',
          'singular_name' => 'Servicio'
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'miapp', // ✅ queda dentro del menú Miapp
        'menu_icon' => 'dashicons-tag',
        'supports' => ['title', 'editor'],
        'capability_type' => ['miapp_service','miapp_services'],
        'map_meta_cap' => true,
      ]);
    });

    add_action('add_meta_boxes', function () {
      add_meta_box(
        'miapp_service_meta',
        'Configuración del servicio',
        [$this, 'metaBox'],
        'miapp_service',
        'normal',
        'high'
      );
    });

    add_action('save_post_miapp_service', [$this, 'saveMeta'], 10, 2);
  }

  public function metaBox($post) {
    $priceCents = intval(get_post_meta($post->ID, '_miapp_price_cents', true) ?: 0);
    $dur = intval(get_post_meta($post->ID, '_miapp_duration_min', true) ?: 60);
    $buf = intval(get_post_meta($post->ID, '_miapp_buffer_min', true) ?: 10);
    $modes = (string) (get_post_meta($post->ID, '_miapp_modes', true) ?: 'VIRTUAL,PRESENTIAL');
    $indications = (string) (get_post_meta($post->ID, '_miapp_indications', true) ?: '');
    $providerId = (string) (get_post_meta($post->ID, '_miapp_service_provider_id', true) ?: '0');

    wp_nonce_field('miapp_service_meta', 'miapp_service_meta_nonce');

    // Providers activos
    $providers = get_posts([
      'post_type' => 'miapp_provider',
      'numberposts' => -1,
      'post_status' => 'publish',
      'meta_key' => '_miapp_provider_active',
      'meta_value' => '1',
      'orderby' => 'title',
      'order' => 'ASC',
    ]);

    echo '<p><label>Precio (COP):</label><br>
      <input type="number" min="0" step="1" name="miapp_price_cop" value="'.esc_attr(intval($priceCents / 100)).'">
    </p>';

    echo '<p><label>Duración (min):</label><br>
      <input type="number" min="15" max="240" step="5" name="miapp_duration_min" value="'.esc_attr($dur).'">
    </p>';

    echo '<p><label>Buffer (min):</label><br>
      <input type="number" min="0" max="60" step="5" name="miapp_buffer_min" value="'.esc_attr($buf).'">
    </p>';

    echo '<p><label>Modalidades permitidas (separadas por coma):</label><br>
      <input type="text" name="miapp_modes" value="'.esc_attr($modes).'" placeholder="VIRTUAL,PRESENTIAL">
    </p>';

    echo '<p><label>Indicaciones (se muestran en el resumen antes de confirmar):</label><br>
      <textarea name="miapp_indications" rows="4" style="width:100%;">'.esc_textarea($indications).'</textarea>
    </p>';

    echo '<p><label>Profesional asignado:</label><br><select name="miapp_service_provider_id">';
    echo '<option value="0" '.selected($providerId, '0', false).'>Por defecto (primero activo)</option>';
    foreach ($providers as $pr) {
      echo '<option value="'.intval($pr->ID).'" '.selected($providerId, (string)$pr->ID, false).'>'.esc_html($pr->post_title).'</option>';
    }
    echo '</select></p>';

    echo '<p class="description">
      Precio se guarda en centavos. Modalidades: VIRTUAL, PRESENTIAL.
    </p>';
  }

  public function saveMeta($postId, $post) {
    if (!isset($_POST['miapp_service_meta_nonce']) || !wp_verify_nonce($_POST['miapp_service_meta_nonce'], 'miapp_service_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $postId)) return;

    $priceCop = intval($_POST['miapp_price_cop'] ?? 0);
    if ($priceCop < 0) $priceCop = 0;

    $duration = intval($_POST['miapp_duration_min'] ?? 60);
    if ($duration < 15) $duration = 15;
    if ($duration > 240) $duration = 240;

    $buffer = intval($_POST['miapp_buffer_min'] ?? 10);
    if ($buffer < 0) $buffer = 0;
    if ($buffer > 60) $buffer = 60;

    $modes = sanitize_text_field($_POST['miapp_modes'] ?? 'VIRTUAL,PRESENTIAL');
    $providerId = (string) intval($_POST['miapp_service_provider_id'] ?? 0);

    update_post_meta($postId, '_miapp_price_cents', $priceCop * 100);
    update_post_meta($postId, '_miapp_duration_min', $duration);
    update_post_meta($postId, '_miapp_buffer_min', $buffer);
    update_post_meta($postId, '_miapp_modes', $modes);
    update_post_meta($postId, '_miapp_service_provider_id', $providerId);
  }

  public static function listServicesByProvider(int $providerId): array {
    $args = [
      'post_type'=>'miapp_service',
      'numberposts'=>-1,
      'post_status'=>'publish',
      'meta_key'=>'_miapp_service_provider_id',
      'meta_value'=> (string)$providerId,
      'orderby'=>'title',
      'order'=>'ASC',
    ];
    $posts = get_posts($args);
    return array_map([self::class,'mapServicePost'], $posts);
  }

  private static function mapServicePost($p): array {
      $price = intval(get_post_meta($p->ID,'_miapp_price_cents',true));
      $dur = intval(get_post_meta($p->ID,'_miapp_duration_min',true));
      $buf = intval(get_post_meta($p->ID,'_miapp_buffer_min',true));
      $modes = get_post_meta($p->ID,'_miapp_modes',true) ?: 'VIRTUAL,PRESENTIAL';
      $ind = (string)(get_post_meta($p->ID,'_miapp_indications',true) ?: '');
      $provider = intval(get_post_meta($p->ID,'_miapp_service_provider_id',true));
      return [
        'id'=>$p->ID,
        'provider_id'=>$provider,
        'name'=>get_the_title($p),
        'description'=>wp_strip_all_tags($p->post_content),
        'price_cents'=>$price,
        'duration_min'=>$dur,
        'buffer_min'=>$buf,
        'modes'=>array_values(array_filter(array_map('trim', explode(',',$modes)))),
        'indications'=>$ind,
      ];
  }

  public static function listServices(): array {
    $posts = get_posts([
      'post_type' => 'miapp_service',
      'numberposts' => -1,
      'post_status' => 'publish'
    ]);

    return array_map(function ($p) {
      $price = intval(get_post_meta($p->ID, '_miapp_price_cents', true));
      $dur = intval(get_post_meta($p->ID, '_miapp_duration_min', true));
      $buf = intval(get_post_meta($p->ID, '_miapp_buffer_min', true));
      $modesRaw = (string) (get_post_meta($p->ID, '_miapp_modes', true) ?: 'VIRTUAL,PRESENTIAL');
      $providerId = intval(get_post_meta($p->ID, '_miapp_service_provider_id', true) ?: 0);

      $modes = array_values(array_filter(array_map('trim', explode(',', $modesRaw))));

      return [
        'id' => intval($p->ID),
        'name' => get_the_title($p),
        'description' => wp_strip_all_tags($p->post_content),
        'price_cents' => $price,
        'duration_min' => $dur,
        'buffer_min' => $buf,
        'modes' => $modes,
        'provider_id' => $providerId,
        'indications' => wp_kses_post(get_post_meta($p->ID, '_miapp_indications', true) ?: ''),
      ];
    }, $posts);
  }

  public static function getService(int $id): ?array {
    $p = get_post($id);
    if (!$p || $p->post_type !== 'miapp_service') return null;

    $price = intval(get_post_meta($id, '_miapp_price_cents', true));
    $dur = intval(get_post_meta($id, '_miapp_duration_min', true));
    $buf = intval(get_post_meta($id, '_miapp_buffer_min', true));
    $modesRaw = (string) (get_post_meta($id, '_miapp_modes', true) ?: 'VIRTUAL,PRESENTIAL');
    $providerId = intval(get_post_meta($id, '_miapp_service_provider_id', true) ?: 0);

    $modes = array_values(array_filter(array_map('trim', explode(',', $modesRaw))));

    return [
      'id' => $id,
      'name' => get_the_title($p),
      'description' => wp_strip_all_tags($p->post_content),
      'price_cents' => $price,
      'duration_min' => $dur,
      'buffer_min' => $buf,
      'modes' => $modes,
      'provider_id' => $providerId,
      'indications' => wp_kses_post(get_post_meta($id, '_miapp_indications', true) ?: ''),
    ];
  }
}
