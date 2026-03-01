<?php
if (!defined('ABSPATH')) exit;

/**
 * Google Calendar + Meet (sin vendor/composer)
 * - OAuth2 manual (auth url + token exchange + refresh)
 * - Crea eventos (y Meet link si es virtual)
 */
class Miapp_Google {
  private string $clientId;
  private string $clientSecret;
  private string $calendarId;
  private string $redirectUri;

  public function __construct() {
    $this->clientId = (string)get_option('miapp_google_client_id','');
    $this->clientSecret = (string)get_option('miapp_google_client_secret','');
    $this->calendarId = (string)get_option('miapp_google_calendar_id','primary');
    // Redirect a la pestaña Google para capturar code
    $this->redirectUri = admin_url('admin.php?page=miapp-booking&tab=google');

    if (!$this->clientId || !$this->clientSecret) {
      throw new \Exception('Configura Google Client ID y Client Secret en Miapp Booking → Google.');
    }
  }

  public function setCalendarId(string $calendarId): void {
    $this->calendarId = $calendarId ?: $this->calendarId;
  }

  public function isConnected(): bool {
    return (bool)get_option('miapp_google_token','');
  }

  public function authUrl(): string {
    $params = [
      'client_id' => $this->clientId,
      'redirect_uri' => $this->redirectUri,
      'response_type' => 'code',
      'access_type' => 'offline',
      'prompt' => 'consent',
      'scope' => implode(' ', [
        'https://www.googleapis.com/auth/calendar',
        'https://www.googleapis.com/auth/calendar.events',
      ]),
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
  }

  public function handleCode(string $code): void {
    $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
      'timeout' => 15,
      'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
      'body' => [
        'code' => $code,
        'client_id' => $this->clientId,
        'client_secret' => $this->clientSecret,
        'redirect_uri' => $this->redirectUri,
        'grant_type' => 'authorization_code',
      ],
    ]);

    if (is_wp_error($resp)) {
      throw new \Exception('Error conectando a Google: '.$resp->get_error_message());
    }

