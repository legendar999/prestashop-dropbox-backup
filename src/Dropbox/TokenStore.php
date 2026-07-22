<?php

declare(strict_types=1);

namespace Akvabackup\Dropbox;

/**
 * Stores and refreshes Dropbox OAuth2 credentials (offline access = persistent refresh token).
 * All secrets (app secret, refresh token) are encrypted at rest with PhpEncryption.
 * The short-lived access token (4 h) is cached in plain config with an expiry timestamp.
 * Tokens are NEVER written to a log or echoed.
 */
final class TokenStore
{
    private const TOKEN_ENDPOINT = 'https://api.dropboxapi.com/oauth2/token';
    private const AUTHORIZE_ENDPOINT = 'https://www.dropbox.com/oauth2/authorize';
    private const REFRESH_MARGIN = 120; // refresh when fewer than this many seconds remain until expiry
    private const DEFAULT_TTL = 14400;  // 4 h, if Dropbox does not return expires_in

    public function __construct()
    {
        // Credentials are read directly from Configuration on every call (no caching/aging).
    }

    public function isConnected(): bool
    {
        return $this->cfg('AKVABACKUP_REFRESH_TOKEN_ENC') !== ''
            && $this->cfg('AKVABACKUP_APP_KEY') !== ''
            && $this->cfg('AKVABACKUP_APP_SECRET_ENC') !== '';
    }

    public function connect(string $appKey, string $appSecret, string $authCode): void
    {
        $data = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $authCode,
            'client_id' => $appKey,
            'client_secret' => $appSecret,
        ]);

        if (empty($data['refresh_token']) || empty($data['access_token'])) {
            // 200 without a refresh_token = the authorization was not "offline" (the authorize
            // URL must contain token_access_type=offline). Diagnostics list ONLY the names of the
            // returned fields + scope (never token values) — enough to see how the consent was
            // actually granted.
            $keys = implode(',', array_keys($data));
            $scope = isset($data['scope']) ? (string) $data['scope'] : '(none)';
            throw new DropboxException('Dropbox did not return a refresh token - the authorization was not offline. Obtain a NEW code via a URL with token_access_type=offline (the old code is consumed). Returned fields: [' . $keys . '], scope: ' . $scope);
        }

        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : self::DEFAULT_TTL;

        \Configuration::updateGlobalValue('AKVABACKUP_APP_KEY', $appKey);
        \Configuration::updateGlobalValue('AKVABACKUP_APP_SECRET_ENC', $this->enc($appSecret));
        \Configuration::updateGlobalValue('AKVABACKUP_REFRESH_TOKEN_ENC', $this->enc((string) $data['refresh_token']));
        \Configuration::updateGlobalValue('AKVABACKUP_ACCESS_TOKEN', (string) $data['access_token']);
        \Configuration::updateGlobalValue('AKVABACKUP_ACCESS_EXPIRES', (string) (time() + $expiresIn));
    }

    public function disconnect(): void
    {
        \Configuration::updateGlobalValue('AKVABACKUP_APP_SECRET_ENC', '');
        \Configuration::updateGlobalValue('AKVABACKUP_REFRESH_TOKEN_ENC', '');
        \Configuration::updateGlobalValue('AKVABACKUP_ACCESS_TOKEN', '');
        \Configuration::updateGlobalValue('AKVABACKUP_ACCESS_EXPIRES', '0');
    }

    public function getAccessToken(): string
    {
        if (!$this->isConnected()) {
            throw new NotConnectedException('Dropbox is not connected.');
        }

        $token = $this->cfg('AKVABACKUP_ACCESS_TOKEN');
        $expires = (int) $this->cfg('AKVABACKUP_ACCESS_EXPIRES');

        if ($token !== '' && ($expires - time()) > self::REFRESH_MARGIN) {
            return $token;
        }

        return $this->refreshAccessToken();
    }

    public static function authorizeUrl(string $appKey): string
    {
        return self::AUTHORIZE_ENDPOINT . '?' . http_build_query([
            'client_id' => $appKey,
            'response_type' => 'code',
            'token_access_type' => 'offline',
            // Without this, for an already-approved app Dropbox silently reuses the OLD grant
            // (possibly non-offline and with a frozen scope) - observed in practice: 200 without
            // a refresh_token + a scope missing the later-added permissions. Always force a fresh
            // full consent.
            'force_reapprove' => 'true',
        ]);
    }

    private function refreshAccessToken(): string
    {
        $appKey = $this->cfg('AKVABACKUP_APP_KEY');
        $appSecret = $this->dec($this->cfg('AKVABACKUP_APP_SECRET_ENC'));
        $refresh = $this->dec($this->cfg('AKVABACKUP_REFRESH_TOKEN_ENC'));

        if ($appKey === '' || $appSecret === '' || $refresh === '') {
            throw new NotConnectedException('Dropbox credentials are missing or cannot be decrypted.');
        }

        $data = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id' => $appKey,
            'client_secret' => $appSecret,
        ]);

        if (empty($data['access_token'])) {
            throw new DropboxException('Dropbox token refresh failed.');
        }

        $token = (string) $data['access_token'];
        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : self::DEFAULT_TTL;

        \Configuration::updateGlobalValue('AKVABACKUP_ACCESS_TOKEN', $token);
        \Configuration::updateGlobalValue('AKVABACKUP_ACCESS_EXPIRES', (string) (time() + $expiresIn));

        return $token;
    }

    /**
     * Form-encoded POST to the Dropbox token endpoint. Returns the decoded JSON body.
     *
     * @param array<string,string> $post
     *
     * @return array<string,mixed>
     */
    private function tokenRequest(array $post): array
    {
        $ch = curl_init(self::TOKEN_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new DropboxException('Dropbox token request failed: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string) $body, true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            // The body is a Dropbox error (e.g. invalid_grant), contains no tokens - error +
            // error_description are safe to display and give the EXACT cause (invalid_grant =
            // code expired/already used; invalid_client = wrong app secret).
            $err = is_array($data = json_decode((string) $body, true)) ? $data : [];
            $hint = trim((string) ($err['error'] ?? '') . ' ' . (string) ($err['error_description'] ?? ''));
            throw new DropboxException('Dropbox token request HTTP ' . $code . ($hint !== '' ? ' (' . $hint . ')' : ''), $code, (string) $body);
        }

        return $data;
    }

    private function cfg(string $key): string
    {
        return (string) \Configuration::getGlobalValue($key);
    }

    private function enc(string $plain): string
    {
        $tool = new \PhpEncryption(_NEW_COOKIE_KEY_);

        return (string) $tool->encrypt($plain);
    }

    private function dec(string $cipher): string
    {
        if ($cipher === '') {
            return '';
        }
        $tool = new \PhpEncryption(_NEW_COOKIE_KEY_);
        $out = $tool->decrypt($cipher);

        return $out === false ? '' : (string) $out;
    }
}
