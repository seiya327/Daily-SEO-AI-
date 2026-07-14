<?php

declare(strict_types=1);

namespace DSAP;

final class QualityGate
{
    public static function hardChecks(array $payload): string
    {
        $article = $payload['article'] ?? [];
        $research = $payload['research'] ?? [];

        if (!is_array($article) || !is_array($research)) {
            return 'Missing article or research payload.';
        }

        if (preg_match('/<\s*h1\b/i', (string) ($article['content_html'] ?? ''))) {
            return 'Article content must not contain H1.';
        }

        if (preg_match('/<\s*(script|iframe)\b|on\w+\s*=/i', (string) ($article['content_html'] ?? ''))) {
            return 'Article content contains disallowed HTML.';
        }

        $sources = $research['sources'] ?? [];
        foreach (($article['source_indexes'] ?? []) as $index) {
            if (!isset($sources[(int) $index])) {
                return 'Article references an out-of-range source index.';
            }
        }

        return '';
    }

    public static function decision(array $payload, array $settings): array
    {
        $audit = is_array($payload['audit'] ?? null) ? $payload['audit'] : [];
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $ymyl = !empty($audit['ymyl']) || !empty($article['ymyl']);
        $score = (int) ($audit['overall_score'] ?? 0);
        $critical = is_array($audit['critical_issues'] ?? null) ? $audit['critical_issues'] : [];
        $unsupported = is_array($audit['unsupported_claims'] ?? null) ? $audit['unsupported_claims'] : [];
        $status = (string) ($settings['post_status'] ?? 'draft');
        $profile = Settings::qualityProfile((string) ($settings['article_quality'] ?? 'high'));
        $minimumScore = (int) ($profile['audit_score'] ?? 85);

        if (!empty($payload['test_mode']) || $ymyl || $score < $minimumScore || $critical !== [] || $unsupported !== []) {
            $status = 'draft';
        }

        if (!in_array($status, ['draft', 'pending', 'publish'], true)) {
            $status = 'draft';
        }

        return [
            'post_status' => $status,
            'score' => $score,
            'ymyl' => $ymyl,
            'critical_count' => count($critical),
            'unsupported_count' => count($unsupported),
            'test_mode' => !empty($payload['test_mode']),
        ];
    }

    public static function hardChecksRefresh(array $article): string
    {
        $html = (string) ($article['content_html'] ?? '');
        if ($html === '') {
            return 'Refresh article content is empty.';
        }
        if (preg_match('/<\s*h1\b/i', $html)) {
            return 'Refresh article must not contain H1.';
        }
        if (preg_match('/<\s*(script|iframe)\b|on\w+\s*=/i', $html)) {
            return 'Refresh article contains disallowed HTML.';
        }
        return '';
    }
}
