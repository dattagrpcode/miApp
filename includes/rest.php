<?php
if (!defined('ABSPATH')) exit;

class Miapp_Rest {

  public function init() {
    add_action('rest_api_init', [$this, 'routes']);
  }

  public function routes() {

    register_rest_route('miapp/v1', '/services', [
      'methods' => 'GET',
      'callback' => [$this, 'services'],
      'permission_callback' => '__return_true',
    ]);

    register_rest_route('miapp/v1', '/availability', [
      'methods' => 'GET',
      'callback' => [$this, 'availability'],
      'permission_callback' => '__return_true',
      'args' => [
        'serviceId' => ['required'=>true],
        'from' => ['required'=>true],
        'to' => ['required'=>true],
        'tz' => ['required'=>false],
      ]
    ]);

    register_rest_route('miapp/v1', '/book', [
      'methods' => 'POST',
      'callback' => [$this, 'book'],
      'permission_callback' => function() { return is_user_logged_in(); },
    ]);

    register_rest_route('miapp/v1', '/cancel', [
      'methods' => 'GET',
      'callback' => [$this, 'cancel'],
      'permission_callback' => '__return_true',
    ]);

    register_rest_route('miapp/v1', '/reschedule', [
      'methods' => 'GET',
      'callback' => [$this, 'reschedule'],
      'permission_callback' => '__return_true',
    ]);

    register_rest_route('miapp/v1', '/me/appointments', [
      'methods' => 'GET',
      'callback' => [$this, 'myAppointments'],
      'permission_callback' => function() { return is_user_logged_in(); },
    ]);

    register_rest_route('miapp/v1', '/me/appointments/(?P<id>\d+)/cancel', [
      'methods' => 'POST',
      'callback' => [$this, 'cancelMine'],
      'permission_callback' => function () { return is_user_logged_in(); },
    ]);

    register_rest_route('miapp/v1', '/me/appointments/(?P<id>\d+)/reschedule', [
      'methods' => 'POST',
      'callback' => [$this, 'rescheduleMine'],
      'permission_callback' => function () { return is_user_logged_in(); },
    ]);
  }

  public function services(WP_REST_Request $req) {
    return new WP_REST_Response(['services' => Miapp_Services::listServices()], 200);
  }

  /**
   * Regla 24h para cancelar/reagendar
   */
  private function canChange(string $startIso): bool {
    try {
      $dt = new DateTime($startIso);
      $now = new DateTime('now', new DateTimeZone('UTC'));
      $diff = $dt->getTimestamp() - $now->getTimestamp();
            $minHours = intval(get_option('miapp_min_hours_change', 24));
      return $diff >= ($minHours * 60 * 60);
    } catch (Exception $e) {
      return false;
    }
  }

  /**
   * Obtiene calendarId según provider del servicio
   */
  private function resolveCalendarIdForService(array $svcDef): string {
    $providerId = intval($svcDef['provider_id'] ?? 0);

    if ($providerId > 0) {
      $cal = get_post_meta($providerId, '_miapp_provider_calendar_id', true);
      if ($cal) return $cal;
      return 'primary';
    }

    return get_option('miapp_calendar_id', 'primary');
  }

  /**
   * ✅ Calcula consecutivo de sesión (global por paciente)
   * Basado en citas confirmadas o completadas existentes.
   */
  private function computeSessionNumber(int $userId): int {
    global $wpdb;
    $table = $wpdb->prefix.'miapp_appointments';

    $max = $wpdb->get_var($wpdb->prepare(
      "SELECT MAX(session_number)
       FROM $table
       WHERE user_id=%d AND status IN ('CONFIRMED','COMPLETED')",
      $userId
    ));

    $max = intval($max);
    return $max > 0 ? ($max + 1) : 1;
  }


  /**
   * Parsea días habilitados: guarda 1=Lun..7=Dom. PHP DateTime->format('N') devuelve 1..7.
   */
  private function getEnabledDays(): array {
    $raw = (string) get_option('miapp_days_enabled', '1,2,3,4,5');
    $parts = array_filter(array_map('trim', explode(',', $raw)));
    $days = [];
    foreach ($parts as $p) {
      $n = intval($p);
      if ($n < 1 || $n > 7) continue;
      $days[$n] = true;
    }
    if (empty($days)) {
      $days = [1=>true,2=>true,3=>true,4=>true,5=>true];
    }
    return array_keys($days);
  }

