<?php
if (!defined('ABSPATH')) exit;

class Miapp_Mail {
  public static function sendHtmlWithIcs($to, $subject, $html, $ics) {
    $boundary = wp_generate_password(24, false, false);
    $headers = [
      "MIME-Version: 1.0",
      "Content-Type: multipart/mixed; boundary=\"$boundary\"",
    ];

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $html . "\r\n\r\n";

    if ($ics) {
      $body .= "--$boundary\r\n";
      $body .= "Content-Type: text/calendar; method=REQUEST; charset=UTF-8; name=\"invite.ics\"\r\n";
      $body .= "Content-Disposition: attachment; filename=\"invite.ics\"\r\n\r\n";
      $body .= $ics . "\r\n";
    }
    $body .= "--$boundary--";

    return wp_mail($to, $subject, $body, $headers);
  }

  public static function sendHtml($to, $subject, $html) {
    return wp_mail($to, $subject, $html, ["Content-Type: text/html; charset=UTF-8"]);
  }
}
