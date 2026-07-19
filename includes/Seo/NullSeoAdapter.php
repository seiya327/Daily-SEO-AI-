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
        add_filter('wpseo_metadesc', [$this, 'description'], 20);
        add_filter('rank_math/frontend/description', [$this, 'description'], 20);
        add_filter('aioseo_description', [$this, 'description'], 20);
    }

    public function description($description): string
    {
        if (!is_singular('post')) {
            return is_string($description) ? $description : '';
        }
        $postId = (int) get_queried_object_id();
        $generated = $postId > 0 ? get_post_meta($postId, '_dsap_meta_description', true) : '';
        return is_string($generated) && trim($generated) !== ''
            ? $generated
            : (is_string($description) ? $description : '');
    }
}
