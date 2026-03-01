<?php
if (!defined('ABSPATH')) exit;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

class Miapp_Elementor_Booking extends Widget_Base {
  public function get_name() { return 'miapp_booking'; }
  public function get_title() { return 'Miapp - Agendamiento'; }
  public function get_icon() { return 'eicon-calendar'; }
  public function get_categories() { return ['general']; }

  public function get_style_depends() { return ['miapp-css']; }
  public function get_script_depends() { return ['miapp-js']; }

  protected function register_controls() {
    $this->start_controls_section('content', ['label'=>'Contenido','tab'=>Controls_Manager::TAB_CONTENT]);

    $this->add_control('label', [
      'label'=>'Texto del botón',
      'type'=>Controls_Manager::TEXT,
      'default'=>'Agendar cita'
    ]);

    $this->add_control('modal_title', [
      'label'=>'Título del modal',
      'type'=>Controls_Manager::TEXT,
      'default'=>'Agenda tu cita'
    ]);

    $this->add_control('style', [
      'label'=>'Estilo del botón',
      'type'=>Controls_Manager::SELECT,
      'default'=>'primary',
      'options'=>[
        'primary'=>'Primario',
        'outline'=>'Outline',
        'link'=>'Link'
      ]
    ]);

    $this->end_controls_section();

    $this->start_controls_section('design', ['label'=>'Diseño','tab'=>Controls_Manager::TAB_STYLE]);

    $this->add_control('primary', [
      'label'=>'Color primario',
      'type'=>Controls_Manager::COLOR,
      'default'=>'#111111'
    ]);

    $this->add_control('bg', [
      'label'=>'Fondo (cards/modal)',
      'type'=>Controls_Manager::COLOR,
      'default'=>'#ffffff'
    ]);

    $this->add_control('text', [
      'label'=>'Texto',
      'type'=>Controls_Manager::COLOR,
      'default'=>'#111111'
    ]);

    $this->add_control('radius', [
      'label'=>'Radius (px)',
      'type'=>Controls_Manager::NUMBER,
      'default'=>14,
      'min'=>0,
      'max'=>40,
    ]);

    $this->end_controls_section();
  }

  protected function render() {
    $s = $this->get_settings_for_display();

    wp_enqueue_script('miapp-js');
    wp_enqueue_style('miapp-css');

    $vars = sprintf('--miapp-primary:%s;--miapp-card-bg:%s;--miapp-text:%s;--miapp-radius:%dpx;',
      esc_attr($s['primary']),
      esc_attr($s['bg']),
      esc_attr($s['text']),
      intval($s['radius'])
    );

    echo '<div class="miapp" style="'.esc_attr($vars).'">';
    echo do_shortcode(sprintf('[miapp_booking_button label="%s" style="%s" modal_title="%s"]',
      esc_attr($s['label']),
      esc_attr($s['style']),
      esc_attr($s['modal_title'])
    ));
    echo '</div>';
  }
}
