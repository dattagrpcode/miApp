<?php
if (!defined('ABSPATH')) exit;

class Miapp_ICS {
  public static function build($summary, $description, $startIso, $endIso, $uid, $organizerEmail) {
    $dtStart = gmdate('Ymd\THis\Z', strtotime($startIso));
    $dtEnd   = gmdate('Ymd\THis\Z', strtotime($endIso));
    $dtStamp = gmdate('Ymd\THis\Z');
    $lines = [
      "BEGIN:VCALENDAR","VERSION:2.0","PRODID:-//Miapp Booking//ES","CALSCALE:GREGORIAN","METHOD:REQUEST",
      "BEGIN:VEVENT",
      "UID:$uid",
      "DTSTAMP:$dtStamp",
      "DTSTART:$dtStart",
      "DTEND:$dtEnd",
      "SUMMARY:".self::esc($summary),
      "DESCRIPTION:".self::esc($description),
      "ORGANIZER:MAILTO:".self::esc($organizerEmail),
      "END:VEVENT",
      "END:VCALENDAR"
    ];
    return implode("\r\n",$lines)."\r\n";
  }
  private static function esc($s) {
    return str_replace(["\\",";",",","\n","\r"],["\\\\","\;","\,","\\n",""], $s);
  }
}
