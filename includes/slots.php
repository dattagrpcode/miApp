<?php
if (!defined('ABSPATH')) exit;

class Miapp_Slots {
  public static function build($fromIso, $toIso, $timezone, $durationMin, $bufferMin) {
    $startHour = intval(get_option('miapp_day_start_hour', 9));
    $endHour   = intval(get_option('miapp_day_end_hour', 18));
    $daysEnabled = array_map('intval', explode(',', get_option('miapp_days_enabled','1,2,3,4,5')));

    $tz = new DateTimeZone($timezone);
    $from = new DateTime($fromIso, $tz);
    $to   = new DateTime($toIso, $tz);

    $slots = [];
    $cursor = (clone $from);

    while ($cursor < $to) {
      $dow = intval($cursor->format('N'));
      if (in_array($dow, $daysEnabled, true)) {
        $dayStart = (clone $cursor)->setTime($startHour, 0, 0);
        $dayEnd   = (clone $cursor)->setTime($endHour, 0, 0);
        $t = clone $dayStart;

        while ($t < $dayEnd) {
          $s = clone $t;
          $e = (clone $t)->modify("+{$durationMin} minutes");
          if ($e <= $dayEnd) {
            $slots[] = ['start'=>$s->format(DateTime::ATOM), 'end'=>$e->format(DateTime::ATOM)];
          }
          $t = $t->modify("+".($durationMin+$bufferMin)." minutes");
        }
      }
      $cursor = $cursor->modify('+1 day')->setTime(0,0,0);
    }
    return $slots;
  }

  public static function subtractBusy($slots, $busyRanges) {
    $out=[];
    foreach ($slots as $s) {
      $ss=strtotime($s['start']); $se=strtotime($s['end']);
      $ok=true;
      foreach ($busyRanges as $b) {
        $bs=strtotime($b['start']); $be=strtotime($b['end']);
        if (($ss < $be) && ($se > $bs)) { $ok=false; break; }
      }
      if ($ok) $out[]=$s;
    }
    return $out;
  }
}
