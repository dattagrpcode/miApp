<?php
if (!defined('ABSPATH')) exit;

use Elementor\Plugin;

add_action('elementor/widgets/register', function($widgets_manager) {
  require_once MIAPP_DIR . 'includes/elementor/widgets/booking.php';
  require_once MIAPP_DIR . 'includes/elementor/widgets/patient-dashboard.php';
  require_once MIAPP_DIR . 'includes/elementor/widgets/mia-dashboard.php';

  $widgets_manager->register(new \Miapp_Elementor_Booking());
  $widgets_manager->register(new \Miapp_Elementor_Patient_Dashboard());
  $widgets_manager->register(new \Miapp_Elementor_Mia_Dashboard());
});

add_action('elementor/frontend/after_register_scripts', function() {
  wp_register_script('miapp-js', MIAPP_URL . 'assets/app.js', [], '0.6.0', true);
  wp_register_style('miapp-css', MIAPP_URL . 'assets/app.css', [], '0.6.0');
});