    $body = wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);
    if (!is_array($json)) {
      throw new \Exception('Respuesta inválida de Google OAuth.');
    }

    if (!empty($json['error'])) {
      $desc = $json['error_description'] ?? $json['error'];
      throw new \Exception('OAuth error: '.$desc);
    }

    // Guardar token cifrado
    update_option('miapp_google_token', Miapp_Crypto::encrypt(json_encode($json)));
  }

  private function getToken(): array {
    $enc = (string)get_option('miapp_google_token','');
    $dec = Miapp_Crypto::decrypt($enc);
    $tok = $dec ? json_decode($dec, true) : null;
    return is_array($tok) ? $tok : [];
  }

  private function saveToken(array $tok): void {
    update_option('miapp_google_token', Miapp_Crypto::encrypt(json_encode($tok)));
  }

  /**
   * Obtiene access_token válido (refresca si expiró)
   */
  public function accessToken(): string {
    $tok = $this->getToken();
    if (empty($tok['access_token'])) {
      throw new \Exception('Google no está conectado.');
    }

    // Si no tenemos expires_in + created, asumimos válido
    $created = isset($tok['created']) ? intval($tok['created']) : 0;
    $expiresIn = isset($tok['expires_in']) ? intval($tok['expires_in']) : 0;

    $isExpired = false;
    if ($created && $expiresIn) {
      $isExpired = time() >= ($created + $expiresIn - 60);
    }

    if (!$isExpired) return (string)$tok['access_token'];

    $refresh = $tok['refresh_token'] ?? '';
    if (!$refresh) {
      throw new \Exception('Token expiró y no hay refresh_token. Reconecta Google.');
    }

    $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
      'timeout' => 15,
      'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
      'body' => [
        'client_id' => $this->clientId,
        'client_secret' => $this->clientSecret,
        'refresh_token' => $refresh,
        'grant_type' => 'refresh_token',
      ],
    ]);

    if (is_wp_error($resp)) {
      throw new \Exception('Error refrescando token Google: '.$resp->get_error_message());
    }

    $body = wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);
    if (!is_array($json) || !empty($json['error'])) {
      $desc = is_array($json) ? ($json['error_description'] ?? $json['error'] ?? 'unknown') : 'invalid json';
      throw new \Exception('Refresh token error: '.$desc);
    }

    $newTok = array_merge($tok, $json);
    if (empty($newTok['refresh_token'])) $newTok['refresh_token'] = $refresh;
    $newTok['created'] = time();
    $this->saveToken($newTok);

    return (string)$newTok['access_token'];
  }

  /**
   * Crea evento en Calendar, y si $virtual=true crea Google Meet.
   * Retorna: ['event_id'=>..., 'htmlLink'=>..., 'meetLink'=>...]
   */
  public function createEvent(array $payload): array {
    $token = $this->accessToken();

    $calendarId = rawurlencode($this->calendarId ?: 'primary');
    $url = 'https://www.googleapis.com/calendar/v3/calendars/'.$calendarId.'/events?conferenceDataVersion=1';

    $resp = wp_remote_post($url, [
      'timeout' => 20,
      'headers' => [
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($resp)) {
      throw new \Exception('Error creando evento Google: '.$resp->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);

    if ($code < 200 || $code >= 300 || !is_array($json)) {
      $msg = is_array($json) ? ($json['error']['message'] ?? 'unknown') : 'invalid response';
      throw new \Exception('Google Calendar error: '.$msg);
    }

    $meet = $json['hangoutLink'] ?? '';
    if (!$meet && !empty($json['conferenceData']['entryPoints']) && is_array($json['conferenceData']['entryPoints'])) {
      foreach ($json['conferenceData']['entryPoints'] as $ep) {
        if (($ep['entryPointType'] ?? '') === 'video' && !empty($ep['uri'])) {
          $meet = $ep['uri'];
          break;
        }
      }
    }

    return [
      'event_id' => $json['id'] ?? '',
      'htmlLink' => $json['htmlLink'] ?? '',
      'meetLink' => $meet,
    ];
  }

  /**
   * Lista eventos entre timeMin/timeMax (ISO 8601) y retorna rangos ocupados.
   * Retorna: [ ['start'=>DateTime::ATOM, 'end'=>DateTime::ATOM], ... ]
   */
  public function busyRanges(string $timeMinIso, string $timeMaxIso): array {
    $token = $this->accessToken();
    $calendarId = rawurlencode($this->calendarId ?: 'primary');

    $url = add_query_arg([
      'singleEvents' => 'true',
      'orderBy' => 'startTime',
      'timeMin' => $timeMinIso,
      'timeMax' => $timeMaxIso,
      'maxResults' => 2500,
    ], 'https://www.googleapis.com/calendar/v3/calendars/'.$calendarId.'/events');

    $resp = wp_remote_get($url, [
      'timeout' => 20,
      'headers' => [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
      ],
    ]);

    if (is_wp_error($resp)) {
      throw new \Exception('Error listando eventos Google: '.$resp->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);
    if ($code < 200 || $code >= 300 || !is_array($json)) {
      $msg = is_array($json) ? ($json['error']['message'] ?? 'unknown') : 'invalid response';
      throw new \Exception('Google Calendar error: '.$msg);
    }

    $busy = [];
    $items = $json['items'] ?? [];
    if (!is_array($items)) return $busy;

    foreach ($items as $it) {
      if (!is_array($it)) continue;
      if (($it['status'] ?? '') === 'cancelled') continue;
      $st = $it['start']['dateTime'] ?? null;
      $en = $it['end']['dateTime'] ?? null;
      if (!$st || !$en) continue; // ignora all-day
      $busy[] = ['start' => $st, 'end' => $en];
    }

    return $busy;
  }
}