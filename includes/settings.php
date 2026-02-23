<?php
if (!defined('ABSPATH')) exit;

class Miapp_Settings {
  const OPT = 'miapp_settings';

  public static function defaults() {
    return [
      'brand_primary' => '#111111',
      'brand_bg'      => '#ffffff',
      'brand_text'    => '#111111',
      'brand_radius'  => '14',
    ];
  }

  public function init() {
    add_action('admin_menu', [$this, 'adminMenu']);
    add_action('admin_init', [$this, 'registerSettings']);
    add_action('wp_enqueue_scripts', [$this, 'enqueueBrandCssVars']);
  }

  public static function get() {
    $v = get_option(self::OPT, []);
    return array_merge(self::defaults(), is_array($v) ? $v : []);
  }

  public function adminMenu() {
    add_menu_page(
      'Miapp',
      'Miapp',
      'manage_options',
      'miapp',
      [$this, 'renderDashboard'],
      'dashicons-calendar-alt'
    );

    add_submenu_page(
      'miapp',
      'Ajustes',
      'Ajustes',
      'manage_options',
      'miapp-settings',
      [$this, 'renderSettings']
    );
  }

  public function registerSettings() {
    register_setting('miapp_settings_group', self::OPT, [
      'type' => 'array',
      'sanitize_callback' => [$this, 'sanitize'],
      'default' => self::defaults(),
    ]);
  }

  public function sanitize($input) {
    $d = self::defaults();
    $out = [];
    $out['brand_primary'] = $this->sanitizeColor($input['brand_primary'] ?? $d['brand_primary']);
    $out['brand_bg']      = $this->sanitizeColor($input['brand_bg'] ?? $d['brand_bg']);
    $out['brand_text']    = $this->sanitizeColor($input['brand_text'] ?? $d['brand_text']);
    $out['brand_radius']  = (string) max(6, min(24, intval($input['brand_radius'] ?? $d['brand_radius'])));
    return $out;
  }

  private function sanitizeColor($c) {
    $c = trim((string)$c);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) return $c;
    return '#111111';
  }

  public function renderDashboard() {
    echo '<div class="wrap"><h1>Miapp</h1><p>Panel principal del plugin.</p></div>';
  }

  public function renderSettings() {
    if (!current_user_can('manage_options')) return;

    $tab = sanitize_text_field($_GET['tab'] ?? 'branding');

    echo '<div class="wrap"><h1>Ajustes Miapp</h1>';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a class="nav-tab '.($tab==='branding'?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=miapp-settings&tab=branding')).'">Branding</a>';
    echo '</h2>';

    if ($tab === 'branding') $this->renderBrandingTab();

    echo '</div>';
  }

  private function renderBrandingTab() {
    $v = self::get();
    ?>
    <form method="post" action="options.php">
      <?php settings_fields('miapp_settings_group'); ?>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="miapp_primary">Color primario</label></th>
          <td><input id="miapp_primary" type="text" name="<?php echo esc_attr(self::OPT); ?>[brand_primary]" value="<?php echo esc_attr($v['brand_primary']); ?>" class="regular-text" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="miapp_bg">Color fondo</label></th>
          <td><input id="miapp_bg" type="text" name="<?php echo esc_attr(self::OPT); ?>[brand_bg]" value="<?php echo esc_attr($v['brand_bg']); ?>" class="regular-text" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="miapp_text">Color texto</label></th>
          <td><input id="miapp_text" type="text" name="<?php echo esc_attr(self::OPT); ?>[brand_text]" value="<?php echo esc_attr($v['brand_text']); ?>" class="regular-text" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="miapp_radius">Radio (6–24)</label></th>
          <td><input id="miapp_radius" type="number" min="6" max="24" name="<?php echo esc_attr(self::OPT); ?>[brand_radius]" value="<?php echo esc_attr($v['brand_radius']); ?>" /></td>
        </tr>
      </table>

      <?php submit_button('Guardar'); ?>
    </form>
    <?php
  }

  public function enqueueBrandCssVars() {
    // Inyecta variables CSS para que el diseñador cambie el look sin tocar CSS
    $v = self::get();
    $css = ":root{"
      ."--miapp-primary: {$v['brand_primary']};"
      ."--miapp-bg: {$v['brand_bg']};"
      ."--miapp-text: {$v['brand_text']};"
      ."--miapp-radius: {$v['brand_radius']}px;"
      ."}";

    // Asegúrate de que el handle coincida con tu wp_enqueue_style del plugin
    wp_register_style('miapp-css', MIAPP_URL.'assets/app.css', [], '0.1.0');
    wp_enqueue_style('miapp-css');
    wp_add_inline_style('miapp-css', $css);
  }
}
