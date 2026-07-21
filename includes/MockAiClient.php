<?php

declare(strict_types=1);

namespace DSAP;

final class MockAiClient implements AiClientInterface
{
    public function respond(string $schemaName, array $schema, string $prompt, bool $webSearch = false, string $model = '', bool $background = false, string $responseId = ''): array|\WP_Error
    {
        unset($schema, $webSearch, $model, $background, $responseId);

        if ($schemaName === 'strategy_v1') {
            $articles = [];
            for ($i = 1; $i <= 18; $i++) {
                $clusterNumber = (int) ceil($i / 6);
                $position = (($i - 1) % 6) + 1;
                $isCv = $position === 6;
                $cvKeyword = "業務改善ツール 比較 選び方 {$clusterNumber}";
                $articles[] = [
                    'keyword' => $isCv ? $cvKeyword : "業務改善 ツール 導入 失敗 対策 {$clusterNumber}-{$position}",
                    'article_type' => $isCv ? 'cv' : 'attraction',
                    'cluster_name' => "導入判断クラスター{$clusterNumber}",
                    'search_intent' => $isCv ? 'commercial' : 'informational',
                    'content_role' => $isCv ? 'cv' : ($position % 2 === 0 ? 'howto' : 'problem'),
                    'reader_stage' => $isCv ? 'product_aware' : 'problem_aware',
                    'entry_angle' => '導入後に起きやすい失敗から逆算して、選定前に確認すべき条件を具体化する。',
                    'hidden_pain' => '比較表だけでは自社に適合するか判断できず、導入後の定着失敗を不安に感じている。',
                    'content_promise' => '失敗条件と確認手順を使い、候補を自分で絞り込める状態にする。',
                    'conversion_bridge' => '失敗条件を整理した読者が、同じ基準で候補を比較できるCV記事へ進む。',
                    'objection' => '比較記事は広告ばかりで信用できないという不安を、非適合条件と注意点の開示で解消する。',
                    'brief' => $isCv ? '比較基準、向く人・向かない人、費用、注意点を公平に示し、条件が合う読者だけを承認済みリンクへ案内する。' : '導入失敗の具体的な状況、原因、対処、判断基準を示し、同じ基準を使う比較記事へ自然に案内する。',
                    'target_keyword' => $cvKeyword,
                    'target_url' => $isCv ? (string) Settings::get()['affiliate_url'] : '',
                    'anchor_text' => $isCv ? (string) Settings::get()['affiliate_anchor'] : '失敗を避ける比較基準を確認する',
                    'priority' => 101 - $i,
                ];
            }
            return ['data' => [
                'strategy_summary' => '検索意図別に集客記事とCV記事を配置するサンプル戦略です。',
                'funnel_summary' => '集客記事から比較・検討記事を経由し、承認済みのアフィリエイト先へ誘導します。',
                'offer_analysis' => '業務改善を検討する読者に対して、導入失敗を避ける判断材料と比較手順を提供する商材として設計します。',
                'ideal_customer_profile' => '候補は見つけたものの、自社の業務条件、運用負荷、総費用に適合するか判断できずにいる実務担当者です。',
                'positioning' => 'おすすめ順位ではなく、非適合条件と失敗回避の基準を先に示すことで、比較への信頼を作ります。',
                'conversion_hypotheses' => ['失敗条件を理解した読者は比較記事へ進みやすい', '向かない条件の開示が広告への不信を下げる', '同じ判断表で比較すると公式情報の確認行動につながる'],
                'content_gap_opportunities' => ['導入後に定着しない原因を扱う', '総費用に含まれる隠れた作業を扱う', '向かない利用条件を具体的に扱う'],
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
                'reader_situation' => '導入候補はあるが、自社に合うかを判断する具体的な基準がなく迷っている。',
                'jobs_to_be_done' => ['失敗条件を事前に確認する', '候補を同じ基準で比較する'],
                'decision_criteria' => ['目的への適合', '総費用', '運用負荷', 'サポート', '解約条件'],
                'competitor_content_gaps' => ['向かない人の説明が弱い', '導入後の失敗パターンが抽象的'],
                'original_value_plan' => ['判断チェックリストを示す', '失敗原因と対策を対応表にする'],
                'conversion_bridge' => '判断基準を理解した読者を、同じ基準で候補を比較する記事へ案内する。',
                'objections' => ['広告的な比較への不信', '導入後に使われなくなる不安'],
                'freshness_as_of' => gmdate('Y-m-d'),
                'title_candidates' => [$keyword . 'の基本', $keyword . 'の選び方', $keyword . 'の注意点', $keyword . 'の始め方', $keyword . 'の比較方法'],
                'outline' => [
                    ['heading' => $keyword . 'とは', 'purpose' => '定義を説明する', 'key_points' => ['概要', '対象読者']],
                    ['heading' => '選び方', 'purpose' => '判断基準を示す', 'key_points' => ['比較軸', '注意点']],
                    ['heading' => '始め方', 'purpose' => '次の行動を示す', 'key_points' => ['手順', '確認事項']],
                ],
                'facts' => [
                    ['claim' => '一次情報を確認することが重要です。', 'source_indexes' => [0], 'confidence' => 'high'],
                    ['claim' => '目的に合う基準で比較します。', 'source_indexes' => [1], 'confidence' => 'high'],
                    ['claim' => '利用条件は公式規約で確認します。', 'source_indexes' => [2], 'confidence' => 'high'],
                    ['claim' => '導入費用と運用費用を分けて確認します。', 'source_indexes' => [0, 1], 'confidence' => 'medium'],
                    ['claim' => 'サポート範囲は提供元ごとに異なります。', 'source_indexes' => [1], 'confidence' => 'medium'],
                    ['claim' => '解約条件は申込前の判断材料です。', 'source_indexes' => [2], 'confidence' => 'medium'],
                    ['claim' => '利用者と管理者では必要な権限が異なります。', 'source_indexes' => [0], 'confidence' => 'medium'],
                    ['claim' => '試用時は通常業務に近い手順を使います。', 'source_indexes' => [1], 'confidence' => 'medium'],
                    ['claim' => 'データ出力条件は乗り換え判断に影響します。', 'source_indexes' => [2], 'confidence' => 'medium'],
                    ['claim' => '追加費用の発生条件は提供元の案内を優先します。', 'source_indexes' => [0, 2], 'confidence' => 'medium'],
                ],
                'sources' => [
                    ['title' => 'Example Source 1', 'url' => 'https://example.com/source-1', 'publisher' => 'Example', 'published_at' => '', 'accessed_at' => gmdate('Y-m-d')],
                    ['title' => 'Example Source 2', 'url' => 'https://example.com/source-2', 'publisher' => 'Example', 'published_at' => '', 'accessed_at' => gmdate('Y-m-d')],
                    ['title' => 'Example Source 3', 'url' => 'https://example.com/source-3', 'publisher' => 'Example', 'published_at' => '', 'accessed_at' => gmdate('Y-m-d')],
                ],
                'related_keywords' => [$keyword . ' 比較', $keyword . ' 方法', $keyword . ' 注意点'],
                'entities' => [$keyword],
                'risks' => ['モック記事のため公開前確認が必要です。'],
                'topic_viability' => true,
                'viability_reason' => '一次情報と比較判断に必要な論点を確認できるため、記事化できます。',
                'demand_evidence' => [
                    ['signal' => '公式情報と比較情報を確認して導入判断を行う需要があります。', 'source_indexes' => [0, 1]],
                ],
                'ymyl' => false,
            ], 'sources' => ['https://example.com/source-1', 'https://example.com/source-2', 'https://example.com/source-3']];
        }

        if ($schemaName === 'article_v1') {
            $keyword = $this->keyword($prompt);
            $sections = [
                '結論と対象者' => '最初に目的、利用者、現在の作業負荷を整理すると、候補を機能数だけで選ぶ失敗を避けられます。判断では導入前の費用だけでなく、設定、教育、移行、保守に必要な時間も含めます。条件が合わない場合は導入を急がず、現行手順の整理を先に行う選択も有効です。',
                '失敗しやすい原因' => 'よくある失敗は、解決したい業務が曖昧なまま製品比較を始めることです。担当者だけで決めると、実際の利用者が必要とする操作や権限が抜けます。無料期間では通常業務に近いデータと手順を使い、例外処理まで確認します。',
                '比較と選び方' => '比較では目的への適合、総費用、運用負荷、連携、サポート、解約条件を同じ表に並べます。おすすめという評価だけで決めず、向いている条件と向いていない条件を分けます。料金は公式情報を確認し、追加費用の発生条件も確認します。',
                '導入手順' => '最初に対象業務と成功条件を一つに絞り、小さな範囲で試します。次に担当者、期限、確認指標を決めます。試用後は操作時間、エラー、問い合わせ、継続利用の状況を記録し、導入前と比較します。結果が悪ければ設定変更か候補の見直しを行います。',
                '注意点とデメリット' => '高機能な製品ほど設定と教育の負担が増える場合があります。既存データの形式、権限管理、バックアップ、解約後のデータ出力を確認してください。重要な判断では担当部門だけでなく、情報管理や経理の確認も必要です。',
                '次に行うこと' => '候補を二つか三つに絞り、同じ業務シナリオで試すと違いが見えます。判断表には必須条件、妥協できる条件、導入を見送る条件を記入します。比較記事へ進む前にこの条件を用意すれば、広告表現に流されずに判断できます。',
            ];
            $content = '<p>' . esc_html($keyword) . 'で迷っている場合は、機能一覧より先に目的と失敗条件を決めることが重要です。この記事では比較、選び方、費用、注意点を具体的な手順に落とし込みます。</p>';
            foreach ($sections as $heading => $body) {
                $content .= '<h2>' . esc_html($heading) . '</h2>';
                $content .= '<p>' . esc_html($body) . '</p>';
                $content .= '<p>' . esc_html($heading . 'では、公式情報と実際の利用条件を分けて記録し、候補間で同じ観点を使うことが重要です。確認できない点は推測で埋めず、' . $heading . 'の問い合わせ項目として残します。') . '</p>';
                $content .= '<p>' . esc_html($heading . 'の判断結果には、採用する条件だけでなく見送る条件も記載します。' . $heading . 'の結論が目的と合わない場合は、機能の多さではなく別の候補を検討します。') . '</p>';
            }
            $content .= '<h2>実行前のチェックリスト</h2><ol><li>目的と成功条件を一つに絞る</li><li>総費用と運用負荷を同じ条件で比べる</li><li>見送る条件を先に決める</li></ol>';
            $content .= '<table><thead><tr><th>判断項目</th><th>確認する内容</th></tr></thead><tbody><tr><td>適合性</td><td>対象業務と必須条件を満たすか</td></tr><tr><td>費用</td><td>導入、運用、解約までの総額</td></tr><tr><td>運用</td><td>教育、保守、例外対応の負担</td></tr></tbody></table>';
            return ['data' => [
                'title' => $keyword . 'の基本と選び方',
                'slug' => 'daily-seo-ai-sample-' . substr(md5($keyword), 0, 8),
                'excerpt' => $keyword . 'の基礎、選び方、始め方を整理します。',
                'meta_description' => $keyword . 'の基本と比較基準、注意点を初心者向けに解説します。',
                'focus_keyword' => $keyword,
                'related_keywords' => [$keyword . ' 比較', $keyword . ' 方法', $keyword . ' 注意点'],
                'category_name' => 'SEO記事',
                'tags' => ['SEO', 'AI', $keyword],
                'content_html' => $content,
                'answer_summary' => '目的、失敗条件、総費用を先に定義し、同じ業務シナリオで候補を比較します。',
                'cta_lead' => '判断条件が整理できたら、同じ基準で候補を比較して自社に合う選択肢を絞り込みましょう。',
                'cta_anchor' => '失敗を避ける比較基準を見る',
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
                'content_html' => '<p>検索データを確認し、変更範囲を限定して改善します。</p><h2>確認する指標</h2>' . str_repeat('<p>表示回数、CTR、順位、クリック数を同じ期間で比較し、変化した要因を切り分けます。数値だけで判断せず、流入クエリと記事内容の一致も確認します。</p>', 8) . '<h2>改善の進め方</h2>' . str_repeat('<p>変更する箇所と期待する指標を一つずつ対応させます。変更後も同じ期間で効果を確認し、改善しない場合は検索意図と導線を再診断します。</p>', 8),
                'cta_lead' => '改善内容を確認したら、次の比較手順へ進みます。',
                'cta_anchor' => '比較手順を確認する',
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
                'information_gain' => 92,
                'conversion_quality' => 90,
                'reader_trust' => 92,
                'internal_link_quality' => 90,
                'product_specificity' => 88,
                'intent_plausibility' => 90,
                'non_redundancy' => 86,
                'generic_or_invented_frameworks' => [],
                'unsupported_claims' => [],
                'critical_issues' => [],
                'revision_instructions' => [],
                'ymyl' => false,
                'overall_score' => 90,
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