  private function getDayStartHour(): int {
    return max(0, min(23, intval(get_option('miapp_day_start_hour', 9))));
  }

  private function getDayEndHour(): int {
    return max(0, min(23, intval(get_option('miapp_day_end_hour', 18))));
  }

  /**
   * Regla de fecha mínima: no pasado; si hoy ya pasó day_end_hour, mínimo mañana.
   */
  private function computeMinDateTimeUtc(DateTimeZone $tz): DateTime {
    $nowLocal = new DateTime('now', $tz);

    $minLocal = clone $nowLocal;
    $minLocal->setTime(0, 0, 0);

    $endHour = $this->getDayEndHour();
    $cutoff = clone $nowLocal;
    $cutoff->setTime($endHour, 0, 0);

    if ($nowLocal >= $cutoff) {
      $minLocal->modify('+1 day');
      $minLocal->setTime(0, 0, 0);
    }

    // convertir a UTC para comparar con timestamps ISO en UTC
    $minUtc = clone $minLocal;
    $minUtc->setTimezone(new DateTimeZone('UTC'));
    return $minUtc;
  }

  private function validateRangeNotPast(string $fromIso, string $toIso, DateTimeZone $tz): ?WP_REST_Response {
    $fromTs = strtotime($fromIso);
    $toTs   = strtotime($toIso);
    if (!$fromTs || !$toTs) return new WP_REST_Response(['error'=>'Rango inválido.'], 400);
    if ($toTs < $fromTs) return new WP_REST_Response(['error'=>'El rango "to" no puede ser menor que "from".'], 400);

    $minUtc = $this->computeMinDateTimeUtc($tz)->getTimestamp();
    if ($toTs < $minUtc) {
      return new WP_REST_Response(['error'=>'No puedes consultar disponibilidad en fechas pasadas.'], 400);
    }
    return null;
  }

  private function validateStartNotPast(string $startIso, DateTimeZone $tz): ?WP_REST_Response {
    $startTs = strtotime($startIso);
    if (!$startTs) return new WP_REST_Response(['error'=>'Fecha/hora inválida.'], 400);

    $minUtcTs = $this->computeMinDateTimeUtc($tz)->getTimestamp();
    if ($startTs < $minUtcTs) {
      return new WP_REST_Response(['error'=>'No puedes agendar en el pasado.'], 400);
    }
    return null;
  }

