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
        $minimum = (int) round((int) ($profile['min_words'] ?? 1800) * 0.82);
        $maximum = (int) ($profile['max_words'] ?? 4500);
        $quality = (string) Settings::get()['article_quality'];
        $minimumH2 = ['standard' => 3, 'high' => 4, 'premium' => 5][$quality] ?? 4;
        $maximumH2 = ['standard' => 7, 'high' => 9, 'premium' => 12][$quality] ?? 9;
        preg_match_all('/<h2\b[^>]*>/i', $html, $h2Matches);
        preg_match_all('/<p\b[^>]*>.*?<\/p>/is', $html, $paragraphMatches);
        preg_match_all('/<table\b[^>]*>/i', $html, $tableMatches);
        preg_match_all('/<(?:ul|ol)\b[^>]*>/i', $html, $listMatches);
        $h2Count = count($h2Matches[0] ?? []);
        $paragraphCount = count($paragraphMatches[0] ?? []);
        $tableCount = count($tableMatches[0] ?? []);
        $listCount = count($listMatches[0] ?? []);
        $facts = is_array($research['facts'] ?? null) ? $research['facts'] : [];
        $highConfidenceFacts = count(array_filter($facts, static fn ($fact): bool => is_array($fact) && ($fact['confidence'] ?? '') === 'high'));
        $factText = implode(' ', array_map(static fn ($fact): string => is_array($fact) ? (string) ($fact['claim'] ?? '') : '', $facts));
        $unsupportedDurations = self::unsupportedRepeatedDurations($text, $factText);
        $duplicateSentenceCount = self::duplicateSentenceCount($text);
        $productTerms = self::productTerms($research, $article);
        $productAware = $productTerms !== [] && (
            ($funnel['article_type'] ?? '') === 'cv'
            || ($funnel['reader_stage'] ?? '') === 'product_aware'
        );
        $productFactCount = count(array_filter($facts, static function ($fact) use ($productTerms): bool {
            return is_array($fact) && self::containsAnyTerm((string) ($fact['claim'] ?? ''), $productTerms);
        }));
        $sectionSpecificity = self::sectionSpecificity($html, $productTerms);
        $longParagraphCount = 0;
        $adviceParagraphCount = 0;
        $paragraphTexts = [];
        foreach (($paragraphMatches[0] ?? []) as $paragraph) {
            $paragraphText = trim(wp_strip_all_tags((string) $paragraph));
            if ($paragraphText !== '') {
                $paragraphTexts[] = $paragraphText;
            }
            if (self::length($paragraphText) > 450) {
                $longParagraphCount++;
            }
            if (preg_match('/(してください|しましょう|確認する|確認します|試す|試します|決める|決めます|記録する|記録します|確保する|確保します|避けてください)/u', $paragraphText)) {
                $adviceParagraphCount++;
            }
        }
        $adviceParagraphRatio = $paragraphCount > 0 ? $adviceParagraphCount / $paragraphCount : 0.0;
        $nearDuplicateParagraphPairs = self::nearDuplicateParagraphPairs($paragraphTexts);

        if ($html === '') {
            $errors[] = 'Article content is empty.';
        }
        if (preg_match('/<\s*h1\b/i', $html)) {
            $errors[] = 'Article content must not contain H1.';
        }
        if (preg_match('/<\s*(script|iframe|form)\b|<[^>]+\son\w+\s*=/i', $html)) {
            $errors[] = 'Article content contains disallowed HTML.';
        }
        if ($length < $minimum) {
            $errors[] = "本文が品質基準より短すぎます（{$length}文字、最低目安{$minimum}文字）。";
        }
        if ($length > $maximum) {
            $errors[] = "検索意図に対して本文が長すぎます（{$length}文字、上限目安{$maximum}文字）。重複と一般論を削ってください。";
        }
        if ($h2Count < $minimumH2) {
            $errors[] = "主要セクションが不足しています（H2 {$h2Count}件、最低{$minimumH2}件）。";
        }
        if ($h2Count > $maximumH2) {
            $errors[] = "見出しが細分化されすぎています（H2 {$h2Count}件、上限{$maximumH2}件）。同じ結論の節を統合してください。";
        }
        if ($paragraphCount < 8) {
            $warnings[] = '本文の段落数が少なく、説明が粗い可能性があります。';
        }
        if ($tableCount < 1) {
            if (($funnel['article_type'] ?? 'attraction') === 'cv') {
                $errors[] = 'CV記事に比較・判断へ使えるHTML表がありません。';
            } else {
                $warnings[] = '表がありません。比較情報がある場合だけ追加してください。';
            }
        }
        if ($listCount < 1) {
            $warnings[] = '箇条書きがありません。手順や確認項目がある場合だけ追加してください。';
        }
        if (self::length(trim((string) ($article['answer_summary'] ?? ''))) < 30) {
            $errors[] = '読者向けの要点要約が不足しています。';
        }
        if ($longParagraphCount > 2) {
            $warnings[] = "450文字を超える長い段落が{$longParagraphCount}件あります。段落を分割してください。";
        }
        if ($duplicateSentenceCount > 2) {
            $errors[] = "同じ文の繰り返しが{$duplicateSentenceCount}件あります。重複を削除してください。";
        }
        if ($nearDuplicateParagraphPairs > 2) {
            $errors[] = "見出し名だけを変えた近似段落が{$nearDuplicateParagraphPairs}組あります。テンプレートの反復を削除してください。";
        }
        if ($unsupportedDurations !== []) {
            $errors[] = '調査根拠のない時間・回数フレームが本文の中心になっています: ' . implode('、', $unsupportedDurations);
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
        $ctaAnchorNormalized = self::normalizeText((string) ($article['cta_anchor'] ?? ''));
        $focusKeywordNormalized = self::normalizeText((string) ($article['focus_keyword'] ?? ''));
        if ($ctaAnchorNormalized !== '' && $ctaAnchorNormalized === $focusKeywordNormalized) {
            $errors[] = 'CTAのリンク文言が検索キーワードの貼り付けになっています。読者が得る内容または次の行動を示してください。';
        }
        $imageSearchQuery = trim((string) ($article['image_search_query'] ?? ''));
        $imageAlt = trim((string) ($article['image_alt'] ?? ''));
        if ($imageSearchQuery === '' || count(preg_split('/\s+/u', $imageSearchQuery, -1, PREG_SPLIT_NO_EMPTY) ?: []) < 2) {
            $errors[] = '記事内容に合う挿絵の英語検索語が不足しています。';
        }
        if (self::length($imageAlt) < 8) {
            $errors[] = '挿絵の代替テキストが不足しています。';
        }
        if ($productAware) {
            if ($productFactCount < 3) {
                $errors[] = '商品名を扱う記事なのに、商品固有の調査事実が3件未満です。一般論ではなく公式情報を追加してください。';
            }
            if ((int) $sectionSpecificity['total'] >= 3 && (float) $sectionSpecificity['ratio'] < 0.4) {
                $errors[] = '主要セクションの多くが商品名を外しても成立します。商品固有の機能・条件・制約・代替比較へ書き直してください。';
            }
        }
        if (($funnel['article_type'] ?? 'attraction') === 'cv') {
            if (count($facts) < 6 || $highConfidenceFacts < 3) {
                $errors[] = 'CV記事の商材固有事実が不足しています（事実6件以上、うち高信頼3件以上が必要）。';
            }
            if ($adviceParagraphRatio > 0.55 && count($facts) < 10) {
                $errors[] = 'CV記事が商材情報より一般的な助言に偏っています。公式事実・制約・代替比較を増やしてください。';
            }
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
                'maximum_characters' => $maximum,
                'h2_count' => $h2Count,
                'paragraph_count' => $paragraphCount,
                'table_count' => $tableCount,
                'list_count' => $listCount,
                'long_paragraph_count' => $longParagraphCount,
                'duplicate_sentence_count' => $duplicateSentenceCount,
                'near_duplicate_paragraph_pairs' => $nearDuplicateParagraphPairs,
                'unsupported_duration_count' => count($unsupportedDurations),
                'research_fact_count' => count($facts),
                'high_confidence_fact_count' => $highConfidenceFacts,
                'product_term_count' => count($productTerms),
                'product_fact_count' => $productFactCount,
                'product_specific_section_ratio' => $sectionSpecificity['ratio'],
                'advice_paragraph_ratio' => round($adviceParagraphRatio, 3),
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
        $componentNames = ['intent_coverage', 'factual_support', 'clarity', 'originality', 'seo_quality', 'information_gain', 'conversion_quality', 'reader_trust', 'internal_link_quality', 'product_specificity', 'intent_plausibility', 'non_redundancy'];
        $componentScores = [];
        foreach ($componentNames as $name) {
            $componentScores[] = max(0, min(100, (int) ($audit[$name] ?? 0)));
        }
        $calculatedScore = $componentScores === [] ? 0 : (int) round(array_sum($componentScores) / count($componentScores));
        $reportedScore = max(0, min(100, (int) ($audit['overall_score'] ?? 0)));
        $score = min($reportedScore, $calculatedScore);
        $critical = is_array($audit['critical_issues'] ?? null) ? $audit['critical_issues'] : [];
        $unsupported = is_array($audit['unsupported_claims'] ?? null) ? $audit['unsupported_claims'] : [];
        $genericFrameworks = is_array($audit['generic_or_invented_frameworks'] ?? null) ? $audit['generic_or_invented_frameworks'] : [];
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
        if ($genericFrameworks !== []) {
            $blockers[] = '一般論または根拠のないフレームが残っています: ' . count($genericFrameworks) . '件';
        }
        foreach (['product_specificity' => 65, 'intent_plausibility' => 65, 'non_redundancy' => 70] as $component => $requiredScore) {
            if (array_key_exists($component, $audit) && (int) $audit[$component] < $requiredScore) {
                $blockers[] = $component . ' が公開基準未満です: ' . (int) $audit[$component] . '/' . $requiredScore;
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
            'generic_framework_count' => count($genericFrameworks),
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
            || (int) $decision['generic_framework_count'] > 0
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
        if (preg_match('/<\s*(script|iframe|form)\b|<[^>]+\son\w+\s*=/i', $html)) {
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

    private static function unsupportedRepeatedDurations(string $text, string $factText): array
    {
        preg_match_all('/(?<![0-9])([1-9][0-9]{0,3}(?:分|時間|日|週間|か月|ヶ月|回))(?![0-9])/u', $text, $matches);
        $counts = array_count_values(array_map('strval', $matches[1] ?? []));
        $unsupported = [];
        foreach ($counts as $label => $count) {
            if ($count >= 5 && !str_contains($factText, (string) $label)) {
                $unsupported[] = (string) $label . '（' . (string) $count . '回）';
            }
        }
        return $unsupported;
    }

    private static function duplicateSentenceCount(string $text): int
    {
        $sentences = preg_split('/[。！？!?]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $seen = [];
        $duplicates = 0;
        foreach ($sentences as $sentence) {
            $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $sentence) ?: '';
            if (self::length($normalized) < 24) {
                continue;
            }
            if (isset($seen[$normalized])) {
                $duplicates++;
            }
            $seen[$normalized] = true;
        }
        return $duplicates;
    }

    private static function normalizeText(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?: '';
    }

    private static function productTerms(array $research, array $article): array
    {
        $values = array_map('strval', is_array($research['entities'] ?? null) ? $research['entities'] : []);
        $values[] = (string) ($research['primary_keyword'] ?? '');
        $values[] = (string) ($article['focus_keyword'] ?? '');
        $values[] = (string) ($article['title'] ?? '');
        $terms = [];
        $generic = ['seo', 'ai', 'api', 'web', 'online', 'service', 'tool', 'app', '料金', '比較', '評判', '口コミ', '方法', '選び方', 'オンラインスクール', 'スクール', 'サービス', 'ツール', 'アプリ'];
        foreach ($values as $value) {
            preg_match_all('/[A-Za-z0-9][A-Za-z0-9._-]{1,30}/', $value, $matches);
            foreach (($matches[0] ?? []) as $term) {
                $lower = strtolower((string) $term);
                if (in_array($lower, $generic, true) || ctype_digit(str_replace(['-', '_', '.'], '', $lower))) {
                    continue;
                }
                if (str_contains((string) $term, '-') || preg_match('/[A-Z].*[A-Z]|[A-Z].*[a-z]|[a-z].*[A-Z]/', (string) $term)) {
                    $terms[] = (string) $term;
                }
            }
            $trimmed = trim($value);
            if (!preg_match('/\s/u', $trimmed) && preg_match('/[ぁ-んァ-ヶ一-龠]/u', $trimmed) && self::length($trimmed) >= 2 && self::length($trimmed) <= 20) {
                $isGeneric = false;
                foreach ($generic as $word) {
                    if (str_contains($trimmed, $word)) {
                        $isGeneric = true;
                        break;
                    }
                }
                if (!$isGeneric) {
                    $terms[] = $trimmed;
                }
            }
        }
        return array_values(array_unique($terms));
    }

    private static function containsAnyTerm(string $text, array $terms): bool
    {
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        foreach ($terms as $term) {
            $needle = function_exists('mb_strtolower') ? mb_strtolower((string) $term, 'UTF-8') : strtolower((string) $term);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function sectionSpecificity(string $html, array $terms): array
    {
        if ($terms === []) {
            return ['specific' => 0, 'total' => 0, 'ratio' => 0.0];
        }
        preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>(.*?)(?=<h2\b|$)/is', $html, $matches, PREG_SET_ORDER);
        $specific = 0;
        foreach ($matches as $match) {
            if (self::containsAnyTerm(wp_strip_all_tags((string) ($match[1] ?? '') . ' ' . (string) ($match[2] ?? '')), $terms)) {
                $specific++;
            }
        }
        $total = count($matches);
        return ['specific' => $specific, 'total' => $total, 'ratio' => $total > 0 ? round($specific / $total, 3) : 0.0];
    }

    private static function nearDuplicateParagraphPairs(array $paragraphs): int
    {
        $normalized = [];
        foreach ($paragraphs as $paragraph) {
            $value = self::normalizeText((string) $paragraph);
            if (self::length($value) >= 45) {
                $normalized[] = $value;
            }
        }
        $pairs = 0;
        $limit = min(count($normalized), 60);
        for ($i = 0; $i < $limit; $i++) {
            for ($j = $i + 1; $j < $limit; $j++) {
                similar_text($normalized[$i], $normalized[$j], $percentage);
                if ($percentage >= 72.0) {
                    $pairs++;
                }
            }
        }
        return $pairs;
    }

    private static function hasPublishBlocker(array $errors): bool
    {
        foreach (array_map('strval', $errors) as $error) {
            if (preg_match('/empty|H1|disallowed HTML|out-of-range source|placeholder|unfinished|内部管理文言|本文が品質基準より短すぎ|本文が長すぎ|主要セクションが不足|見出しが細分化|同じ文の繰り返し|近似段落|時間・回数フレーム|CTA|CV記事|挿絵|商品固有|商品名を外しても/i', $error)) {
                return true;
            }
        }
        return false;
    }
}
