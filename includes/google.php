<?php
if (!defined('ABSPATH')) exit;

class Miapp_Google {
  private $client;

  public function __construct() {
    if (!file_exists(MIAPP_DIR.'vendor/autoload.php')) {
      throw new Exception("Falta vendor/autoload.php. Corre composer install.");
    }
    require_once MIAPP_DIR.'vendor/autoload.php';

    $id = get_option('miapp_google_client_id','');
    $sec = get_option('miapp_google_client_secret','');
    if (!$id || !$sec) throw new Exception("Configura Client ID/Secret.");

    $this->client = new Google\Client();
    $this->client->setClientId($id);
    $this->client->setClientSecret($sec);
    $this->client->setRedirectUri(admin_url('admin.php?page=miapp-booking'));
    $this->client->setAccessType('offline');
    $this->client->setPrompt('consent');
    $this->client->setScopes([Google\Service\Calendar::CALENDAR, Google\Service\Calendar::CALENDAR_EVENTS]);

    $tokEnc = get_option('miapp_google_token','');
    $tokJson = Miapp_Crypto::decrypt($tokEnc);
    if ($tokJson) {
      $this->client->setAccessToken(json_decode($tokJson,true));
      if ($this->client->isAccessTokenExpired()) {
        $refresh = $this->client->getRefreshToken();
        if ($refresh) {
          $new = $this->client->fetchAccessTokenWithRefreshToken($refresh);
          if (!isset($new['error'])) {
            if (!isset($new['refresh_token'])) $new['refresh_token'] = $refresh;
            update_option('miapp_google_token', Miapp_Crypto::encrypt(json_encode($new)));
            $this->client->setAccessToken($new);
          }
        }
      }
    }
  }

  public function isConnected(): bool {
    return (bool)get_option('miapp_google_token','');
  }
  public function authUrl(): string { return $this->client->createAuthUrl(); }
  public function handleCode(string $code): void {
    $token = $this->client->fetchAccessTokenWithAuthCode($code);
    if (isset($token['error'])) throw new Exception($token['error_description'] ?? 'OAuth error');
    update_option('miapp_google_token', Miapp_Crypto::encrypt(json_encode($token)));
  }
  public function svc(): Google\Service\Calendar {
    return new Google\Service\Calendar($this->client);
  }
}