  private function isWithinBusinessRules(DateTime $localStart): bool {
    $enabled = $this->getEnabledDays();
    $dow = intval($localStart->format('N')); // 1..7
    if (!in_array($dow, $enabled, true)) return false;

    $h = intval($localStart->format('H'));
    $startH = $this->getDayStartHour();
    $endH   = $this->getDayEndHour();

    // Permitimos slots cuyo inicio esté entre [startH, endH) (endH exclusivo)
    if ($h < $startH) return false;
    if ($h >= $endH) return false;

    return true;
  }
  public function availability(WP_REST_Request $req) {
    $serviceId = intval($req->get_param('serviceId'));
    $from = $req->get_param('from');
    $to = $req->get_param('to');
    $tz = $req->get_param('tz') ?: 'UTC';
    try { $tzObj = new DateTimeZone($tz); } catch (Exception $e) { $tzObj = new DateTimeZone('UTC'); $tz = 'UTC'; }

    try { $tzObj = new DateTimeZone($tz); } catch (Exception $e) { $tzObj = new DateTimeZone('UTC'); $tz = 'UTC'; }

        if ($resp = $this->validateRangeNotPast($from, $to, $tzObj)) return $resp;

$svcDef = Miapp_Services::getService($serviceId);
    if (!$svcDef) return new WP_REST_Response(['error'=>'Servicio inválido.'], 400);

    $calId = $this->resolveCalendarIdForService($svcDef);

    $durMin = intval($svcDef['duration_min']);
    $bufMin = intval($svcDef['buffer_min']);
    $slotStep = 15;

    try {
      $g = new Miapp_Google();
      $cal = $g->svc();

      $events = $cal->events->listEvents($calId, [
        'timeMin' => $from,
        'timeMax' => $to,
        'singleEvents' => true,
        'orderBy' => 'startTime',
      ]);

      $busy = [];
      foreach ($events->getItems() as $ev) {
        $st = $ev->getStart()->getDateTime();
        $en = $ev->getEnd()->getDateTime();
        if (!$st || !$en) continue;
        $busy[] = [
          'start' => strtotime($st),
          'end'   => strtotime($en),
        ];
      }

      $fromTs = strtotime($from);
      $toTs   = strtotime($to);

      $slots = [];
      $need = ($durMin + $bufMin) * 60;

      for ($t = $fromTs; $t <= $toTs; $t += $slotStep * 60) {
        $lt = new DateTime('@'.$t);
        $lt->setTimezone($tzObj);

        // Reglas de negocio: días/hora configurados
        if (!$this->isWithinBusinessRules($lt)) continue;

        $start = $t;
        $end   = $t + $need;

        if ($end > $toTs) continue;

        $overlaps = false;
        foreach ($busy as $b) {
          if ($start < $b['end'] && $end > $b['start']) { $overlaps = true; break; }
        }
        if ($overlaps) continue;

        $slots[] = [
          'start' => gmdate(DateTime::ATOM, $start),
          'end'   => gmdate(DateTime::ATOM, $end),
        ];
      }

      return new WP_REST_Response(['slots'=>$slots], 200);

    } catch (Exception $e) {
      return new WP_REST_Response(['error'=>$e->getMessage()], 500);
    }
  }

