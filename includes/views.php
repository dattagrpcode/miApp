<?php
if (!defined('ABSPATH')) exit;

class Miapp_Views {

  /**
   * Render a view template.
   * Allows theme override: /wp-content/themes/<theme>/miapp/<template>.php
   */
  public static function render(string $template, array $vars = []): string {
    $template = preg_replace('/[^a-zA-Z0-9\-_]/', '', $template);
    $themePath = get_stylesheet_directory() . '/miapp/' . $template . '.php';
    $pluginPath = MIAPP_DIR . 'views/' . $template . '.php';

    $path = file_exists($themePath) ? $themePath : $pluginPath;
    if (!file_exists($path)) {
      return '<!-- Miapp view not found: ' . esc_html($template) . ' -->';
    }

    ob_start();
    extract($vars, EXTR_SKIP);
    include $path;
    return ob_get_clean();
  }
}
