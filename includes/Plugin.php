<?php

declare(strict_types=1);

namespace DSAP;

use DSAP\Seo\SeoManager;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        Activator::maybeUpgrade();
        Settings::boot();
        AdminPage::boot();
        Scheduler::boot();
        (new ConversionTracker())->boot();
        (new GitHubUpdater())->boot();
        (new SeoManager())->boot();
        add_action('wp_enqueue_scripts', [$this, 'frontendAssets']);
        add_filter('body_class', [$this, 'bodyClasses']);
        add_filter('the_content', [$this, 'cleanLegacyArticleChrome'], 1);
        add_filter('the_content', [$this, 'enhanceArticleContent'], 20);
    }

    public function frontendAssets(): void
    {
        if (is_singular('post') && $this->isManagedPost((int) get_queried_object_id())) {
            wp_enqueue_style('dsap-frontend', DSAP_URL . 'assets/frontend.css', [], DSAP_VERSION);
        }
    }

    public function bodyClasses(array $classes): array
    {
        if (is_singular('post') && $this->isManagedPost((int) get_queried_object_id())) {
            $classes[] = 'dsap-managed-post';
        }
        return array_values(array_unique($classes));
    }

    public function cleanLegacyArticleChrome(string $content): string
    {
        if (is_admin()) {
            return $content;
        }

        $cleaned = preg_replace('/<section\b[^>]*class=(["\'])[^"\']*\bdsap-visual-(?:lead|chart)\b[^"\']*\1[^>]*>.*?<\/section>/is', '', $content);
        return is_string($cleaned) ? $cleaned : $content;
    }

    public function enhanceArticleContent(string $content): string
    {
        if (is_admin() || !is_singular('post')) {
            return $content;
        }
        $postId = get_the_ID();
        if ($postId <= 0 || $postId !== (int) get_queried_object_id() || !$this->isManagedPost($postId)) {
            return $content;
        }
        $enhanced = ArticleVisuals::enhance(
            $content,
            get_the_title($postId),
            (string) get_post_meta($postId, '_dsap_article_type', true),
            (string) get_post_meta($postId, '_dsap_answer_summary', true)
        );
        return str_contains($enhanced, 'dsap-article-content')
            ? $enhanced
            : '<div class="dsap-article-content">' . $enhanced . '</div>';
    }

    private function isManagedPost(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }
        return get_post_meta($postId, '_dsap_job_id', true) !== ''
            || get_post_meta($postId, '_dsap_article_type', true) !== ''
            || get_post_meta($postId, '_dsap_focus_keyword', true) !== '';
    }
}
