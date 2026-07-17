<?php

declare(strict_types=1);

namespace DSAP;

final class AnalyticsClient
{
    public function backfill(int $days = 59): int|\WP_Error
    {
        $end = new \DateTimeImmutable('-1 day', new \DateTimeZone('America/Los_Angeles'));
        $start = $end->modify('-' . max(1, $days - 1) . ' days');
        return $this->syncRange($start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    public function syncRange(string $startDate, string $endDate): int|\WP_Error
    {
        $settings = Settings::get();
        $propertyId = preg_replace('/\D+/', '', (string) ($settings['ga4_property_id'] ?? '')) ?: '';
        if ($propertyId === '') {
            return new \WP_Error('dsap_ga4_property', 'GA4 property ID is not configured.');
        }

        $token = GoogleOAuth::accessToken();
        if (is_wp_error($token)) {
            return $token;
        }

        $rows = $this->query($token, $propertyId, $startDate, $endDate);
        if (is_wp_error($rows)) {
            return $rows;
        }

        $repo = new ConversionRepository();
        $saved = 0;
        foreach ($rows as $row) {
            $dimensions = is_array($row['dimensionValues'] ?? null) ? $row['dimensionValues'] : [];
            $metrics = is_array($row['metricValues'] ?? null) ? $row['metricValues'] : [];
            $dateRaw = (string) ($dimensions[0]['value'] ?? '');
            $path = (string) ($dimensions[1]['value'] ?? '');
            if (!preg_match('/^\d{8}$/', $dateRaw) || $path === '') {
                continue;
            }
            $url = home_url($path);
            $postId = url_to_postid($url);
            if ($postId <= 0 || get_post_type($postId) !== 'post') {
                continue;
            }
            $date = substr($dateRaw, 0, 4) . '-' . substr($dateRaw, 4, 2) . '-' . substr($dateRaw, 6, 2);
            $views = max(0, (int) round((float) ($metrics[0]['value'] ?? 0)));
            $engagementSeconds = max(0, (int) round((float) ($metrics[1]['value'] ?? 0)));
            $keyEvents = max(0, (int) round((float) ($metrics[2]['value'] ?? 0)));
            $saved += $repo->replaceExternal((int) $postId, $date, 'ga4_page_view', $views) ? 1 : 0;
            $saved += $repo->replaceExternal((int) $postId, $date, 'ga4_engagement_seconds', $engagementSeconds) ? 1 : 0;
            $saved += $repo->replaceExternal((int) $postId, $date, 'ga4_key_event', $keyEvents) ? 1 : 0;
        }

        update_option('dsap_ga4_last_sync', ['date' => $startDate . ' - ' . $endDate, 'rows' => $saved, 'synced_at' => current_time('mysql')], false);
        return $saved;
    }

    private function query(string $token, string $propertyId, string $startDate, string $endDate): array|\WP_Error
    {
        $response = wp_remote_post('https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($propertyId) . ':runReport', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
                'dimensions' => [
                    ['name' => 'date'],
                    ['name' => 'unifiedPagePathScreen'],
                ],
                'metrics' => [
                    ['name' => 'screenPageViews'],
                    ['name' => 'userEngagementDuration'],
                    ['name' => 'keyEvents'],
                ],
                'limit' => 10000,
            ]),
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('dsap_ga4_network', $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($json) && !empty($json['error']['message']) ? (string) $json['error']['message'] : 'GA4 Data API request failed.';
            return new \WP_Error('dsap_ga4_api', $message, ['status' => $code]);
        }
        return is_array($json['rows'] ?? null) ? $json['rows'] : [];
    }
}
