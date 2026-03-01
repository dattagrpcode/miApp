<?php
if (!defined('ABSPATH')) exit;

class Miapp_Rest {
  public function init() {
    add_action('rest_api_init', function () {
      register_rest_route('miapp/v1', '/services', [
        'methods' => 'GET',
        'callback' => [$this, 'services'],
        'permission_callback' => '__return_true',
      ]);
    register_rest_route('miapp/v1', '/providers', [
      'methods' => 'GET',
      'callback' => [$this,'providers'],
      'permission_callback' => '__return_true',
    ]);


      register_rest_route('miapp/v1', '/availability', [
        'methods' => 'GET',
        'callback' => [$this, 'availability'],
        'permission_callback' => '__return_true',
      ]);

      // Disponibilidad por día (para calendario mensual)
      register_rest_route('miapp/v1', '/availability/days', [
        'methods' => 'GET',
        'callback' => [$this, 'availabilityDays'],
        'permission_callback' => '__return_true',
      ]);

      register_rest_route('miapp/v1', '/book', [
        'methods' => 'POST',
        'callback' => [$this, 'book'],
        'permission_callback' => function(){ return is_user_logged_in(); },
      ]);

      // Paciente
      register_rest_route('miapp/v1', '/me/appointments', [
        'methods' => 'GET',
        'callback' => [$this, 'meAppointments'],
        'permission_callback' => function(){ return is_user_logged_in(); },
      ]);

      register_rest_route('miapp/v1', '/me/appointments/(?P<id>\d+)/cancel', [
        'methods' => 'POST',
        'callback' => [$this, 'meCancel'],
        'permission_callback' => function(){ return is_user_logged_in(); },
      ]);

      register_rest_route('miapp/v1', '/me/appointments/(?P<id>\d+)/reschedule', [
        'methods' => 'POST',
        'callback' => [$this, 'meReschedule'],
        'permission_callback' => function(){ return is_user_logged_in(); },
      ]);

      // Practicante (Mia)
      register_rest_route('miapp/v1', '/practitioner/appointments', [
        'methods' => 'GET',
        'callback' => [$this, 'practitionerAppointments'],
        'permission_callback' => [$this, 'isPractitioner'],
      ]);

      register_rest_route('miapp/v1', '/practitioner/appointments/(?P<id>\d+)/status', [
      'methods'  => 'POST',
      'callback' => [$this, 'practitionerUpdateStatus'],
      'permission_callback' => [$this, 'canPractitioner'],
    ]);

    register_rest_route('miapp/v1', '/practitioner/appointments/(?P<id>\d+)/attendance', [
        'methods' => 'POST',
        'callback' => [$this, 'practitionerAttendance'],
        'permission_callback' => [$this, 'isPractitioner'],
      ]);

      register_rest_route('miapp/v1', '/practitioner/kpis', [
        'methods' => 'GET',
        'callback' => [$this, 'practitionerKpis'],
        'permission_callback' => [$this, 'isPractitioner'],
      ]);
    register_rest_route('miapp/v1', '/practitioner/services', [
      'methods' => ['GET','POST'],
      'callback' => [$this,'practitionerServices'],
      'permission_callback' => [$this,'canPractitioner'],
    ]);
    register_rest_route('miapp/v1', '/practitioner/services/(?P<id>\d+)', [
      'methods' => ['PUT','DELETE'],
      'callback' => [$this,'practitionerServiceUpdate'],
      'permission_callback' => [$this,'canPractitioner'],
    ]);

    });
  }

  public function isPractitioner(): bool {
    return current_user_can('miapp_manage');
  }

  private function apptTable(): string {
    global $wpdb;
    return $wpdb->prefix.'miapp_appointments';
  }

  private function nowUtc(): string {
    return gmdate('Y-m-d H:i:s');
  }

  private function minHoursChange(): int {
    return intval(get_option('miapp_min_hours_change', 24));
  }

  private function dayStartHour(): int { return intval(get_option('miapp_day_start_hour', 9)); }
  private function dayEndHour(): int { return intval(get_option('miapp_day_end_hour', 18)); }

  private function enabledDays(): array {
    $raw = (string)get_option('miapp_days_enabled','1,2,3,4,5');
    $arr = array_map('intval', array_filter(array_map('trim', explode(',', $raw))));
    $arr = array_values(array_unique(array_filter($arr, fn($n)=>$n>=1 && $n<=7)));
    return $arr ?: [1,2,3,4,5];
  }

  private function computeMinDateTimeUtc(DateTimeZone $tz): DateTime {
    $endH = $this->dayEndHour();
    $localNow = new DateTime('now', $tz);
    $minLocal = (clone $localNow)->setTime(0,0,0);
    if (intval($localNow->format('H')) >= $endH) {
      $minLocal->modify('+1 day');
    }
    // ajustar al próximo día habilitado
    $enabled = $this->enabledDays();
    for ($i=0; $i<14; $i++) {
      $dow = intval($minLocal->format('N'));
      if (in_array($dow, $enabled, true)) break;
      $minLocal->modify('+1 day');
    }
    $minLocal->setTimezone(new DateTimeZone('UTC'));
    return $minLocal;
  }

