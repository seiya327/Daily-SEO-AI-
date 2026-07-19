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
        add_action('wp_head', [$this, 'renderArticleSchema'], 20);
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

    public function renderArticleSchema(): void
    {
        if (!is_singular('post')) {
            return;
        }
        $postId = (int) get_queried_object_id();
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || get_post_meta($postId, '_dsap_job_id', true) === '') {
            return;
        }
        $author = get_userdata((int) $post->post_author);
        $description = sanitize_text_field((string) get_post_meta($postId, '_dsap_meta_description', true));
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'mainEntityOfPage' => (string) get_permalink($postId),
            'headline' => sanitize_text_field((string) $post->post_title),
            'description' => $description,
            'datePublished' => get_post_time('c', true, $post),
            'dateModified' => get_post_modified_time('c', true, $post),
            'inLanguage' => (string) get_bloginfo('language'),
            'author' => [
                '@type' => 'Person',
                'name' => $author instanceof \WP_User ? (string) $author->display_name : (string) get_bloginfo('name'),
                'url' => $author instanceof \WP_User ? (string) get_author_posts_url((int) $author->ID) : home_url('/'),
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => (string) get_bloginfo('name'),
                'url' => home_url('/'),
            ],
        ];
        $imageId = (int) get_post_thumbnail_id($postId);
        $imageUrl = $imageId > 0 ? wp_get_attachment_image_url($imageId, 'full') : false;
        if (is_string($imageUrl) && $imageUrl !== '') {
            $schema['image'] = $imageUrl;
        }
        $keyword = sanitize_text_field((string) get_post_meta($postId, '_dsap_focus_keyword', true));
        if ($keyword !== '') {
            $schema['about'] = $keyword;
        }
        echo "\n" . '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
}
