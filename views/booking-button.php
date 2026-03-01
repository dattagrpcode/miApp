<?php if (!defined('ABSPATH')) exit; ?>
<button class="<?php echo esc_attr($btnClass ?? 'miapp-btn miapp-btn--primary'); ?>"
        data-miapp-open="<?php echo esc_attr($id ?? 'miapp-btn'); ?>">
  <?php echo esc_html($label ?? 'Agendar cita'); ?>
</button>