  public function book(WP_REST_Request $req) {
    $serviceId = intval($req->get_param('serviceId'));
    $start = $req->get_param('start');
    $end = $req->get_param('end');
    $tz = $req->get_param('tz') ?: 'UTC';
    $mode = strtoupper((string)($req->get_param('mode') ?: 'VIRTUAL'));

    if (!$serviceId || !$start || !$end) {
      return new WP_REST_Response(['error'=>'Datos incompletos.'], 400);
    }

    $svcDef = Miapp_Services::getService($serviceId);
    if (!$svcDef) return new WP_REST_Response(['error'=>'Servicio inválido.'], 400);

    if ($resp = $this->validateStartNotPast($start, $tzObj)) return $resp;
    // Validar día/hora configurados
    try {
      $localStart = new DateTime($start);
      $localStart->setTimezone($tzObj);
    } catch (Exception $e) {
      return new WP_REST_Response(['error'=>'Fecha/hora inválida.'], 400);
    }
    if (!$this->isWithinBusinessRules($localStart)) {
      return new WP_REST_Response(['error'=>'Horario no disponible según reglas de atención.'], 400);
    }

    $providerId = intval($svcDef['provider_id'] ?? 0);
    $calId = $this->resolveCalendarIdForService($svcDef);

    $patientId = get_current_user_id();
    $sessionNumber = $this->computeSessionNumber($patientId);

    $patient = get_userdata($patientId);
    $patientEmail = $patient ? $patient->user_email : '';
    $patientName = $patient ? ($patient->display_name ?: $patient->user_login) : 'Paciente';

    try {
      $g = new Miapp_Google();
      $cal = $g->svc();

      $doctor = Miapp_Templates::doctorName();

      // ✅ Incluye el consecutivo en el evento y el correo
      $summary = "Sesión #{$sessionNumber} - {$svcDef['name']} - {$doctor}";
      $desc = "Cita agendada desde Miapp.\n".
              "Sesión: #{$sessionNumber}\n".
              "Servicio: {$svcDef['name']}\n".
              "Modalidad: {$mode}\n".
              "Paciente: {$patientName}";

      $event = new Google_Service_Calendar_Event([
        'summary' => $summary,
        'description' => $desc,
        'start' => ['dateTime' => $start, 'timeZone' => $tz],
        'end' => ['dateTime' => $end, 'timeZone' => $tz],
        'attendees' => $patientEmail ? [['email'=>$patientEmail]] : [],
      ]);

      $meet = null;
      $conferenceProvider = null;

      if ($mode === 'VIRTUAL') {
        $event->setConferenceData(new Google_Service_Calendar_ConferenceData([
          'createRequest' => new Google_Service_Calendar_CreateConferenceRequest([
            'requestId' => wp_generate_uuid4(),
            'conferenceSolutionKey' => new Google_Service_Calendar_ConferenceSolutionKey([
              'type' => 'hangoutsMeet'
            ])
          ])
        ]));
        $conferenceProvider = 'GOOGLE_MEET';
      }

      $created = $cal->events->insert($calId, $event, [
        'conferenceDataVersion' => 1,
        'sendUpdates' => 'all',
      ]);

      $eventId = $created->getId();
      $icalUid = $created->getICalUID();

      if ($mode === 'VIRTUAL') {
        $conf = $created->getConferenceData();
        if ($conf && $conf->getEntryPoints()) {
          foreach ($conf->getEntryPoints() as $ep) {
            if ($ep->getEntryPointType() === 'video') {
              $meet = $ep->getUri();
              break;
            }
          }
        }
      }

      global $wpdb;
      $table = $wpdb->prefix.'miapp_appointments';

      $cancelToken = wp_generate_password(32, false, false);
      $resToken = wp_generate_password(32, false, false);

      $wpdb->insert($table, [
        'status'=>'CONFIRMED',
        'user_id'=>$patientId,
        'service_id'=>$serviceId,
        'provider_id'=>$providerId,
        'session_number'=>$sessionNumber, // ✅ NUEVO
        'service_name'=>$svcDef['name'],
        'service_price_cents'=>intval($svcDef['price_cents']),
        'start_at'=>gmdate('Y-m-d H:i:s', strtotime($start)),
        'end_at'=>gmdate('Y-m-d H:i:s', strtotime($end)),
        'timezone'=>$tz,
        'mode'=>$mode,
        'calendar_provider'=>'GOOGLE',
        'calendar_id'=>$calId,
        'calendar_event_id'=>$eventId,
        'calendar_ical_uid'=>$icalUid,
        'conference_provider'=>$conferenceProvider,
        'conference_join_url'=>$meet,
        'cancel_token'=>$cancelToken,
        'reschedule_token'=>$resToken,
        'created_at'=>gmdate('Y-m-d H:i:s')
      ]);

      $apptId = $wpdb->insert_id;

      $cancelUrl = add_query_arg(['token'=>$cancelToken], rest_url('miapp/v1/cancel'));
      $resUrl = add_query_arg(['token'=>$resToken], rest_url('miapp/v1/reschedule'));

      $dateHuman = date_i18n('l d \d\e F, g:i a', strtotime($start));

      $meetBlock = $meet ? "<b>Enlace:</b><br><a href=\"".esc_url($meet)."\">".esc_html($meet)."</a>" : "";
      $buttons = Miapp_Templates::button('Cancelar', $cancelUrl)." &nbsp; ".Miapp_Templates::button('Reagendar', $resUrl);

      // ✅ Incluye sesión en el correo aunque no uses plantillas
      $tpl = get_option('miapp_email_tpl_confirm','');
      $inner = Miapp_Templates::render($tpl, [
        'patient_name'=>esc_html($patientName),
        'doctor_name'=>esc_html($doctor),
        'date_human'=>esc_html($dateHuman),
        'meet_block'=>$meetBlock,
        'buttons_block'=>$buttons,
        'session_number'=>esc_html((string)$sessionNumber),
      ]);
      $html = Miapp_Templates::wrap($inner);

      $ics = Miapp_ICS::build(
        $summary,
        $desc.($meet? "\nMeet: $meet":""),
        $start,
        $end,
        $icalUid ?: wp_generate_uuid4(),
        get_option('admin_email')
      );

      Miapp_Mail::sendHtmlWithIcs(
        $patientEmail,
        get_option('miapp_email_subject_confirm','Confirmación de cita'),
        $html,
        $ics
      );

      return new WP_REST_Response([
        'ok'=>true,
        'appointmentId'=>$apptId,
        'meet'=>$meet,
        'session_number'=>$sessionNumber
      ], 200);

    } catch (Exception $e) {
      return new WP_REST_Response(['error'=>$e->getMessage()], 500);
    }
  }

