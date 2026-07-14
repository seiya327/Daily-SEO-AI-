<?php

declare(strict_types=1);

namespace DSAP;

final class GitHubUpdater
{
    public const CACHE_KEY = 'dsap_github_release_v1';
    private const REPOSITORY = 'seiya327/Daily-SEO-AI-';
    private const SLUG = 'daily-seo-ai-publisher';
    private const ZIP_ASSET = 'daily-seo-ai-publisher.zip';
    private const CHECKSUM_ASSET = 'daily-seo-ai-publisher.zip.sha256';

    public function boot(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'injectUpdate']);
        add_filter('plugins_api', [$this, 'pluginInformation'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'preDownload'], 10, 4);
        add_filter('auto_update_plugin', [$this, 'autoUpdate'], 10, 2);
    }

    public function injectUpdate($transient)
    {
        if (!is_object($transient) || empty($transient->checked) || empty(Settings::get()['github_updates_enabled'])) {
            return $transient;
        }
        $release = $this->release();
        if (is_wp_error($release) || version_compare((string) $release['version'], DSAP_VERSION, '<=')) {
            return $transient;
        }
        $plugin = plugin_basename(DSAP_FILE);
        $transient->response[$plugin] = (object) [
            'id' => 'github.com/' . self::REPOSITORY,
            'slug' => self::SLUG,
            'plugin' => $plugin,
            'new_version' => $release['version'],
            'url' => 'https://github.com/' . self::REPOSITORY,
            'package' => $release['package'],
            'tested' => $release['tested'],
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
        ];
        return $transient;
    }

    public function pluginInformation($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }
        $release = $this->release();
        if (is_wp_error($release)) {
            return $result;
        }
        return (object) [
            'name' => 'Daily SEO AI Publisher',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => '<a href="https://github.com/seiya327">seiya327</a>',
            'homepage' => 'https://github.com/' . self::REPOSITORY,
            'requires' => $release['requires'],
            'tested' => $release['tested'],
            'requires_php' => $release['requires_php'],
            'download_link' => $release['package'],
            'last_updated' => $release['published_at'],
            'sections' => [
                'description' => 'AIでSEO記事の戦略、リサーチ、執筆、監査、投稿、Search Consoleを使った改善を自動化します。',
                'changelog' => wpautop(esc_html((string) $release['notes'])),
            ],
        ];
    }

    public function preDownload($reply, string $package, $upgrader, array $hookExtra)
    {
        unset($upgrader, $hookExtra);
        if ($reply !== false || !$this->isOurPackage($package)) {
            return $reply;
        }
        $tempFile = wp_tempnam(self::ZIP_ASSET);
        if (!$tempFile) {
            return new \WP_Error('dsap_update_temp', '更新ZIP用の一時ファイルを作成できませんでした。');
        }
        $response = $this->assetRequest($package, $tempFile);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            @unlink($tempFile);
            return is_wp_error($response) ? $response : new \WP_Error('dsap_update_download', 'GitHub Release ZIPをダウンロードできませんでした。');
        }
        $release = $this->release();
        $expected = is_wp_error($release) ? '' : (string) ($release['sha256'] ?? '');
        $actual = hash_file('sha256', $tempFile);
        if ($expected === '' || !is_string($actual) || !hash_equals(strtolower($expected), strtolower($actual))) {
            @unlink($tempFile);
            return new \WP_Error('dsap_update_checksum', '更新ZIPのSHA-256検証に失敗しました。更新を中止しました。');
        }
        return $tempFile;
    }

    public function autoUpdate($update, $item)
    {
        if (is_object($item) && ($item->plugin ?? '') === plugin_basename(DSAP_FILE) && !empty(Settings::get()['github_auto_update'])) {
            return true;
        }
        return $update;
    }

    public function release(): array|\WP_Error
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $response = wp_remote_get('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest', [
            'timeout' => 20,
            'headers' => $this->headers(false),
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('dsap_github_network', $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($json)) {
            if ($code === 404) {
                return new \WP_Error(
                    'dsap_github_release',
                    'GitHub Releaseを取得できません。公開リポジトリなら v0.5.4 などのReleaseを作成してください。非公開リポジトリなら、設定の詳細設定にGitHubトークンを保存してください。'
                );
            }
            if ($code === 401 || $code === 403) {
                return new \WP_Error('dsap_github_auth', 'GitHubトークンの権限が足りないか期限切れです。対象リポジトリのContents読み取り権限を確認してください。');
            }
            return new \WP_Error('dsap_github_release', 'GitHub Release情報を取得できませんでした。HTTP ' . $code);
        }
        $zip = $this->asset($json, self::ZIP_ASSET);
        $checksum = $this->asset($json, self::CHECKSUM_ASSET);
        if ($zip === null || $checksum === null) {
            return new \WP_Error('dsap_github_assets', 'Releaseに daily-seo-ai-publisher.zip または daily-seo-ai-publisher.zip.sha256 がありません。');
        }
        $sha256 = $this->checksum($checksum);
        if (is_wp_error($sha256)) {
            return $sha256;
        }
        $token = (string) Settings::get()['github_token'];
        $package = $token !== '' ? (string) $zip['url'] : (string) $zip['browser_download_url'];
        $release = [
            'version' => ltrim((string) ($json['tag_name'] ?? ''), 'vV'),
            'package' => $package,
            'sha256' => $sha256,
            'notes' => (string) ($json['body'] ?? ''),
            'published_at' => (string) ($json['published_at'] ?? ''),
            'requires' => '6.5',
            'tested' => '6.6',
            'requires_php' => '8.0',
        ];
        if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $release['version'])) {
            return new \WP_Error('dsap_github_version', 'GitHub Releaseのバージョン形式が不正です。タグは v1.2.3 の形式にしてください。');
        }
        set_transient(self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS);
        return $release;
    }

    private function checksum(array $asset): string|\WP_Error
    {
        $token = (string) Settings::get()['github_token'];
        $url = $token !== '' ? (string) $asset['url'] : (string) $asset['browser_download_url'];
        $response = $this->assetRequest($url);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return new \WP_Error('dsap_github_checksum_download', 'SHA-256ファイルを取得できませんでした。');
        }
        $body = trim((string) wp_remote_retrieve_body($response));
        if (!preg_match('/^([a-f0-9]{64})(?:\s|$)/i', $body, $matches)) {
            return new \WP_Error('dsap_github_checksum_format', 'SHA-256ファイルの形式が不正です。');
        }
        return strtolower($matches[1]);
    }

    private function asset(array $release, string $name): ?array
    {
        foreach (($release['assets'] ?? []) as $asset) {
            if (is_array($asset) && ($asset['name'] ?? '') === $name) {
                return $asset;
            }
        }
        return null;
    }

    private function headers(bool $binary): array
    {
        $headers = [
            'Accept' => $binary ? 'application/octet-stream' : 'application/vnd.github+json',
            'User-Agent' => 'Daily-SEO-AI-Publisher/' . DSAP_VERSION,
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        $token = trim((string) Settings::get()['github_token']);
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    private function isOurPackage(string $package): bool
    {
        return str_starts_with($package, 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/assets/')
            || str_starts_with($package, 'https://github.com/' . self::REPOSITORY . '/releases/download/');
    }

    private function assetRequest(string $url, string $filename = '')
    {
        $isApiAsset = str_starts_with($url, 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/assets/');
        $args = [
            'timeout' => $filename !== '' ? 300 : 20,
            'redirection' => $isApiAsset ? 0 : 5,
            'headers' => $this->headers(true),
        ];
        if ($filename !== '') {
            $args['stream'] = true;
            $args['filename'] = $filename;
        }
        $response = wp_remote_get($url, $args);
        if (!$isApiAsset || is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 300 || $code >= 400) {
            return $response;
        }
        $location = (string) wp_remote_retrieve_header($response, 'location');
        if ($location === '') {
            return new \WP_Error('dsap_update_redirect', 'GitHubの更新ファイル転送先を取得できませんでした。');
        }
        $safeArgs = [
            'timeout' => $filename !== '' ? 300 : 20,
            'redirection' => 5,
            'headers' => ['User-Agent' => 'Daily-SEO-AI-Publisher/' . DSAP_VERSION],
        ];
        if ($filename !== '') {
            file_put_contents($filename, '');
            $safeArgs['stream'] = true;
            $safeArgs['filename'] = $filename;
        }
        return wp_remote_get($location, $safeArgs);
    }
}
