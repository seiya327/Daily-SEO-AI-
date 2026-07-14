<?php

declare(strict_types=1);

namespace DSAP\Seo;

final class NullSeoAdapter implements SeoAdapterInterface
{
    public function ownsMetaDescription(): bool
    {
        return false;
    }

    public function boot(): void
    {
    }
}