  private function validateStartNotPast(string $startIso, DateTimeZone $tz): ?WP_REST_Response {
    $startTs = strtotime($startIso);
    if (!$startTs) return new WP_REST_Response(['error'=>'Fecha/hora inválida.'], 400);
    $minTs = $this->computeMinDateTimeUtc($tz)->getTimestamp();
    if ($startTs < $minTs) return new WP_REST_Response(['error'=>'No puedes agendar en el pasado.'], 400);
    return null;
  }

  private function validateRangeNotPast(?string $fromIso, ?string $toIso, DateTimeZone $tz): ?WP_REST_Response {
    if (!$fromIso || !$toIso) return new WP_REST_Response(['error'=>'Rango inválido.'], 400);
    $fromTs = strtotime($fromIso);
    $toTs = strtotime($toIso);
    if (!$fromTs || !$toTs || $toTs <= $fromTs) return new WP_REST_Response(['error'=>'Rango inválido.'], 400);
    $minTs = $this->computeMinDateTimeUtc($tz)->getTimestamp();
    if ($toTs < $minTs) return new WP_REST_Response(['error'=>'No puedes consultar fechas pasadas.'], 400);
    return null;
  }

  private function isWithinBusinessRules(DateTime $localStart): bool {
    $enabled = $this->enabledDays();
    $dow = intval($localStart->format('N'));
    if (!in_array($dow, $enabled, true)) return false;
    $h = intval($localStart->format('H'));
    $startH = $this->dayStartHour();
    $endH = $this->dayEndHour();
    if ($h < $startH) return false;
    if ($h >= $endH) return false;
    return true;
  }

