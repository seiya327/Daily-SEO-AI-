<?php

declare(strict_types=1);

namespace DSAP;

final class StrategyGate
{
    public static function inspect(array $plan, array $settings, array $siteContext = []): array
    {
        $articles = is_array($plan['articles'] ?? null) ? $plan['articles'] : [];
        $errors = [];
        $warnings = [];
        $seen = [];
        $cvKeywords = [];
        $clusters = [];
        $longTailCount = 0;
        $attractionCount = 0;
        $cvCount = 0;

        if (count($articles) < 18) {
            $errors[] = '記事計画は18件以上必要です。';
        }
        foreach (['offer_analysis', 'ideal_customer_profile', 'positioning'] as $field) {
            if (self::length(trim((string) ($plan[$field] ?? ''))) < 20) {
                $errors[] = "{$field}が具体性不足です。";
            }
        }
        foreach (['conversion_hypotheses', 'content_gap_opportunities'] as $field) {
            if (!is_array($plan[$field] ?? null) || count($plan[$field]) < 3) {
                $errors[] = "{$field}は3件以上必要です。";
            }
        }

        foreach ($articles as $index => $article) {
            if (!is_array($article)) {
                $errors[] = '記事計画に不正な行があります。';
                continue;
            }
            $keyword = trim((string) ($article['keyword'] ?? ''));
            $normalized = self::normalize($keyword);
            $type = ($article['article_type'] ?? '') === 'cv' ? 'cv' : 'attraction';
            $cluster = trim((string) ($article['cluster_name'] ?? ''));
            $label = '記事' . ((int) $index + 1);
            if ($normalized === '') {
                $errors[] = "{$label}のキーワードが空です。";
                continue;
            }
            if (isset($seen[$normalized])) {
                $errors[] = "キーワード「{$keyword}」が重複しています。";
            }
            $seen[$normalized] = true;
            if ($cluster === '') {
                $errors[] = "{$label}のクラスターが空です。";
            }
            $clusters[$cluster][$type] = ($clusters[$cluster][$type] ?? 0) + 1;
            if ($type === 'cv') {
                $cvCount++;
                $cvKeywords[$normalized] = true;
            } else {
                $attractionCount++;
                if (self::isLongTail($keyword)) {
                    $longTailCount++;
                }
            }
            foreach (['entry_angle', 'hidden_pain', 'content_promise', 'conversion_bridge', 'objection'] as $field) {
                if (self::length(trim((string) ($article[$field] ?? ''))) < 8) {
                    $errors[] = "{$label}の{$field}が具体性不足です。";
                }
            }
        }

        foreach ($articles as $article) {
            if (!is_array($article) || ($article['article_type'] ?? '') === 'cv') {
                continue;
            }
            $target = self::normalize((string) ($article['target_keyword'] ?? ''));
            if ($target === '' || !isset($cvKeywords[$target])) {
                $errors[] = '集客記事「' . (string) ($article['keyword'] ?? '') . '」の誘導先CV記事が計画内にありません。';
            }
        }

        foreach ($clusters as $cluster => $counts) {
            if ($cluster === '') {
                continue;
            }
            if (empty($counts['cv'])) {
                $errors[] = "クラスター「{$cluster}」にCV記事がありません。";
            }
            if (($counts['attraction'] ?? 0) < 2) {
                $warnings[] = "クラスター「{$cluster}」の集客記事が2件未満です。";
            }
        }

        if ($cvCount < 2) {
            $errors[] = 'CV記事は最低2件必要です。';
        }
        if ($attractionCount < 10) {
            $errors[] = '集客記事は最低10件必要です。';
        }

        $strategy = (string) ($settings['keyword_strategy'] ?? 'longtail');
        $requiredRatio = ['balanced' => 0.55, 'longtail' => 0.70, 'unexpected' => 0.75][$strategy] ?? 0.70;
        $longTailRatio = $attractionCount > 0 ? $longTailCount / $attractionCount : 0.0;
        if ($longTailRatio < $requiredRatio) {
            $errors[] = 'ロングテール比率が不足しています。現在' . (string) round($longTailRatio * 100) . '%、必要' . (string) round($requiredRatio * 100) . '%以上です。';
        }

        $existing = [];
        foreach (($siteContext['existing_content'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (['focus_keyword', 'title'] as $field) {
                $value = self::normalize((string) ($item[$field] ?? ''));
                if ($value !== '') {
                    $existing[$value] = true;
                }
            }
        }
        foreach ($articles as $article) {
            $keyword = self::normalize((string) ($article['keyword'] ?? ''));
            if ($keyword !== '' && isset($existing[$keyword])) {
                $errors[] = '既存記事と重複するキーワードがあります: ' . (string) ($article['keyword'] ?? '');
            }
        }

        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));
        $score = max(0, 100 - count($errors) * 12 - count($warnings) * 3);
        return [
            'passed' => $errors === [],
            'score' => $score,
            'errors' => $errors,
            'warnings' => $warnings,
            'metrics' => [
                'articles' => count($articles),
                'attraction_articles' => $attractionCount,
                'cv_articles' => $cvCount,
                'clusters' => count(array_filter(array_keys($clusters))),
                'long_tail_ratio' => round($longTailRatio, 3),
            ],
        ];
    }

    private static function isLongTail(string $keyword): bool
    {
        $keyword = trim($keyword);
        $parts = preg_split('/[\s　]+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $modifiers = ['比較', '違い', '費用', '料金', '失敗', '後悔', '代替', 'できない', '向いて', '選び方', '注意', 'デメリット', 'メリット', '乗り換え', '初心者', '個人', '法人', '小規模', '無料', '有料', 'いつ', '方法', '手順', '原因', '対処'];
        $hasModifier = false;
        foreach ($modifiers as $modifier) {
            if (str_contains($keyword, $modifier)) {
                $hasModifier = true;
                break;
            }
        }
        return self::length(self::normalize($keyword)) >= 9 && (count($parts) >= 2 || $hasModifier);
    }

    private static function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?: '';
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
