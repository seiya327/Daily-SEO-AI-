<?php

declare(strict_types=1);

namespace DSAP;

final class GoogleOAuth
{
    private const TOKEN_OPTION = 'dsap_gsc_tokens';
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly';

    public static function connected(): bool
    {
        $tokens = get_option(self::TOKEN_OPTION, []);
        return is_array($tokens) && !empty($tokens['refresh_token']);
    }

    public static function redirectUri(): string
    {
        return admin_url('admin-post.php?action=dsap_gsc_callback');
    }

    public static function authorizationUrl(): string|\WP_Error
    {
        $settings = Settings::get();
        if ($settings['gsc_client_id'] === '' || $settings['gsc_client_secret'] === '') {
            return new \WP_Error('dsap_gsc_credentials', 'Google OAuthのクライアントIDとシークレットを設定してください。');
        }

        $state = wp_generate_password(48, false, false);
        set_transient('dsap_gsc_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);
        return add_query_arg([
            'client_id' => $settings['gsc_client_id'],
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], 'https://accounts.google.com/o/oauth2/v2/auth');
    }

    public static function handleCallback(string $code, string $state): true|\WP_Error
    {
        $key = 'dsap_gsc_state_' . get_current_user_id();
        $expected = get_transient($key);
        delete_transient($key);
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $state)) {
            return new \WP_Error('dsap_gsc_state', 'Google接続のstate検証に失敗しました。最初から接続し直してください。');
        }

        $settings = Settings::get();
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 30,
            'body' => [
                'code' => $code,
                'client_id' => $settings['gsc_client_id'],
                'client_secret' => $settings['gsc_client_secret'],
                'redirect_uri' => self::redirectUri(),
                'grant_type' => 'authorization_code',
            ],
        ]);
        return self::storeTokenResponse($response, true);
    }

    public static function accessToken(): string|\WP_Error
    {
        $tokens = get_option(self::TOKEN_OPTION, []);
        if (!is_array($tokens) || empty($tokens['refresh_token'])) {
            return new \WP_Error('dsap_gsc_not_connected', 'Google Search Consoleが接続されていません。');
        }
        if (!empty($tokens['access_token']) && (int) ($tokens['expires_at'] ?? 0) > time() + 60) {
            return (string) $tokens['access_token'];
        }

        $settings = Settings::get();
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 30,
            'body' => [
                'client_id' => $settings['gsc_client_id'],
                'client_secret' => $settings['gsc_client_secret'],
                'refresh_token' => $tokens['refresh_token'],
                'grant_type' => 'refresh_token',
            ],
        ]);
        $stored = self::storeTokenResponse($response, false);
        if (is_wp_error($stored)) {
            return $stored;
        }
        $tokens = get_option(self::TOKEN_OPTION, []);
        return is_array($tokens) && !empty($tokens['access_token']) ? (string) $tokens['access_token'] : new \WP_Error('dsap_gsc_token_missing', 'Googleアクセストークンを取得できませんでした。');
    }

    public static function disconnect(): void
    {
        $tokens = get_option(self::TOKEN_OPTION, []);
        $token = is_array($tokens) ? (string) ($tokens['refresh_token'] ?? $tokens['access_token'] ?? '') : '';
        if ($token !== '') {
            wp_remote_post('https://oauth2.googleapis.com/revoke', [
                'timeout' => 10,
                'body' => ['token' => $token],
            ]);
        }
        delete_option(self::TOKEN_OPTION);
    }

    private static function storeTokenResponse($response, bool $requireRefresh): true|\WP_Error
    {
        if (is_wp_error($response)) {
            return new \WP_Error('dsap_gsc_network', $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['access_token'])) {
            $message = is_array($json) && !empty($json['error_description']) ? (string) $json['error_description'] : 'Google OAuthトークンを取得できませんでした。';
            return new \WP_Error('dsap_gsc_oauth', $message);
        }
        $old = get_option(self::TOKEN_OPTION, []);
        $refreshToken = (string) ($json['refresh_token'] ?? (is_array($old) ? ($old['refresh_token'] ?? '') : ''));
        if ($requireRefresh && $refreshToken === '') {
            return new \WP_Error('dsap_gsc_refresh_token', '更新トークンが返されませんでした。Google接続を解除して再承認してください。');
        }
        update_option(self::TOKEN_OPTION, [
            'access_token' => (string) $json['access_token'],
            'refresh_token' => $refreshToken,
            'expires_at' => time() + max(60, (int) ($json['expires_in'] ?? 3600)),
            'scope' => (string) ($json['scope'] ?? self::SCOPE),
        ], false);
        return true;
    }
}
