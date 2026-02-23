<?php
if (!defined('ABSPATH')) exit;

class Miapp_Templates {
  public static function doctorName(): string {
    return get_option('miapp_doctor_name', 'Mia');
  }
  public static function brandColor(): string {
    $c = get_option('miapp_brand_primary_color', '#111111');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#111111';
  }
  public static function logoUrl(): string {
    return esc_url_raw(get_option('miapp_brand_logo_url', ''));
  }

  public static function wrap(string $innerHtml): string {
    $color = self::brandColor();
    $logo = self::logoUrl();
    $header = $logo
      ? "<img src=\"".esc_url($logo)."\" style=\"max-width:180px;height:auto\" alt=\"Logo\"/>"
      : "<div style=\"font-size:18px;font-weight:700;color:$color;\">".esc_html(self::doctorName())."</div>";

    return "
      <div style=\"font-family:Arial,Helvetica,sans-serif;background:#f7f7f7;padding:24px;\">
        <div style=\"max-width:640px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e9e9e9;\">
          <div style=\"padding:18px 22px;border-bottom:1px solid #eee;\">$header</div>
          <div style=\"padding:22px;color:#222;line-height:1.45;font-size:14px;\">$innerHtml</div>
          <div style=\"padding:16px 22px;border-top:1px solid #eee;color:#666;font-size:12px;\">
            Si necesitas apoyo o cambios, usa los botones incluidos. (Cambios permitidos hasta 24h antes.)
          </div>
        </div>
      </div>
    ";
  }

  public static function button(string $label, string $url): string {
    $color = self::brandColor();
    return "<a href=\"".esc_url($url)."\" style=\"display:inline-block;background:$color;color:#fff;text-decoration:none;padding:12px 16px;border-radius:10px;font-weight:600;\">".esc_html($label)."</a>";
  }

  public static function render(string $tpl, array $vars): string {
    foreach ($vars as $k => $v) $tpl = str_replace('{{'.$k.'}}', $v, $tpl);
    return $tpl;
  }
}