  private function busyRangesFromDb(string $fromIso, string $toIso): array {
    global $wpdb;
    $t = $this->apptTable();
    $pid = $this->currentProviderId();

    $from = gmdate('Y-m-d H:i:s', strtotime($fromIso));
    $to   = gmdate('Y-m-d H:i:s', strtotime($toIso));

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT start_at, end_at FROM $t WHERE status IN ('PENDING','CONFIRMED') AND start_at < %s AND end_at > %s",
      $to, $from
    ), ARRAY_A);

    $busy = [];
    foreach ($rows as $r) {
      $busy[] = [
        'start' => strtotime($r['start_at'].' UTC'),
        'end'   => strtotime($r['end_at'].' UTC'),
      ];
    }
    return $busy;
  }

  private function busyRangesFromGoogle(string $fromIso, string $toIso, string $calendarId = ''): array {
    try {
      $g = new Miapp_Google();
      if (!$g->isConnected()) return [];
      if ($calendarId) { $g->setCalendarId($calendarId); }
      // La API de Google requiere UTC o ISO con tz
      $from = gmdate(DateTime::ATOM, strtotime($fromIso));
      $to   = gmdate(DateTime::ATOM, strtotime($toIso));
      return $g->busyRanges($from, $to);
    } catch (Throwable $e) {
      return [];
    }
  }

  private function subtractBusyAll(array $slots, string $fromIso, string $toIso, string $calendarId = ''): array {
    $busyDb = $this->busyRangesFromDb($fromIso, $toIso);
    $busyGo = $this->busyRangesFromGoogle($fromIso, $toIso);

    $busy = [];
    foreach ($busyDb as $b) {
      $busy[] = [
        'start' => gmdate(DateTime::ATOM, $b['start']),
        'end' => gmdate(DateTime::ATOM, $b['end']),
      ];
    }
    foreach ($busyGo as $b) {
      if (!is_array($b) || empty($b['start']) || empty($b['end'])) continue;
      $busy[] = [
        'start' => (string)$b['start'],
        'end' => (string)$b['end'],
      ];
    }
    return Miapp_Slots::subtractBusy($slots, $busy);
  }

  public function services(WP_REST_Request $req) {
    $providerId = intval($req->get_param('provider_id') ?: 0);
    if ($providerId > 0) {
      return new WP_REST_Response(['services'=>Miapp_Services::listServicesByProvider($providerId)], 200);
    }
    return new WP_REST_Response(['services'=>Miapp_Services::listServices()], 200);
  }

  public function availability(WP_REST_Request $req) {
    $serviceId = intval($req->get_param('service_id') ?: $req->get_param('serviceId'));
    $providerId = intval($req->get_param('provider_id') ?: 0);
    $from = (string)$req->get_param('from');
    $to = (string)$req->get_param('to');
    $tz = (string)($req->get_param('tz') ?: 'UTC');

    try { $tzObj = new DateTimeZone($tz); } catch (Exception $e) { $tzObj = new DateTimeZone('UTC'); $tz = 'UTC'; }

    if ($resp = $this->validateRangeNotPast($from, $to, $tzObj)) return $resp;

    $svcDef = Miapp_Services::getService($serviceId);
    if (!$svcDef) return new WP_REST_Response(['error'=>'Servicio inválido.'], 400);
    $providerId = intval($req->get_param('provider_id') ?: 0);
    if ($providerId<=0) { $providerId = intval($svcDef['provider_id'] ?? 0); }
    if ($providerId<=0) { $providerId = Miapp_Providers::get_default_provider_id(); }
    $calendarId = '';
    if ($providerId) {
      $calMeta = get_post_meta($providerId, '_miapp_provider_calendar_id', true);
      if ($calMeta) $calendarId = (string)$calMeta;
    }
    if (!$calendarId) $calendarId = (string)get_option('miapp_google_calendar_id','primary');
    $providerId = intval($req->get_param('provider_id') ?: 0);
    if ($providerId<=0) { $providerId = intval($svcDef['provider_id'] ?? 0); }
    if ($providerId<=0) { $providerId = Miapp_Providers::get_default_provider_id(); }
    $calendarId = '';
    if ($providerId) {
      $calMeta = get_post_meta($providerId, '_miapp_provider_calendar_id', true);
      if ($calMeta) $calendarId = (string)$calMeta;
    }
    if (!$calendarId) $calendarId = (string)get_option('miapp_google_calendar_id','primary');
    if ($providerId <= 0) { $providerId = intval($svcDef['provider_id'] ?? 0); }
    if ($providerId <= 0) { $providerId = Miapp_Providers::get_default_provider_id(); }

    $durMin = intval($svcDef['duration_min']);
    $bufMin = intval($svcDef['buffer_min']);

    $slots = Miapp_Slots::build($from, $to, $tz, $durMin, $bufMin);

    // aplicar reglas adicionales de fecha mínima + horario habilitado
    $minUtc = $this->computeMinDateTimeUtc($tzObj)->getTimestamp();
    $filtered = [];
    foreach ($slots as $s) {
      $st = strtotime($s['start']);
      if ($st < $minUtc) continue;
      $lt = new DateTime('@'.$st);
      $lt->setTimezone($tzObj);
      if (!$this->isWithinBusinessRules($lt)) continue;
      $filtered[] = $s;
    }

    // Restar ocupados (DB + Google)
    $free = $this->subtractBusyAll($filtered, $from, $to);

    return new WP_REST_Response(['slots'=>$free], 200);
  }

  /**
   * Resumen de disponibilidad por día para un mes.
   * Params: service_id, month=YYYY-MM, tz
   */
  public function availabilityDays(WP_REST_Request $req) {
    $serviceId = intval($req->get_param('service_id') ?: $req->get_param('serviceId'));
    $providerId = intval($req->get_param('provider_id') ?: 0);
    $month = (string)$req->get_param('month');
    $tz = (string)($req->get_param('tz') ?: 'UTC');

    try { $tzObj = new DateTimeZone($tz); } catch (Exception $e) { $tzObj = new DateTimeZone('UTC'); $tz='UTC'; }

    if (!$serviceId || !preg_match('/^\d{4}-\d{2}$/', $month)) {
      return new WP_REST_Response(['error'=>'Parámetros inválidos.'], 400);
    }

    $svcDef = Miapp_Services::getService($serviceId);
    if (!$svcDef) return new WP_REST_Response(['error'=>'Servicio inválido.'], 400);
    if ($providerId <= 0) { $providerId = intval($svcDef['provider_id'] ?? 0); }
    if ($providerId <= 0) { $providerId = Miapp_Providers::get_default_provider_id(); }

    $durMin = intval($svcDef['duration_min']);
    $bufMin = intval($svcDef['buffer_min']);

    $firstLocal = DateTime::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', $tzObj);
    if (!$firstLocal) return new WP_REST_Response(['error'=>'Mes inválido.'], 400);
    $lastLocal = (clone $firstLocal)->modify('first day of next month');

    $fromIso = $firstLocal->format(DateTime::ATOM);
    $toIso = $lastLocal->format(DateTime::ATOM);

    // slots teóricos del mes
    $slots = Miapp_Slots::build($fromIso, $toIso, $tz, $durMin, $bufMin);

    // aplicar reglas adicionales
    $minUtc = $this->computeMinDateTimeUtc($tzObj)->getTimestamp();
    $filtered = [];
    foreach ($slots as $s) {
      $st = strtotime($s['start']);
      if ($st < $minUtc) continue;
      $lt = new DateTime('@'.$st);
      $lt->setTimezone($tzObj);
      if (!$this->isWithinBusinessRules($lt)) continue;
      $filtered[] = $s;
    }

    // slots libres (DB + Google)
    $free = $this->subtractBusyAll($filtered, $fromIso, $toIso);

    // agrupar por día local
    $totalByDay = [];
    foreach ($filtered as $s) {
      $st = strtotime($s['start']);
      $d = (new DateTime('@'.$st))->setTimezone($tzObj)->format('Y-m-d');
      $totalByDay[$d] = ($totalByDay[$d] ?? 0) + 1;
    }
    $freeByDay = [];
    foreach ($free as $s) {
      $st = strtotime($s['start']);
      $d = (new DateTime('@'.$st))->setTimezone($tzObj)->format('Y-m-d');
      $freeByDay[$d] = ($freeByDay[$d] ?? 0) + 1;
    }

    // generar lista completa del mes
    $days = [];
    $cursor = clone $firstLocal;
    $enabled = $this->enabledDays();
    while ($cursor < $lastLocal) {
      $d = $cursor->format('Y-m-d');
      $dow = intval($cursor->format('N'));
      $isEnabled = in_array($dow, $enabled, true);
      $total = $isEnabled ? intval($totalByDay[$d] ?? 0) : 0;
      $remaining = $isEnabled ? intval($freeByDay[$d] ?? 0) : 0;
      $days[] = [
        'date' => $d,
        'enabled' => $isEnabled,
        'total' => $total,
        'remaining' => $remaining,
      ];
      $cursor->modify('+1 day');
    }

    return new WP_REST_Response(['month'=>$month,'days'=>$days], 200);
  }

  public function book(WP_REST_Request $req) {
    global $wpdb;

    $serviceId = intval($req->get_param('service_id') ?: $req->get_param('serviceId'));
    $providerId = intval($req->get_param('provider_id') ?: 0);
    $startIso = (string)$req->get_param('start');
    $endIso   = (string)$req->get_param('end');
    $tz       = (string)($req->get_param('tz') ?: 'UTC');
    $mode     = strtoupper((string)($req->get_param('mode') ?: 'VIRTUAL'));

    try { $tzObj = new DateTimeZone($tz); } catch (Exception $e) { $tzObj = new DateTimeZone('UTC'); $tz = 'UTC'; }

    if (!$serviceId || !$startIso || !$endIso) return new WP_REST_Response(['error'=>'Datos incompletos.'], 400);
    if ($resp = $this->validateStartNotPast($startIso, $tzObj)) return $resp;

    $svcDef = Miapp_Services::getService($serviceId);
    if (!$svcDef) return new WP_REST_Response(['error'=>'Servicio inválido.'], 400);
    if ($providerId <= 0) { $providerId = intval($svcDef['provider_id'] ?? 0); }
    if ($providerId <= 0) { $providerId = Miapp_Providers::get_default_provider_id(); }

    $userId = get_current_user_id();
    // Regla: un paciente solo puede tener 1 cita por día POR ESPECIALIDAD (según zona horaria de la cita)
    try {
      $local = new DateTime($startIso, $tzObj);
      $dayStart = (clone $local)->setTime(0,0,0);
      $dayEnd = (clone $local)->setTime(23,59,59);
      $dayStartUtc = (clone $dayStart); $dayStartUtc->setTimezone(new DateTimeZone('UTC'));
      $dayEndUtc = (clone $dayEnd); $dayEndUtc->setTimezone(new DateTimeZone('UTC'));

      $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT provider_id, start_at FROM {$this->apptTable()} WHERE user_id=%d AND status<>'CANCELED' AND start_at BETWEEN %s AND %s",
        $userId, $dayStartUtc->format('Y-m-d H:i:s'), $dayEndUtc->format('Y-m-d H:i:s')
      ), ARRAY_A);

      $targetSpec = '';
      if ($providerId) {
        $spec = wp_get_post_terms($providerId, 'miapp_specialty', ['fields'=>'names']);
        $targetSpec = $spec ? (string)$spec[0] : '';
      }

      foreach($rows as $r){
        $pid = intval($r['provider_id']);
        if(!$pid) continue;
        $spec = wp_get_post_terms($pid, 'miapp_specialty', ['fields'=>'names']);
        $specName = $spec ? (string)$spec[0] : '';
        if ($targetSpec && $specName === $targetSpec) {
          return new WP_REST_Response(['error'=>'Solo puedes tener una cita por día para esta especialidad.'], 409);
        }
      }
    } catch (Exception $e) {
      // Si falla el cálculo, no bloqueamos
    }

    $user = get_userdata($userId);
    $patientEmail = $user ? $user->user_email : '';
    $patientName  = $user ? ($user->display_name ?: $user->user_login) : 'Paciente';

    // validar que el slot está libre (DB + Google)
    $t = $this->apptTable();
    $pid = $this->currentProviderId();
    $startUtc = gmdate('Y-m-d H:i:s', strtotime($startIso));
    $endUtc   = gmdate('Y-m-d H:i:s', strtotime($endIso));

    // (regla por especialidad aplicada arriba)
    try {
      $local = new DateTime($startIso, $tzObj);
      $dayStart = (clone $local)->setTime(0,0,0);
      $dayEnd = (clone $local)->setTime(23,59,59);
      $dayStartUtc = (clone $dayStart); $dayStartUtc->setTimezone(new DateTimeZone('UTC'));
      $dayEndUtc = (clone $dayEnd); $dayEndUtc->setTimezone(new DateTimeZone('UTC'));
      $exists = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE id <> %d AND user_id=%d AND status<>'CANCELED' AND start_at BETWEEN %s AND %s",
        $id, $uid, $dayStartUtc->format('Y-m-d H:i:s'), $dayEndUtc->format('Y-m-d H:i:s')
      )));
      if ($exists > 0) {
        return new WP_REST_Response(['error'=>'Solo puedes tener una cita por día.'], 409);
      }
    } catch (Exception $e) {}


    $conflict = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(1) FROM $t WHERE status IN ('PENDING','CONFIRMED') AND start_at < %s AND end_at > %s",
      $endUtc, $startUtc
    ));
    if (intval($conflict) > 0) return new WP_REST_Response(['error'=>'Ese horario ya fue tomado.'], 409);

    // Conflicto en Google Calendar (por si hay eventos externos)
    $busyGo = $this->busyRangesFromGoogle($startIso, $endIso);
    foreach ($busyGo as $b) {
      $bst = strtotime((string)($b['start'] ?? ''));
      $ben = strtotime((string)($b['end'] ?? ''));
      if (!$bst || !$ben) continue;
      $st = strtotime($startIso);
      $en = strtotime($endIso);
      if ($st < $ben && $en > $bst) {
        return new WP_REST_Response(['error'=>'Ese horario ya está ocupado (calendario).'], 409);
      }
    }

    // session_number
    $maxSess = intval($wpdb->get_var($wpdb->prepare("SELECT MAX(session_number) FROM $t WHERE user_id=%d", $userId)));
    $sessionNumber = $maxSess + 1;

    $doctor = Miapp_Templates::doctorName();

    $cancelToken = wp_generate_password(32, false, false);
    $resToken = wp_generate_password(32, false, false);

    $calendarProvider = 'NONE';
    $calendarId = '';
    $eventId = null;
    $joinUrl = null;

    // Crear evento Google si está conectado
    try {
      $g = new Miapp_Google();
      if ($g->isConnected()) {
        $calendarProvider = 'GOOGLE';
        $calendarId = '';
        if ($providerId) {
          $calMeta = get_post_meta($providerId, '_miapp_provider_calendar_id', true);
          if ($calMeta) $calendarId = (string)$calMeta;
        }
        if (!$calendarId) $calendarId = (string)get_option('miapp_google_calendar_id','primary');
        $g->setCalendarId($calendarId);

        $payload = [
          'summary' => "Sesión #{$sessionNumber} - {$svcDef['name']} - {$doctor}",
          'description' => "Paciente: {$patientName}\nServicio: {$svcDef['name']}\nSesión: #{$sessionNumber}",
          'start' => ['dateTime' => $startIso, 'timeZone' => $tz],
          'end'   => ['dateTime' => $endIso, 'timeZone' => $tz],
          'attendees' => array_values(array_filter([
            $patientEmail ? ['email'=>$patientEmail] : null,
          ])),
        ];
        if ($mode === 'VIRTUAL') {
          $payload['conferenceData'] = [
            'createRequest' => [
              'requestId' => wp_generate_password(18, false, false),
              'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ],
          ];
        }

        $created = $g->createEvent($payload);
        $eventId = $created['event_id'] ?: null;
        $joinUrl = $created['meetLink'] ?: null;
      }
    } catch (Throwable $e) {
      // Si Google falla, seguimos con confirmación por correo + ICS.
    }

    $now = $this->nowUtc();
    $ok = $wpdb->insert($t, [
      'status' => 'PENDING',
      'payment_status' => 'UNPAID',
      'attendance_status' => 'PENDING',
      'user_id' => $userId,
      'service_id' => $serviceId,
      'provider_id' => $providerId ?: null,
      'session_number' => $sessionNumber,
      'service_name' => $svcDef['name'],
      'service_price_cents' => intval($svcDef['price_cents']),
      'start_at' => $startUtc,
      'end_at' => $endUtc,
      'timezone' => $tz,
      'mode' => $mode,
      'calendar_provider' => $calendarProvider,
      'calendar_id' => $calendarId,
      'calendar_event_id' => $eventId,
      'conference_provider' => ($mode === 'VIRTUAL') ? 'GOOGLE_MEET' : null,
      'conference_join_url' => $joinUrl,
      'cancel_token' => $cancelToken,
      'reschedule_token' => $resToken,
      'created_at' => $now,
      'updated_at' => $now,
      'last_status_changed_at' => $now,
    ], [
      '%s','%s','%d','%d','%d','%d','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'
    ]);

    if (!$ok) return new WP_REST_Response(['error'=>'No se pudo guardar la cita.'], 500);

    $apptId = intval($wpdb->insert_id);

    // Email + ICS
    $buttons = '';
    $meetBlock = '';

    $cancelUrl = add_query_arg(['miapp_cancel'=>$cancelToken], home_url('/'));
    $resUrl = add_query_arg(['miapp_reschedule'=>$resToken], home_url('/'));

    $buttons .= Miapp_Templates::button('Cancelar', $cancelUrl) . ' ';
    $buttons .= Miapp_Templates::button('Reagendar', $resUrl);

    if ($joinUrl) {
      $meetBlock = '<p><b>Teleconsulta:</b> '.Miapp_Templates::button('Entrar a la sesión', $joinUrl).'</p>';
    }

    $dateHuman = (new DateTime($startIso))->setTimezone($tzObj)->format('d/m/Y H:i');

    $vars = [
      'patient_name' => esc_html($patientName),
      'doctor_name' => esc_html($doctor),
      'service_name' => esc_html($svcDef['name']),
      'date_human' => esc_html($dateHuman),
      'session_number' => (string)$sessionNumber,
      'meet_block' => $meetBlock,
      'buttons_block' => '<p>'.$buttons.'</p>',
      'status_label' => 'Confirmada',
    ];

    $subj = (string)get_option('miapp_email_subject_confirm','Confirmación de cita');
    $tpl = (string)get_option('miapp_email_tpl_confirm','');

    $html = Miapp_Templates::wrap(Miapp_Templates::render($tpl, $vars));

    $ics = Miapp_ICS::buildInvite([
      'uid' => 'miapp-'.$apptId.'@'.parse_url(home_url(), PHP_URL_HOST),
      'summary' => "Cita - {$svcDef['name']} - {$doctor}",
      'description' => "Sesión #{$sessionNumber}",
      'start' => $startIso,
      'end' => $endIso,
      'timezone' => $tz,
      'organizerName' => $doctor,
      'organizerEmail' => get_option('admin_email'),
      'attendeeEmail' => $patientEmail,
    ]);

    if ($patientEmail) {
      Miapp_Mail::sendHtmlWithIcs($patientEmail, $subj, $html, $ics);
    }

    return new WP_REST_Response([
      'ok' => true,
      'appointment_id' => $apptId,
      'session_number' => $sessionNumber,
      'meet_url' => $joinUrl,
    ], 200);
  }

  public function meAppointments() {
    global $wpdb;
    $t = $this->apptTable();
    $pid = $this->currentProviderId();
    $uid = get_current_user_id();

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM $t WHERE user_id=%d ORDER BY start_at DESC LIMIT 200",
      $uid
    ), ARRAY_A);

    return new WP_REST_Response(['appointments'=>$rows], 200);
  }

  private function loadApptForUser(int $id, int $uid): ?array {
    global $wpdb;
    $t = $this->apptTable();
    $pid = $this->currentProviderId();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d AND user_id=%d", $id, $uid), ARRAY_A);
    return $row ?: null;
  }

  private function canChangeAppt(array $appt): bool {
    $minH = $this->minHoursChange();
    $startTs = strtotime($appt['start_at'].' UTC');
    return $startTs - time() >= ($minH * 3600);
  }

  public function meCancel(WP_REST_Request $req) {
    global $wpdb;
    $id = intval($req->get_param('id'));
    $uid = get_current_user_id();
    $appt = $this->loadApptForUser($id, $uid);
    if (!$appt) return new WP_REST_Response(['error'=>'No encontrado.'], 404);

    if (!$this->canChangeAppt($appt)) {
      return new WP_REST_Response(['error'=>'Solo puedes cancelar hasta '.$this->minHoursChange().'h antes.'], 403);
    }

    $t = $this->apptTable();
    $pid = $this->currentProviderId();
    $now = $this->nowUtc();
    $wpdb->update($t, [
      'status' => 'CANCELLED',
      'updated_at' => $now,
      'last_status_changed_at' => $now,
    ], ['id'=>$id], ['%s','%s','%s'], ['%d']);

    return new WP_REST_Response(['ok'=>true], 200);
  }

  public function meReschedule(WP_REST_Request $req) {
    global $wpdb;

    $id = intval($req->get_param('id'));
    $uid = get_current_user_id();
    $appt = $this->loadApptForUser($id, $uid);
    if (!$appt) return new WP_REST_Response(['error'=>'No encontrado.'], 404);

    if (!$this->canChangeAppt($appt)) {
      return new WP_REST_Response(['error'=>'Solo puedes reagendar hasta '.$this->minHoursChange().'h antes.'], 403);
    }

    $startIso = (string)$req->get_param('start');
    $endIso   = (string)$req->get_param('end');
    $tz       = (string)($req->get_param('tz') ?: ($appt['timezone'] ?: 'UTC'));

    try { $tzObj = new DateTimeZone($tz); } catch (Exception $e) { $tzObj = new DateTimeZone('UTC'); $tz = 'UTC'; }

    if ($resp = $this->validateStartNotPast($startIso, $tzObj)) return $resp;

    $t = $this->apptTable();
    $pid = $this->currentProviderId();
    $startUtc = gmdate('Y-m-d H:i:s', strtotime($startIso));
    $endUtc   = gmdate('Y-m-d H:i:s', strtotime($endIso));

    // validar conflicto
    $conflict = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(1) FROM $t WHERE id <> %d AND status IN ('PENDING','CONFIRMED') AND start_at < %s AND end_at > %s",
      $id, $endUtc, $startUtc
    ));
    if (intval($conflict) > 0) return new WP_REST_Response(['error'=>'Ese horario ya fue tomado.'], 409);

    $now = $this->nowUtc();
    $wpdb->update($t, [
      'start_at' => $startUtc,
      'end_at' => $endUtc,
      'timezone' => $tz,
      'updated_at' => $now,
      'last_rescheduled_at' => $now,
      'reschedule_count' => intval($appt['reschedule_count']) + 1,
    ], ['id'=>$id], ['%s','%s','%s','%s','%s','%d'], ['%d']);

    return new WP_REST_Response(['ok'=>true], 200);
  }

  public function practitionerAppointments(WP_REST_Request $req) {
    global $wpdb;
    $t = $this->apptTable();
    $pid = $this->currentProviderId();

    $from = $req->get_param('from');
    $to = $req->get_param('to');

    $where = "1=1";
    $params = [];
    if ($from) { $where .= " AND start_at >= %s"; $params[] = gmdate('Y-m-d H:i:s', strtotime($from)); }
    if ($to) { $where .= " AND start_at <= %s"; $params[] = gmdate('Y-m-d H:i:s', strtotime($to)); }

    $sql = "SELECT a.*, u.user_email, u.display_name FROM $t a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id WHERE $where ORDER BY a.start_at DESC LIMIT 500";
    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

    return new WP_REST_Response(['appointments'=>$rows], 200);
  }

  
  public function practitionerUpdateStatus(WP_REST_Request $req) {
    global $wpdb;
    $t = $this->apptTable();
    $pid = $this->currentProviderId();
    $id = intval($req['id']);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A);
    if (!$row) return new WP_REST_Response(['error'=>'NOT_FOUND'], 404);

    $body = $req->get_json_params();
    $status = strtoupper(sanitize_text_field($body['status'] ?? ''));
    $pay = strtoupper(sanitize_text_field($body['payment_status'] ?? ''));

    $allowedStatus = ['PENDING','CONFIRMED','CLOSED','CANCELED'];
    $allowedPay = ['UNPAID','PAID'];

    $updates = [];
    $params = [];

    if ($status && in_array($status, $allowedStatus, true)) {
      $updates[] = "status=%s";
      $params[] = $status;
      $updates[] = "last_status_changed_at=%s";
      $params[] = current_time('mysql');
    }
    if ($pay && in_array($pay, $allowedPay, true)) {
      $updates[] = "payment_status=%s";
      $params[] = $pay;
    }

    if (!$updates) return new WP_REST_Response(['ok'=>true], 200);

    $updates[] = "updated_at=%s";
    $params[] = current_time('mysql');

    $params[] = $id;

    $sql = "UPDATE $t SET ".implode(', ', $updates)." WHERE id=%d";
    $wpdb->query($wpdb->prepare($sql, $params));

    // recargar para plantilla
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A);

    // Email al paciente cuando hay cambio de status (no para cambios solo de pago)
    if ($status && $row && !empty($row['user_id'])) {
      $u = get_user_by('id', intval($row['user_id']));
      $patientEmail = $u ? $u->user_email : '';
      if ($patientEmail) {
        $tpl = get_option('miapp_email_tpl_status', '');
        $subj = get_option('miapp_email_subject_status', 'Actualización de tu cita');
        if ($tpl) {
          $when = '';
          try {
            $dt = new DateTime($row['start_at'], new DateTimeZone($row['timezone'] ?: 'America/Bogota'));
            $when = $dt->format('Y-m-d H:i');
          } catch (Exception $e) { $when = $row['start_at']; }
          $html = Miapp_Templates::render($tpl, [
            'doctor_name' => Miapp_Templates::doctorName(),
            'service_name' => $row['service_name'] ?? '',
            'status' => $row['status'] ?? '',
            'when' => $when,
            'patient_email' => $patientEmail,
          ]);
          wp_mail($patientEmail, $subj, $html, ['Content-Type: text/html; charset=UTF-8']);
        }
      }
    }

    return new WP_REST_Response(['ok'=>true], 200);
  }