  public function cancel(WP_REST_Request $req) {
    $token = $req->get_param('token');
    if (!$token) return new WP_REST_Response(['error'=>'Token inválido.'], 400);

    global $wpdb;
    $table = $wpdb->prefix.'miapp_appointments';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE cancel_token=%s LIMIT 1", $token));
    if (!$row) return new WP_REST_Response(['error'=>'Token inválido.'], 404);
    if ($row->status !== 'CONFIRMED') return new WP_REST_Response(['error'=>'La cita no está activa.'], 409);

    $startIso = gmdate(DateTime::ATOM, strtotime($row->start_at.' UTC'));
    if (!$this->canChange($startIso)) return new WP_REST_Response(['error'=>'No es posible cancelar con menos de 24h.'], 403);

    try {
      $g = new Miapp_Google();
      $cal = $g->svc();
      if ($row->calendar_event_id) $cal->events->delete($row->calendar_id, $row->calendar_event_id, ['sendUpdates'=>'all']);

      $wpdb->update($table, [
        'status'=>'CANCELLED',
        'updated_at'=>gmdate('Y-m-d H:i:s')
      ], ['id'=>$row->id]);

      return new WP_REST_Response(['ok'=>true], 200);

    } catch (Exception $e) {
      return new WP_REST_Response(['error'=>$e->getMessage()], 500);
    }
  }

  public function reschedule(WP_REST_Request $req) {
    $token = $req->get_param('token');
    if (!$token) return new WP_REST_Response(['error'=>'Token inválido.'], 400);

    global $wpdb;
    $table = $wpdb->prefix.'miapp_appointments';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE reschedule_token=%s LIMIT 1", $token));
    if (!$row) return new WP_REST_Response(['error'=>'Token inválido.'], 404);
    if ($row->status !== 'CONFIRMED') return new WP_REST_Response(['error'=>'La cita no está activa.'], 409);

    $startIso = gmdate(DateTime::ATOM, strtotime($row->start_at.' UTC'));
    if (!$this->canChange($startIso)) return new WP_REST_Response(['error'=>'No es posible reagendar con menos de 24h.'], 403);

    return new WP_REST_Response([
      'ok'=>true,
      'appointment'=>[
        'id'=>intval($row->id),
        'service_name'=>$row->service_name,
        'session_number'=>intval($row->session_number),
        'start_at'=>$row->start_at,
        'end_at'=>$row->end_at,
        'timezone'=>$row->timezone,
        'mode'=>$row->mode,
      ]
    ], 200);
  }

