<?php

declare(strict_types=1);

namespace DSAP\Seo;

final class SeoManager
{
    public function boot(): void
    {
        $this->adapter()->boot();
    }

    private function adapter(): SeoAdapterInterface
    {
        if ($this->knownSeoPluginActive()) {
            return new NullSeoAdapter();
        }

        return new CoreSeoAdapter();
    }

    private function knownSeoPluginActive(): bool
    {
        return defined('WPSEO_VERSION')
            || defined('RANK_MATH_VERSION')
            || defined('AIOSEO_VERSION')
            || class_exists('WPSEO_Frontend')
            || class_exists('RankMath')
            || class_exists('AIOSEO\\Plugin\\AIOSEO');
    }
}
