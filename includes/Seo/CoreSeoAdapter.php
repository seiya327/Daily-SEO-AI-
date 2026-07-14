<?php

declare(strict_types=1);

namespace DSAP\Seo;

final class CoreSeoAdapter implements SeoAdapterInterface
{
    public function ownsMetaDescription(): bool
    {
        return true;
    }

    public function boot(): void
    {
        add_action('wp_head', [$this, 'renderMetaDescription'], 1);
    }

    public function renderMetaDescription(): void
    {
        if (!is_singular('post')) {
            return;
        }

        $postId = get_queried_object_id();
        if ($postId <= 0) {
            return;
        }

        $description = get_post_meta($postId, '_dsap_meta_description', true);
        if (!is_string($description) || trim($description) === '') {
            return;
        }

        echo "\n" . '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
    }
}