  public function myAppointments(WP_REST_Request $req) {
    global $wpdb;
    $table = $wpdb->prefix.'miapp_appointments';
    $uid = get_current_user_id();

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id,status,service_name,service_price_cents,session_number,start_at,end_at,timezone,mode,conference_join_url,provider_id
       FROM $table
       WHERE user_id=%d
       ORDER BY start_at DESC
       LIMIT 100",
      $uid
    ));

    $out = array_map(function($r){
      return [
        'id'=>intval($r->id),
        'status'=>$r->status,
        'service_name'=>$r->service_name,
        'service_price_cents'=>intval($r->service_price_cents),
        'session_number'=>intval($r->session_number),
        'start_at'=>$r->start_at,
        'end_at'=>$r->end_at,
        'timezone'=>$r->timezone,
        'mode'=>$r->mode,
        'meet'=>$r->conference_join_url,
        'provider_id'=>intval($r->provider_id),
      ];
    }, $rows);

    return new WP_REST_Response(['appointments'=>$out], 200);
  }

  private function getChangeWindowHours(): int {
    $h = intval(get_option('miapp_min_hours_change', 24));
    return $h > 0 ? $h : 24;
  }

  private function canChangeAt(string $startAtUtc): bool {
    $minHours = $this->getChangeWindowHours();
    $startTs = strtotime($startAtUtc . ' UTC');
    if (!$startTs) return false;
    return ($startTs - time()) >= ($minHours * 3600);
  }

  private function loadMineAppt(int $id) {
    global $wpdb;
    $t = $wpdb->prefix . 'miapp_appointments';
    $uid = get_current_user_id();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d AND user_id=%d LIMIT 1", $id, $uid));
  }

  public function cancelMine(WP_REST_Request $req) {
    $id = intval($req->get_param('id'));
    $row = $this->loadMineAppt($id);
    if (!$row) return new WP_REST_Response(['error'=>'Cita no encontrada.'], 404);
    if ($row->status !== 'CONFIRMED') return new WP_REST_Response(['error'=>'La cita no está activa.'], 409);

    if (!$this->canChangeAt($row->start_at)) {
    return new WP_REST_Response(['error'=>'No es posible cancelar dentro de la ventana mínima.'], 403);
  }

  try {
    // Borra evento del calendario
    if ($row->calendar_provider === 'GOOGLE' && $row->calendar_event_id) {
      $g = new Miapp_Google();
      $cal = $g->svc();
      $cal->events->delete($row->calendar_id, $row->calendar_event_id, ['sendUpdates'=>'all']);
    }

      global $wpdb;
      $t = $wpdb->prefix . 'miapp_appointments';
      $wpdb->update($t, [
        'status' => 'CANCELLED',
        'updated_at' => gmdate('Y-m-d H:i:s')
      ], ['id'=>$row->id]);

      return new WP_REST_Response(['ok'=>true], 200);

    } catch (Exception $e) {
      return new WP_REST_Response(['error'=>$e->getMessage()], 500);
    }
  }

  public function rescheduleMine(WP_REST_Request $req) {
    $id = intval($req->get_param('id'));
    $row = $this->loadMineAppt($id);
    if (!$row) return new WP_REST_Response(['error'=>'Cita no encontrada.'], 404);
    if ($row->status !== 'CONFIRMED') return new WP_REST_Response(['error'=>'La cita no está activa.'], 409);

    if (!$this->canChangeAt($row->start_at)) {
      return new WP_REST_Response(['error'=>'No es posible reagendar dentro de la ventana mínima.'], 403);
    }

    $start = (string)($req->get_param('start') ?? '');
    $end   = (string)($req->get_param('end') ?? '');
    $tz    = (string)($req->get_param('tz') ?? 'UTC');

    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if (!$startTs || !$endTs || $endTs <= $startTs) {
      return new WP_REST_Response(['error'=>'Rango inválido.'], 400);
    }
    if ($startTs < time()) {
      return new WP_REST_Response(['error'=>'No puedes reagendar al pasado.'], 400);
    }

    global $wpdb;
    $table = $wpdb->prefix.'miapp_appointments';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE reschedule_token=%s LIMIT 1", $token));
    if (!$row) return new WP_REST_Response(['error'=>'Token inválido.'], 404);
    if ($row->status !== 'CONFIRMED') return new WP_REST_Response(['error'=>'La cita no está activa.'], 409);

    $startIso = gmdate(DateTime::ATOM, strtotime($row->start_at.' UTC'));
    if (!$this->canChange($startIso)) return new WP_REST_Response(['error'=>'No es posible reagendar con menos de 24h.'], 403);


    try {
      // Mover evento en Google
      if ($row->calendar_provider === 'GOOGLE' && $row->calendar_event_id) {
        $g = new Miapp_Google();
        $cal = $g->svc();

        $patch = new Google_Service_Calendar_Event([
          'start' => ['dateTime' => $start, 'timeZone' => $tz],
          'end'   => ['dateTime' => $end, 'timeZone' => $tz],
        ]);

        $cal->events->patch($row->calendar_id, $row->calendar_event_id, $patch, [
          'sendUpdates' => 'all',
          'conferenceDataVersion' => 1,
        ]);
      }

      // Actualizar DB
      global $wpdb;
      $t = $wpdb->prefix.'miapp_appointments';

      $wpdb->update($t, [
        'start_at' => gmdate('Y-m-d H:i:s', $startTs),
        'end_at'   => gmdate('Y-m-d H:i:s', $endTs),
        'timezone' => $tz,  
        'updated_at' => gmdate('Y-m-d H:i:s')
      ] , ['id'=>$row->id]);

      return new WP_REST_Response(['ok'=>true], 200);

    } catch (Exception $e) {
      return new WP_REST_Response(['error'=>$e->getMessage()], 500);
    }
  }

}
