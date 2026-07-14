<?php

declare(strict_types=1);

namespace DSAP;

final class ConversionTracker
{
    public function boot(): void
    {
        add_action('template_redirect', [$this, 'redirect'], 1);
        add_action('wp', [$this, 'trackPageView']);
    }

    public function trackPageView(): void
    {
        if (is_user_logged_in() || !is_singular('post')) {
            return;
        }
        $postId = (int) get_queried_object_id();
        if ($postId <= 0 || !get_post_meta($postId, '_dsap_job_id', true) || $this->isLikelyBot()) {
            return;
        }
        $key = $this->fingerprintKey($postId, 'page_view', $this->pacificNow()->format('Y-m-d'));
        if (get_transient($key) === false) {
            (new ConversionRepository())->record($postId, 'page_view');
            set_transient($key, 1, 12 * HOUR_IN_SECONDS);
        }
    }

    public function redirect(): void
    {
        if (empty($_GET['dsap_go'])) {
            return;
        }
        $postId = absint(wp_unslash($_GET['dsap_go']));
        $target = esc_url_raw((string) get_post_meta($postId, '_dsap_cta_target', true));
        $eventType = (string) get_post_meta($postId, '_dsap_cta_event_type', true);
        if ($postId <= 0 || $target === '' || !in_array($eventType, ['affiliate_click', 'internal_cta_click'], true)) {
            wp_die('リンク先を確認できませんでした。', 'Daily SEO AI Publisher', ['response' => 404]);
        }

        $key = $this->fingerprintKey($postId, $eventType, $this->pacificNow()->format('Y-m-d-H'));
        if (!$this->isLikelyBot() && get_transient($key) === false) {
            (new ConversionRepository())->record($postId, $eventType);
            set_transient($key, 1, 10 * MINUTE_IN_SECONDS);
        }

        nocache_headers();
        wp_redirect($target, 302, 'Daily SEO AI Publisher');
        exit;
    }

    private function fingerprintKey(int $postId, string $eventType, string $period): string
    {
        $fingerprint = hash('sha256', $postId . '|' . $eventType . '|' . $period . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return 'dsap_event_' . substr($fingerprint, 0, 32);
    }

    private function isLikelyBot(): bool
    {
        return preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|bingpreview/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) === 1;
    }

    private function pacificNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles'));
    }
}
