<?php
if (!defined('ABSPATH')) exit;

class Miapp_Crypto {
  private static function key() {
    $k = defined('AUTH_KEY') ? AUTH_KEY : wp_salt('auth');
    return hash('sha256', $k, true);
  }
  public static function encrypt($plaintext) {
    if (!$plaintext) return '';
    $key = self::key();
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv.$tag.$ct);
  }
  public static function decrypt($b64) {
    if (!$b64) return '';
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) < 28) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $pt = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? '' : $pt;
  }
  public static function token(): string {
    $b = random_bytes(32);
    return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
  }
}
