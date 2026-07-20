<?php

declare(strict_types=1);

namespace DSAP;

final class QualityGate
{
    public static function hardChecks(array $payload): string
    {
        $diagnostics = self::diagnostics($payload);
        return $diagnostics['errors'] === [] ? '' : implode(' / ', array_slice($diagnostics['errors'], 0, 5));
    }

    public static function diagnostics(array $payload): array
    {
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $research = is_array($payload['research'] ?? null) ? $payload['research'] : [];
        $funnel = is_array($payload['funnel'] ?? null) ? $payload['funnel'] : [];
        $errors = [];
        $warnings = [];
        if ($article === [] || $research === []) {
            return ['passed' => false, 'errors' => ['Missing article or research payload.'], 'warnings' => [], 'metrics' => []];
        }

        $html = (string) ($article['content_html'] ?? '');
        $text = trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $length = self::length($text);
        $profile = Settings::qualityProfile();
        $minimum = (int) round((int) ($profile['min_words'] ?? 3000) * 0.78);
        $quality = (string) Settings::get()['article_quality'];
        $minimumH2 = ['standard' => 3, 'high' => 5, 'premium' => 6][$quality] ?? 5;
        preg_match_all('/<h2\b[^>]*>/i', $html, $h2Matches);
        preg_match_all('/<p\b[^>]*>.*?<\/p>/is', $html, $paragraphMatches);
        preg_match_all('/<table\b[^>]*>/i', $html, $tableMatches);
        preg_match_all('/<(?:ul|ol)\b[^>]*>/i', $html, $listMatches);
        $h2Count = count($h2Matches[0] ?? []);
        $paragraphCount = count($paragraphMatches[0] ?? []);
        $tableCount = count($tableMatches[0] ?? []);
        $listCount = count($listMatches[0] ?? []);
        $longParagraphCount = 0;
        foreach (($paragraphMatches[0] ?? []) as $paragraph) {
            if (self::length(trim(wp_strip_all_tags((string) $paragraph))) > 450) {
                $longParagraphCount++;
            }
        }

        if ($html === '') {
            $errors[] = 'Article content is empty.';
        }
        if (preg_match('/<\s*h1\b/i', $html)) {
            $errors[] = 'Article content must not contain H1.';
        }
        if (preg_match('/<\s*(script|iframe|form)\b|on\w+\s*=/i', $html)) {
            $errors[] = 'Article content contains disallowed HTML.';
        }
        if ($length < $minimum) {
            $errors[] = "本文が品質基準より短すぎます（{$length}文字、最低目安{$minimum}文字）。";
        }
        if ($h2Count < $minimumH2) {
            $errors[] = "主要セクションが不足しています（H2 {$h2Count}件、最低{$minimumH2}件）。";
        }
        if ($paragraphCount < 8) {
            $warnings[] = '本文の段落数が少なく、説明が粗い可能性があります。';
        }
        if ($tableCount < 1) {
            $errors[] = '比較・判断に使えるHTML表がありません。少なくとも1つ追加してください。';
        }
        if ($listCount < 1) {
            $errors[] = '手順またはチェック項目を示す箇条書きがありません。少なくとも1つ追加してください。';
        }
        if (self::length(trim((string) ($article['answer_summary'] ?? ''))) < 30) {
            $errors[] = '読者向けの要点要約が不足しています。';
        }
        if ($longParagraphCount > 2) {
            $warnings[] = "450文字を超える長い段落が{$longParagraphCount}件あります。段落を分割してください。";
        }
        if (preg_match('/(ここに.{0,20}(入力|記載|追加)|Lorem ipsum|TODO|架空の|サンプルテキスト)/iu', $text)) {
            $errors[] = '本文にプレースホルダーまたは未完成表現が含まれます。';
        }
        if (preg_match('/(判断材料の整理|根拠の量|構成の深さ|内部導線|品質判定|監査スコア|AI監査)/u', $text)) {
            $errors[] = '本文に読者へ表示してはいけない内部管理文言が含まれます。';
        }

        $sources = is_array($research['sources'] ?? null) ? $research['sources'] : [];
        $sourceIndexes = array_values(array_unique(array_map('intval', is_array($article['source_indexes'] ?? null) ? $article['source_indexes'] : [])));
        if (count($sourceIndexes) < 3) {
            $errors[] = 'Article must reference at least 3 verified sources.';
        }
        foreach ($sourceIndexes as $index) {
            if (!isset($sources[$index])) {
                $errors[] = 'Article references an out-of-range source index.';
                break;
            }
        }

        $candidateIds = [];
        foreach (($payload['internal_link_candidates'] ?? []) as $candidate) {
            if (is_array($candidate) && !empty($candidate['post_id'])) {
                $candidateIds[] = (int) $candidate['post_id'];
            }
        }
        foreach (($article['internal_link_post_ids'] ?? []) as $postId) {
            if (!in_array((int) $postId, $candidateIds, true)) {
                $errors[] = 'Article selected an internal link outside the approved candidate list.';
                break;
            }
        }

        if (self::length(trim((string) ($article['cta_lead'] ?? ''))) < 10 || self::length(trim((string) ($article['cta_anchor'] ?? ''))) < 3) {
            $errors[] = 'CTA copy is missing or too generic.';
        }
        if (($funnel['article_type'] ?? 'attraction') === 'cv') {
            $decisionSignals = 0;
            foreach (['比較', '選び方', '向いて', '注意', 'デメリット', '費用', '料金', '代替'] as $signal) {
                if (str_contains($text, $signal)) {
                    $decisionSignals++;
                }
            }
            if ($decisionSignals < 2) {
                $errors[] = 'CV記事に比較・適合判断・注意点などの意思決定材料が不足しています。';
            }
        } elseif (self::length(trim((string) ($funnel['conversion_bridge'] ?? ''))) < 8) {
            $warnings[] = '集客記事からCV記事への橋渡し理由が弱い可能性があります。';
        }

        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));
        return [
            'passed' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'metrics' => [
                'text_characters' => $length,
                'minimum_characters' => $minimum,
                'h2_count' => $h2Count,
                'paragraph_count' => $paragraphCount,
                'table_count' => $tableCount,
                'list_count' => $listCount,
                'long_paragraph_count' => $longParagraphCount,
                'verified_source_count' => count($sourceIndexes),
                'internal_link_count' => count(is_array($article['internal_link_post_ids'] ?? null) ? $article['internal_link_post_ids'] : []),
            ],
        ];
    }

    public static function decision(array $payload, array $settings): array
    {
        $audit = is_array($payload['audit'] ?? null) ? $payload['audit'] : [];
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $diagnostics = is_array($payload['quality_diagnostics'] ?? null) ? $payload['quality_diagnostics'] : self::diagnostics($payload);
        $ymyl = !empty($audit['ymyl']) || !empty($article['ymyl']);
        $componentNames = ['intent_coverage', 'factual_support', 'clarity', 'originality', 'seo_quality', 'information_gain', 'conversion_quality', 'reader_trust', 'internal_link_quality'];
        $componentScores = [];
        foreach ($componentNames as $name) {
            $componentScores[] = max(0, min(100, (int) ($audit[$name] ?? 0)));
        }
        $calculatedScore = $componentScores === [] ? 0 : (int) round(array_sum($componentScores) / count($componentScores));
        $reportedScore = max(0, min(100, (int) ($audit['overall_score'] ?? 0)));
        $score = min($reportedScore, $calculatedScore);
        $critical = is_array($audit['critical_issues'] ?? null) ? $audit['critical_issues'] : [];
        $unsupported = is_array($audit['unsupported_claims'] ?? null) ? $audit['unsupported_claims'] : [];
        $status = (string) ($settings['post_status'] ?? 'publish');
        $profile = Settings::qualityProfile((string) ($settings['article_quality'] ?? 'high'));
        $minimumScore = (int) ($profile['audit_score'] ?? 85);
        $reasons = [];
        $warnings = [];
        $blockers = [];

        if ($ymyl) {
            $blockers[] = '医療・健康・法律・金融など重要判断に関わる記事のため、安全確認が必要です。';
        }
        if ($score < $minimumScore) {
            $warnings[] = '品質スコアが基準未満です: ' . $score . '/' . $minimumScore;
        }
        if ($critical !== []) {
            $warnings[] = '重大な監査指摘: ' . count($critical) . '件';
        }
        if ($unsupported !== []) {
            $message = '根拠を確認できない主張が残っています: ' . count($unsupported) . '件';
            if ($status === 'publish' && !$ymyl) {
                $warnings[] = $message;
            } else {
                $blockers[] = $message;
            }
        }
        if (empty($diagnostics['passed'])) {
            $errors = is_array($diagnostics['errors'] ?? null) ? $diagnostics['errors'] : [];
            $message = $errors !== [] ? 'Deterministic checks failed: ' . implode(' / ', array_slice(array_map('strval', $errors), 0, 3)) : 'Deterministic checks failed';
            if (self::hasPublishBlocker($errors)) {
                $blockers[] = $message;
            } else {
                $warnings[] = $message;
            }
        }

        if ($blockers !== []) {
            $status = 'draft';
        }
        if (!in_array($status, ['draft', 'pending', 'publish'], true)) {
            $status = 'draft';
            $blockers[] = '投稿状態の設定が不正です。';
        }
        $reasons = $status === 'draft' ? array_values(array_merge($blockers, $warnings)) : [];
        if ($status === 'draft' && $reasons === []) {
            $reasons[] = '設定の投稿状態が「下書き」になっています。';
        }
        return [
            'post_status' => $status,
            'score' => $score,
            'reported_score' => $reportedScore,
            'component_score' => $calculatedScore,
            'minimum_score' => $minimumScore,
            'ymyl' => $ymyl,
            'critical_count' => count($critical),
            'unsupported_count' => count($unsupported),
            'deterministic_errors' => count(is_array($diagnostics['errors'] ?? null) ? $diagnostics['errors'] : []),
            'test_mode' => !empty($payload['test_mode']),
            'draft_reasons' => $reasons,
            'publish_warnings' => $warnings,
            'publish_blockers' => $blockers,
        ];
    }

    public static function needsRevision(array $payload, array $settings): bool
    {
        $decision = self::decision($payload, $settings);
        return empty($decision['ymyl']) && (
            (int) $decision['score'] < (int) $decision['minimum_score']
            || (int) $decision['critical_count'] > 0
            || (int) $decision['unsupported_count'] > 0
            || (int) $decision['deterministic_errors'] > 0
        );
    }

    public static function hardChecksRefresh(array $article): string
    {
        $html = (string) ($article['content_html'] ?? '');
        $text = trim(wp_strip_all_tags($html));
        if ($html === '') {
            return 'Refresh article content is empty.';
        }
        if (preg_match('/<\s*h1\b/i', $html)) {
            return 'Refresh article must not contain H1.';
        }
        if (preg_match('/<\s*(script|iframe|form)\b|on\w+\s*=/i', $html)) {
            return 'Refresh article contains disallowed HTML.';
        }
        if (preg_match('/(判断材料の整理|根拠の量|構成の深さ|内部導線|品質判定|監査スコア|AI監査)/u', $text)) {
            return '改善記事に読者へ表示してはいけない内部管理文言が含まれます。';
        }
        if (self::length($text) < 1000) {
            return 'Refresh article is unexpectedly short.';
        }
        if (self::length(trim((string) ($article['cta_lead'] ?? ''))) < 10 || self::length(trim((string) ($article['cta_anchor'] ?? ''))) < 3) {
            return 'Refresh CTA copy is missing.';
        }
        return '';
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function hasPublishBlocker(array $errors): bool
    {
        foreach (array_map('strval', $errors) as $error) {
            if (preg_match('/empty|H1|disallowed HTML|out-of-range source|placeholder|unfinished|内部管理文言/i', $error)) {
                return true;
            }
        }
        return false;
    }
}
