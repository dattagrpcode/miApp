<?php
if (!defined('ABSPATH')) exit;

class Miapp_Auth {
  public function init() {
    add_shortcode('miapp_login', [$this,'loginForm']);
    add_shortcode('miapp_register', [$this,'registerForm']);

    add_action('init', function(){
      if (!get_role('miapp_patient')) {
        add_role('miapp_patient', 'Paciente', ['read'=>true]);
      }
    });
  }

  public function loginForm() {
    if (is_user_logged_in()) return '<div class="miapp-card"><p>Ya iniciaste sesión ✅</p></div>';

    $out = '<div class="miapp-card"><h3>Iniciar sesión</h3>';
    $out .= wp_login_form(['echo'=>false, 'remember'=>true]);
    $out .= '<p>¿No tienes cuenta? '.do_shortcode('[miapp_register]').'</p></div>';
    return $out;
  }

  public function registerForm() {
    if (is_user_logged_in()) return '';
    if (isset($_POST['miapp_register']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'],'miapp_register')) {
      $email = sanitize_email($_POST['email'] ?? '');
      $name = sanitize_text_field($_POST['name'] ?? '');
      $pass = $_POST['password'] ?? '';

      if (!$email || !$pass || !$name) {
        return '<div class="miapp-card"><p style="color:#b00020;">Faltan datos.</p></div>'.$this->registerForm();
      }
      if (email_exists($email)) {
        return '<div class="miapp-card"><p style="color:#b00020;">Ese correo ya existe.</p></div>'.$this->registerForm();
      }

      $userId = wp_create_user($email, $pass, $email);
      if (is_wp_error($userId)) {
        return '<div class="miapp-card"><p style="color:#b00020;">No se pudo crear el usuario.</p></div>'.$this->registerForm();
      }
      wp_update_user(['ID'=>$userId, 'display_name'=>$name, 'first_name'=>$name]);
      $u = new WP_User($userId);
      $u->set_role('miapp_patient');

      wp_set_current_user($userId);
      wp_set_auth_cookie($userId);

      return '<div class="miapp-card"><p>Cuenta creada ✅</p></div>';
    }

    $nonce = wp_nonce_field('miapp_register','_wpnonce',true,false);
    return '
      <div class="miapp-card">
        <h4>Crear cuenta</h4>
        <form method="post">
          '.$nonce.'
          <p><input name="name" placeholder="Nombre completo" required></p>
          <p><input name="email" type="email" placeholder="Correo" required></p>
          <p><input name="password" type="password" placeholder="Contraseña" required></p>
          <p><button name="miapp_register" value="1" type="submit">Crear cuenta</button></p>
        </form>
      </div>
    ';
  }
}
