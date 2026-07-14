<?php

declare(strict_types=1);

namespace DSAP\Seo;

interface SeoAdapterInterface
{
    public function ownsMetaDescription(): bool;

    public function boot(): void;
}
