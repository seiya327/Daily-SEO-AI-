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
        (new GitHubUpdater())->boot();
        (new SeoManager())->boot();
    }
}
