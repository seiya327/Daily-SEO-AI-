<?php

declare(strict_types=1);

namespace DSAP;

final class SearchConsoleClient
{
    public function listSites(): array|\WP_Error
    {
        $token = GoogleOAuth::accessToken();
        if (is_wp_error($token)) {
            return $token;
        }
        $response = wp_remote_get('https://searchconsole.googleapis.com/webmasters/v3/sites', [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('dsap_gsc_network', $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($json) && !empty($json['error']['message']) ? (string) $json['error']['message'] : 'Search Console property list request failed.';
            return new \WP_Error('dsap_gsc_sites', $message);
        }
        $sites = [];
        foreach (($json['siteEntry'] ?? []) as $entry) {
            if (!empty($entry['siteUrl'])) {
                $sites[] = ['siteUrl' => (string) $entry['siteUrl'], 'permissionLevel' => (string) ($entry['permissionLevel'] ?? '')];
            }
        }
        return $sites;
    }

    public function syncDate(string $date): int|\WP_Error
    {
        return $this->syncRange($date, $date);
    }

    public function backfill(int $days = 59): int|\WP_Error
    {
        $end = new \DateTimeImmutable('-3 days', new \DateTimeZone('America/Los_Angeles'));
        $start = $end->modify('-' . max(1, $days - 1) . ' days');
        return $this->syncRange($start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    public function syncRange(string $startDate, string $endDate): int|\WP_Error
    {
        $settings = Settings::get();
        $siteUrl = trim((string) $settings['gsc_site_url']);
        if ($siteUrl === '') {
            return new \WP_Error('dsap_gsc_site', 'Search Consoleのプロパティを設定してください。');
        }
        $token = GoogleOAuth::accessToken();
        if (is_wp_error($token)) {
            return $token;
        }

        $rows = [];
        $startRow = 0;
        do {
            $batch = $this->query($token, $siteUrl, $startDate, $endDate, $startRow);
            if (is_wp_error($batch)) {
                return $batch;
            }
            $rows = array_merge($rows, $batch);
            $count = count($batch);
            $startRow += $count;
        } while ($count === 25000 && $startRow < 100000);

        $byDate = [];
        foreach ($rows as $row) {
            $keys = is_array($row['keys'] ?? null) ? $row['keys'] : [];
            if (count($keys) < 3) {
                continue;
            }
            $date = (string) $keys[0];
            $row['keys'] = [(string) $keys[1], (string) $keys[2]];
            $byDate[$date][] = $row;
        }
        $saved = 0;
        $repo = new MetricsRepository();
        $gscTimezone = new \DateTimeZone('America/Los_Angeles');
        $cursor = new \DateTimeImmutable($startDate, $gscTimezone);
        $last = new \DateTimeImmutable($endDate, $gscTimezone);
        while ($cursor <= $last) {
            $date = $cursor->format('Y-m-d');
            $saved += $repo->replaceDate($date, $byDate[$date] ?? [], false);
            $cursor = $cursor->modify('+1 day');
        }
        update_option('dsap_gsc_last_sync', ['date' => $startDate . ' - ' . $endDate, 'rows' => $saved, 'synced_at' => current_time('mysql')], false);
        return $saved;
    }

    private function query(string $token, string $siteUrl, string $startDate, string $endDate, int $startRow): array|\WP_Error
    {
        $url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';
        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => ['date', 'page', 'query'],
                'type' => 'web',
                'dataState' => 'final',
                'rowLimit' => 25000,
                'startRow' => $startRow,
            ]),
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('dsap_gsc_network', $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($json) && !empty($json['error']['message']) ? (string) $json['error']['message'] : 'Search Console API request failed.';
            return new \WP_Error('dsap_gsc_api', $message, ['status' => $code]);
        }
        return is_array($json['rows'] ?? null) ? $json['rows'] : [];
    }
}