public function practitionerAttendance(WP_REST_Request $req) {
    global $wpdb;
    $id = intval($req->get_param('id'));
    $status = strtoupper((string)$req->get_param('attendance_status'));
    $allowed = ['PENDING','ATTENDED','NO_SHOW','CANCELLED_BY_PRACTITIONER'];
    if (!in_array($status, $allowed, true)) return new WP_REST_Response(['error'=>'Estado inválido.'], 400);

    $t = $this->apptTable();
    $pid = $this->currentProviderId();
    $now = $this->nowUtc();
    $wpdb->update($t, [
      'attendance_status' => $status,
      'updated_at' => $now,
    ], ['id'=>$id], ['%s','%s'], ['%d']);

    return new WP_REST_Response(['ok'=>true], 200);
  }

  public function practitionerKpis() {
    global $wpdb;
    $t = $this->apptTable();
    $pid = $this->currentProviderId();

    $todayStart = (new DateTime('now', wp_timezone()))->setTime(0,0,0)->format('Y-m-d H:i:s');
    $todayEnd   = (new DateTime('now', wp_timezone()))->setTime(23,59,59)->format('Y-m-d H:i:s');

    $monthStart = (new DateTime('first day of this month', wp_timezone()))->setTime(0,0,0)->format('Y-m-d H:i:s');
    $monthEnd   = (new DateTime('last day of this month', wp_timezone()))->setTime(23,59,59)->format('Y-m-d H:i:s');

    $scheduledToday = intval($wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $t WHERE (%d=0 OR provider_id=%d) AND start_at BETWEEN %s AND %s",
      $pid, $pid, $todayStart, $todayEnd
    )));

    $confirmedToday = intval($wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $t WHERE (%d=0 OR provider_id=%d) AND status='CONFIRMED' AND last_status_changed_at BETWEEN %s AND %s",
      $pid, $pid, $todayStart, $todayEnd
    )));

    $paidToday = intval($wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $t WHERE (%d=0 OR provider_id=%d) AND payment_status='PAID' AND updated_at BETWEEN %s AND %s",
      $pid, $pid, $todayStart, $todayEnd
    )));

    $closedToday = intval($wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $t WHERE (%d=0 OR provider_id=%d) AND status='CLOSED' AND updated_at BETWEEN %s AND %s",
      $pid, $pid, $todayStart, $todayEnd
    )));

    $pendingMonth = intval($wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $t WHERE (%d=0 OR provider_id=%d) AND status IN ('PENDING','CONFIRMED') AND start_at BETWEEN %s AND %s",
      $pid, $pid, $monthStart, $monthEnd
    )));

    return new WP_REST_Response([
      'scheduled_today' => $scheduledToday,
      'confirmed_today' => $confirmedToday,
      'paid_today' => $paidToday,
      'closed_today' => $closedToday,
      'pending_month' => $pendingMonth,
    ], 200);
  }

  private function currentProviderId(): int {
    $uid = get_current_user_id();
    $pid = intval(get_user_meta($uid, 'miapp_provider_id', true));
    // If not set, allow admin to act as "all providers"
    if ($pid <= 0 && current_user_can('administrator')) return 0;
    return $pid;
  }

  public function providers(WP_REST_Request $req) {
    $providers = Miapp_Providers::listProviders(true);
    // add id+specialty only (name is in listProviders)
    return new WP_REST_Response(['providers'=>$providers], 200);
  }

  public function practitionerServices(WP_REST_Request $req) {
    $pid = $this->currentProviderId();
    if ($pid <= 0) {
      return new WP_REST_Response(['error'=>'PROVIDER_NOT_ASSIGNED'], 403);
    }

    if ($req->get_method() === 'POST') {
      $body = $req->get_json_params();
      $name = sanitize_text_field($body['name'] ?? '');
      if (!$name) return new WP_REST_Response(['error'=>'Nombre requerido'], 400);

      $postId = wp_insert_post([
        'post_type' => 'miapp_service',
        'post_title' => $name,
        'post_content' => wp_kses_post($body['description'] ?? ''),
        'post_status' => 'publish',
      ], true);

      if (is_wp_error($postId)) return new WP_REST_Response(['error'=>$postId->get_error_message()], 500);

      update_post_meta($postId, '_miapp_service_provider_id', (string)$pid);
      update_post_meta($postId, '_miapp_price_cents', intval($body['price_cop'] ?? 0) * 100);
      update_post_meta($postId, '_miapp_duration_min', intval($body['duration_min'] ?? 60));
      update_post_meta($postId, '_miapp_buffer_min', intval($body['buffer_min'] ?? 10));
      update_post_meta($postId, '_miapp_modes', sanitize_text_field($body['modes'] ?? 'VIRTUAL,PRESENTIAL'));
      update_post_meta($postId, '_miapp_indications', wp_kses_post($body['indications'] ?? ''));

      return new WP_REST_Response(['ok'=>true,'id'=>intval($postId)], 201);
    }

    // GET
    $services = Miapp_Services::listServicesByProvider($pid);
    return new WP_REST_Response(['services'=>$services], 200);
  }

  public function practitionerServiceUpdate(WP_REST_Request $req) {
    $pid = $this->currentProviderId();
    if ($pid <= 0) return new WP_REST_Response(['error'=>'PROVIDER_NOT_ASSIGNED'], 403);

    $id = intval($req['id']);
    $svc = get_post($id);
    if (!$svc || $svc->post_type !== 'miapp_service') return new WP_REST_Response(['error'=>'NOT_FOUND'], 404);

    $owner = intval(get_post_meta($id, '_miapp_service_provider_id', true));
    if ($owner !== $pid) return new WP_REST_Response(['error'=>'FORBIDDEN'], 403);

    if ($req->get_method() === 'DELETE') {
      wp_update_post(['ID'=>$id,'post_status'=>'draft']);
      return new WP_REST_Response(['ok'=>true], 200);
    }

    // PUT
    $body = $req->get_json_params();
    $name = sanitize_text_field($body['name'] ?? '');
    if ($name) {
      wp_update_post(['ID'=>$id,'post_title'=>$name, 'post_content'=>wp_kses_post($body['description'] ?? $svc->post_content)]);
    }
    if (isset($body['price_cop'])) update_post_meta($id, '_miapp_price_cents', intval($body['price_cop']) * 100);
    if (isset($body['duration_min'])) update_post_meta($id, '_miapp_duration_min', intval($body['duration_min']));
    if (isset($body['buffer_min'])) update_post_meta($id, '_miapp_buffer_min', intval($body['buffer_min']));
    if (isset($body['modes'])) update_post_meta($id, '_miapp_modes', sanitize_text_field($body['modes']));
    if (isset($body['indications'])) update_post_meta($id, '_miapp_indications', wp_kses_post($body['indications']));

    return new WP_REST_Response(['ok'=>true], 200);
  }


}
