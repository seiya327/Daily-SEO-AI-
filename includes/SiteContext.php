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

        $performance = [];
        $metrics = new MetricsRepository();
        $performancePostIds = array_slice(array_values(array_unique(array_merge(
            $metrics->postIds(24),
            $metrics->managedPostIds(24)
        ))), 0, 24);
        foreach ($performancePostIds as $postId) {
            $post = get_post($postId);
            if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
                continue;
            }
            $comparison = $metrics->comparison($postId);
            $performance[] = [
                'post_id' => $postId,
                'title' => (string) $post->post_title,
                'focus_keyword' => (string) get_post_meta($postId, '_dsap_focus_keyword', true),
                'article_type' => (string) get_post_meta($postId, '_dsap_article_type', true),
                'cluster_name' => (string) get_post_meta($postId, '_dsap_cluster_name', true),
                'current' => $comparison['current'],
                'previous' => $comparison['previous'],
                'current_cta' => $comparison['current_cta'],
                'previous_cta' => $comparison['previous_cta'],
                'top_queries' => $metrics->topQueries($postId, $comparison['current_range'][0], $comparison['current_range'][1], 8),
            ];
        }

        return [
            'site_name' => get_bloginfo('name'),
            'site_description' => get_bloginfo('description'),
            'existing_content' => $items,
            'planned_content' => $planned,
            'performance_evidence' => $performance,
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
