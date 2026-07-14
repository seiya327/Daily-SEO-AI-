<?php

declare(strict_types=1);

namespace DSAP;

final class MockAiClient implements AiClientInterface
{
    public function respond(string $schemaName, array $schema, string $prompt, bool $webSearch = false, string $model = ''): array|\WP_Error
    {
        unset($schema, $webSearch, $model);

        if ($schemaName === 'strategy_v1') {
            $articles = [];
            for ($i = 1; $i <= 12; $i++) {
                $isCv = $i % 4 === 0;
                $articles[] = [
                    'keyword' => $isCv ? "おすすめサービス 比較 {$i}" : "SEOテーマ 入門 {$i}",
                    'article_type' => $isCv ? 'cv' : 'attraction',
                    'cluster_name' => 'サンプルクラスター',
                    'search_intent' => $isCv ? 'commercial' : 'informational',
                    'brief' => $isCv ? '比較基準と選び方を示し、適切な読者を申込み先へ案内する。' : '疑問を解消し、関連する比較記事へ自然に案内する。',
                    'target_keyword' => 'おすすめサービス 比較 4',
                    'target_url' => $isCv ? (string) Settings::get()['affiliate_url'] : '',
                    'anchor_text' => $isCv ? (string) Settings::get()['affiliate_anchor'] : 'おすすめサービスを比較する',
                    'priority' => 101 - $i,
                ];
            }
            return ['data' => [
                'strategy_summary' => '検索意図別に集客記事とCV記事を配置するサンプル戦略です。',
                'funnel_summary' => '集客記事から比較・検討記事を経由し、承認済みのアフィリエイト先へ誘導します。',
                'articles' => $articles,
            ]];
        }

        if ($schemaName === 'research_v1') {
            $keyword = $this->keyword($prompt);
            return ['data' => [
                'primary_keyword' => $keyword,
                'search_intent' => 'informational',
                'audience_need' => '基礎と判断基準を短時間で理解したい。',
                'angle' => '初心者向けに比較基準と実行手順を整理する。',
                'freshness_as_of' => gmdate('Y-m-d'),
                'title_candidates' => [$keyword . 'の基本', $keyword . 'の選び方', $keyword . 'の注意点', $keyword . 'の始め方', $keyword . 'の比較方法'],
                'outline' => [
                    ['heading' => $keyword . 'とは', 'purpose' => '定義を説明する', 'key_points' => ['概要', '対象読者']],
                    ['heading' => '選び方', 'purpose' => '判断基準を示す', 'key_points' => ['比較軸', '注意点']],
                    ['heading' => '始め方', 'purpose' => '次の行動を示す', 'key_points' => ['手順', '確認事項']],
                ],
                'facts' => [
                    ['claim' => '一次情報を確認することが重要です。', 'source_indexes' => [0], 'confidence' => 'high'],
                    ['claim' => '目的に合う基準で比較します。', 'source_indexes' => [1], 'confidence' => 'medium'],
                    ['claim' => '公開後も定期的に更新します。', 'source_indexes' => [2], 'confidence' => 'medium'],
                ],
                'sources' => [
                    ['title' => 'Example Source 1', 'url' => 'https://example.com/source-1', 'publisher' => 'Example', 'published_at' => '', 'accessed_at' => gmdate('Y-m-d')],
                    ['title' => 'Example Source 2', 'url' => 'https://example.com/source-2', 'publisher' => 'Example', 'published_at' => '', 'accessed_at' => gmdate('Y-m-d')],
                    ['title' => 'Example Source 3', 'url' => 'https://example.com/source-3', 'publisher' => 'Example', 'published_at' => '', 'accessed_at' => gmdate('Y-m-d')],
                ],
                'related_keywords' => [$keyword . ' 比較', $keyword . ' 方法', $keyword . ' 注意点'],
                'entities' => [$keyword],
                'risks' => ['モック記事のため公開前確認が必要です。'],
                'ymyl' => false,
            ]];
        }

        if ($schemaName === 'article_v1') {
            $keyword = $this->keyword($prompt);
            return ['data' => [
                'title' => $keyword . 'の基本と選び方',
                'slug' => 'daily-seo-ai-sample-' . substr(md5($keyword), 0, 8),
                'excerpt' => $keyword . 'の基礎、選び方、始め方を整理します。',
                'meta_description' => $keyword . 'の基本と比較基準、注意点を初心者向けに解説します。',
                'focus_keyword' => $keyword,
                'related_keywords' => [$keyword . ' 比較', $keyword . ' 方法', $keyword . ' 注意点'],
                'category_name' => 'SEO記事',
                'tags' => ['SEO', 'AI', $keyword],
                'content_html' => '<p>' . esc_html($keyword) . 'について、目的と判断基準を先に決めることが大切です。</p><h2>' . esc_html($keyword) . 'とは</h2><p>基本事項を整理します。</p><h2>選び方</h2><p>一次情報、費用、使いやすさを比較します。</p><h2>始め方</h2><p>小さく試して結果を確認します。</p>',
                'source_indexes' => [0, 1, 2],
                'internal_link_post_ids' => [],
                'ymyl' => false,
            ]];
        }

        if ($schemaName === 'refresh_plan_v1') {
            return ['data' => [
                'should_refresh' => true,
                'primary_goal' => 'improve_ctr',
                'diagnosis_summary' => '表示回数に対してCTRを改善できる余地があります。',
                'target_queries' => ['SEO 記事 改善'],
                'retained_elements' => ['既存の正確な説明', '承認済みCTA'],
                'changes' => [[
                    'section_key' => 'title-and-introduction',
                    'action' => 'update',
                    'reason' => '検索意図を明確にするため',
                    'metric_evidence' => '表示回数に対してCTRが低い',
                    'source_indexes' => [],
                    'expected_metric' => 'ctr',
                ]],
                'risks' => ['既存順位を落とさないよう変更範囲を限定する'],
                'requires_web_research' => false,
                'ymyl' => false,
            ]];
        }

        if ($schemaName === 'refresh_article_v1') {
            return ['data' => [
                'title' => '【改善版】SEO記事の基本と選び方',
                'excerpt' => '検索意図に合わせて改善したモック記事です。',
                'meta_description' => 'SEO記事の改善方法と確認すべき指標を分かりやすく解説します。',
                'focus_keyword' => 'SEO 記事 改善',
                'related_keywords' => ['SEO リライト', 'CTR 改善', '検索順位 改善'],
                'content_html' => '<p>検索データを確認し、変更範囲を限定して改善します。</p><h2>確認する指標</h2><p>表示回数、CTR、順位、クリック数を比較します。</p><h2>改善の進め方</h2><p>変更後も同じ期間で効果を確認します。</p>',
                'source_indexes' => [],
                'sources' => [],
                'internal_link_post_ids' => [],
                'change_summary' => ['タイトルと導入を検索意図に合わせた'],
                'preserved_facts' => ['既存の正確な説明を維持した'],
                'material_change' => true,
                'ymyl' => false,
            ]];
        }

        if ($schemaName === 'audit_v1') {
            return ['data' => [
                'intent_coverage' => 82,
                'factual_support' => 80,
                'clarity' => 84,
                'originality' => 80,
                'seo_quality' => 82,
                'unsupported_claims' => [],
                'critical_issues' => [],
                'ymyl' => false,
                'overall_score' => 82,
            ]];
        }

        return new \WP_Error('dsap_mock_unknown_schema', 'Unknown mock schema.');
    }

    private function keyword(string $prompt): string
    {
        if (preg_match('/Keyword:\s*(.+)/u', $prompt, $matches)) {
            return trim($matches[1]);
        }
        return 'SEO記事';
    }
}
