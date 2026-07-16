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
    }

    public function frontendAssets(): void
    {
        if (is_singular('post')) {
            wp_enqueue_style('dsap-frontend', DSAP_URL . 'assets/frontend.css', [], DSAP_VERSION);
        }
    }
}
