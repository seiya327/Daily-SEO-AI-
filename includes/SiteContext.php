<?php

declare(strict_types=1);

namespace DSAP;

final class SiteContext
{
    public static function forStrategy(int $limit = 80): array
    {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => ['publish', 'draft', 'pending'],
            'numberposts' => max(1, min(200, $limit)),
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $items = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $items[] = [
                'post_id' => (int) $post->ID,
                'title' => (string) $post->post_title,
                'status' => (string) $post->post_status,
                'focus_keyword' => (string) get_post_meta($post->ID, '_dsap_focus_keyword', true),
                'article_type' => (string) get_post_meta($post->ID, '_dsap_article_type', true),
                'cluster_name' => (string) get_post_meta($post->ID, '_dsap_cluster_name', true),
            ];
        }

        $planned = [];
        foreach ((new TopicRepository())->latest(100) as $topic) {
            $planned[] = [
                'keyword' => (string) ($topic['keyword'] ?? ''),
                'status' => (string) ($topic['status'] ?? ''),
                'article_type' => (string) ($topic['article_type'] ?? ''),
                'cluster_name' => (string) ($topic['cluster_name'] ?? ''),
            ];
        }

        return [
            'site_name' => get_bloginfo('name'),
            'site_description' => get_bloginfo('description'),
            'existing_content' => $items,
            'planned_content' => $planned,
        ];
    }

    public static function internalLinkCandidates(string $cluster, int $excludePostId = 0, int $limit = 12): array
    {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => max(1, min(30, $limit * 2)),
            'meta_query' => $cluster !== '' ? [
                ['key' => '_dsap_cluster_name', 'value' => $cluster],
            ] : [],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $candidates = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post || (int) $post->ID === $excludePostId) {
                continue;
            }
            $candidates[] = [
                'post_id' => (int) $post->ID,
                'title' => (string) $post->post_title,
                'url' => (string) get_permalink($post),
                'focus_keyword' => (string) get_post_meta($post->ID, '_dsap_focus_keyword', true),
                'article_type' => (string) get_post_meta($post->ID, '_dsap_article_type', true),
            ];
            if (count($candidates) >= $limit) {
                break;
            }
        }
        return $candidates;
    }
}
