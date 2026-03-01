<?php
if (!defined('ABSPATH')) exit;

use Elementor\Widget_Base;

class Miapp_Elementor_Mia_Dashboard extends Widget_Base {
  public function get_name() { return 'miapp_mia_dashboard'; }
  public function get_title() { return 'Miapp - Dashboard Mia'; }
  public function get_icon() { return 'eicon-person'; }
  public function get_categories() { return ['general']; }

  public function get_style_depends() { return ['miapp-css']; }
  public function get_script_depends() { return ['miapp-js']; }

  protected function render() {
    wp_enqueue_script('miapp-js');
    wp_enqueue_style('miapp-css');
    echo do_shortcode('[miapp_mia]');
  }
}
