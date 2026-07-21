<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/includes/AiClientInterface.php';
require dirname(__DIR__) . '/includes/Settings.php';
require dirname(__DIR__) . '/includes/MockAiClient.php';
require dirname(__DIR__) . '/includes/QualityGate.php';
require dirname(__DIR__) . '/includes/ArticleVisuals.php';
require dirname(__DIR__) . '/includes/ArticleImageGenerator.php';

use DSAP\ArticleImageGenerator;
use DSAP\ArticleVisuals;
use DSAP\MockAiClient;
use DSAP\QualityGate;
use DSAP\Settings;

$GLOBALS['dsap_test_options'][Settings::OPTION] = array_merge(Settings::defaults(), [
    'article_quality' => 'high',
    'post_status' => 'publish',
    'affiliate_url' => 'https://example.com/offer',
    'affiliate_anchor' => '公式情報と申込条件を確認する',
    'mock_mode' => true,
]);

// The built-in mock intentionally resembles the repetitive article that reached the site.
// It is a regression canary: a high AI score must never make this output publishable.
$keyword = 'UR-U オンラインスクール 料金 比較';
$client = new MockAiClient();
$researchResult = $client->respond('research_v1', [], "Keyword: {$keyword}", true);
$articleResult = $client->respond('article_v1', [], "Keyword: {$keyword}");
if (is_wp_error($researchResult) || is_wp_error($articleResult)) {
    throw new RuntimeException('Mock article generation failed.');
}
$badPayload = [
    'research' => $researchResult['data'],
    'funnel' => [
        'article_type' => 'cv',
        'cluster_name' => 'UR-U検討',
        'content_role' => 'cv',
        'reader_stage' => 'product_aware',
        'target_keyword' => $keyword,
        'entry_angle' => '料金と契約条件を公式情報で確認する。',
        'conversion_bridge' => '条件が合う読者だけを公式情報へ案内する。',
        'target_url' => 'https://example.com/offer',
        'anchor_text' => '公式情報と申込条件を確認する',
    ],
    'internal_link_candidates' => [],
    'article' => $articleResult['data'],
];
$badPayload['quality_diagnostics'] = QualityGate::diagnostics($badPayload);
$badPayload['audit'] = passingAudit(94);
$badPayload['publish_decision'] = QualityGate::decision($badPayload, Settings::get());

if (($badPayload['publish_decision']['post_status'] ?? '') !== 'draft') {
    throw new RuntimeException('Generic product article was not blocked from publishing.');
}
if (!QualityGate::blocksRequestedPublication($badPayload['publish_decision'], Settings::get())) {
    throw new RuntimeException('Blocked article could still complete as a draft while publish was requested.');
}
if (QualityGate::blocksRequestedPublication($badPayload['publish_decision'], array_merge(Settings::get(), ['post_status' => 'draft']))) {
    throw new RuntimeException('Intentional draft mode was incorrectly treated as a failed publication.');
}
if ((int) ($badPayload['quality_diagnostics']['metrics']['near_duplicate_paragraph_pairs'] ?? 0) < 3) {
    throw new RuntimeException('Near-duplicate paragraphs were not detected.');
}
if ((int) ($badPayload['quality_diagnostics']['metrics']['product_fact_count'] ?? 0) !== 0) {
    throw new RuntimeException('Generic facts were incorrectly counted as product-specific facts.');
}

// A product-specific fixture proves the same deterministic gate still permits a useful article.
$goodPayload = passingArticlePayload();
$goodPayload['quality_diagnostics'] = QualityGate::diagnostics($goodPayload);
$goodPayload['audit'] = passingAudit(92);
$goodPayload['publish_decision'] = QualityGate::decision($goodPayload, Settings::get());
if (empty($goodPayload['quality_diagnostics']['passed'])) {
    throw new RuntimeException('Product-specific article failed: ' . implode(' / ', $goodPayload['quality_diagnostics']['errors'] ?? []));
}
if (($goodPayload['publish_decision']['post_status'] ?? '') !== 'publish') {
    throw new RuntimeException('Product-specific article was not approved for publishing.');
}
if (QualityGate::blocksRequestedPublication($goodPayload['publish_decision'], Settings::get())) {
    throw new RuntimeException('Approved article was incorrectly blocked from publishing.');
}

$article = $goodPayload['article'];
$content = ArticleVisuals::enhance(
    (string) $article['content_html'],
    (string) $article['title'],
    'cv',
    (string) $article['answer_summary']
);
$GLOBALS['dsap_test_meta'][99] = [
    '_dsap_test_src' => 'openverse-sample.jpg',
    '_wp_attachment_image_alt' => $article['image_alt'],
    '_dsap_image_title' => 'Support for distance education',
    '_dsap_image_creator' => 'EU-Ukraine cooperation',
    '_dsap_image_creator_url' => 'https://www.flickr.com/photos/149400054@N04',
    '_dsap_image_license' => 'BY-SA',
    '_dsap_image_license_url' => 'https://creativecommons.org/licenses/by-sa/2.0/',
    '_dsap_image_source_url' => 'https://www.flickr.com/photos/149400054@N04/54241970559',
];
$figure = ArticleImageGenerator::figure(99);
$paragraph = 0;
$withImage = preg_replace_callback('/<\/p>/i', static function (array $matches) use (&$paragraph, $figure): string {
    $paragraph++;
    return (string) $matches[0] . ($paragraph === 3 ? $figure : '');
}, $content);
$content = is_string($withImage) ? $withImage : $content;
$content .= '<aside class="dsap-cta dsap-cta-cv"><p class="dsap-cta-lead">'
    . esc_html((string) $article['cta_lead'])
    . '</p><p><a href="https://example.com/offer">'
    . esc_html((string) $article['cta_anchor'])
    . '</a></p></aside>';

if (!str_contains($content, 'dsap-generated-image') || !str_contains($content, '<figcaption>画像:')) {
    throw new RuntimeException('Article image or attribution caption is missing.');
}
if (preg_match('/(判断材料の整理|根拠の量|構成の深さ|内部導線|品質判定|監査スコア|AI監査)/u', wp_strip_all_tags($content))) {
    throw new RuntimeException('Internal AI diagnostics leaked into reader-facing content.');
}

echo wp_json_encode([
    'title' => $article['title'],
    'content_html' => $content,
    'diagnostics' => $goodPayload['quality_diagnostics'],
    'decision' => $goodPayload['publish_decision'],
    'image_figure_present' => str_contains($content, 'dsap-generated-image'),
    'rejected_article' => [
        'title' => $badPayload['article']['title'] ?? '',
        'decision' => $badPayload['publish_decision'],
        'diagnostics' => $badPayload['quality_diagnostics'],
    ],
], JSON_PRETTY_PRINT);

function passingAudit(int $score): array
{
    return [
        'overall_score' => $score,
        'intent_coverage' => $score,
        'factual_support' => $score,
        'clarity' => $score,
        'originality' => $score,
        'seo_quality' => $score,
        'information_gain' => $score,
        'conversion_quality' => $score,
        'reader_trust' => $score,
        'internal_link_quality' => $score,
        'product_specificity' => $score,
        'intent_plausibility' => $score,
        'non_redundancy' => $score,
        'generic_or_invented_frameworks' => [],
        'unsupported_claims' => [],
        'critical_issues' => [],
        'ymyl' => false,
    ];
}

function passingArticlePayload(): array
{
    $facts = [
        ['claim' => 'UR-U公式規約には無料体験期間と終了後の有料プランへの切替条件が記載されている。', 'confidence' => 'high'],
        ['claim' => 'UR-U公式申込画面には決済日、通貨、金額、無料体験後の扱いが表示される。', 'confidence' => 'high'],
        ['claim' => 'UR-U公式プランページでは複数プランの対象者と利用範囲を比較できる。', 'confidence' => 'high'],
        ['claim' => 'UR-U公式ガイドでは講義動画、講義LIVE、サポート資料の利用方法を案内している。', 'confidence' => 'high'],
        ['claim' => 'UR-U公式ガイドには動画視聴前に必要なプロフィール設定の案内がある。', 'confidence' => 'medium'],
        ['claim' => 'UR-UアプリはApple App Storeに掲載され、対応端末などの情報を確認できる。', 'confidence' => 'medium'],
        ['claim' => 'UR-Uの料金、解約、最低利用期間は変更され得るため申込時の表示が優先される。', 'confidence' => 'high'],
        ['claim' => 'UR-Uで得られる成果は受講だけで保証されず、学習後の実践が必要である。', 'confidence' => 'medium'],
    ];
    $content = <<<'HTML'
<p>UR-Uの無料体験を検討するときは、講義数の多さだけで決めるべきではありません。申込画面に表示される料金と決済日、無料期間後の扱い、解約条件、使う端末で講義まで到達できるかを先に見ると、登録後の行き違いを減らせます。</p>
<p>結論は、UR-Uの公式規約と自分の申込画面を読み、費用と利用条件を許容でき、試したい講義が決まっている人なら無料体験を判断材料にできます。条件が曖昧なまま、短期間で収入が増えることだけを期待する人は申込みを保留するのが安全です。</p>
<h2>UR-U無料体験の前に見るべき5項目</h2>
<p>最初に見るのは、無料期間の終了日、終了後に移るプラン、初回決済日、表示通貨と金額、解約手続きです。UR-Uの条件は変更される可能性があるため、検索記事の古い金額より、登録時に自分へ表示された画面と最新規約を優先します。</p>
<table><thead><tr><th>確認項目</th><th>見る場所</th><th>判断のポイント</th></tr></thead><tbody><tr><td>料金・通貨</td><td>UR-U申込画面</td><td>実際の請求額を許容できるか</td></tr><tr><td>自動切替</td><td>UR-U規約・申込画面</td><td>無料終了後のプランを理解したか</td></tr><tr><td>利用期間</td><td>UR-U規約</td><td>最低利用期間や途中解約条件があるか</td></tr><tr><td>解約方法</td><td>UR-U公式案内</td><td>期限と手続き場所が分かるか</td></tr><tr><td>端末</td><td>アプリ・実機</td><td>普段の環境で講義を開けるか</td></tr></tbody></table>
<p>特に無料という表示だけを切り取らず、その後に何が起きるかまで一続きで読むことが重要です。画面の内容を保存し、終了日をカレンダーへ入れておけば、記憶だけに頼らず継続か解約かを決められます。</p>
<h2>UR-Uのプランは料金だけで比較しない</h2>
<p>UR-U公式のプラン案内では、対象者や利用範囲が示されています。安いか高いかだけでなく、自分が学びたい領域を扱う講義があるか、講義LIVEや資料をどう使えるか、必要な機能が選ぶプランに含まれるかを照合します。</p>
<p>比較時には「いつか役立ちそうな講義」ではなく、最初に見る講義を一つ決めます。その講義を選んだ理由と、視聴後に作るものを言葉にできない場合、UR-Uへ登録しても動画一覧を眺めるだけになる可能性があります。</p>
<h2>UR-Uを使う端末でログインから講義まで試す</h2>
<p>UR-Uの公式ガイドは、プロフィール設定や動画ページへの進み方を案内しています。無料体験では、普段使うスマートフォンやPCでログインし、プロフィール設定を終え、目的の講義を開くところまでを一度通します。</p>
<p>アプリを利用する場合は、App Storeの対応情報、端末の空き容量、通信量、イヤホンの使いやすさも実機で見ます。移動中の視聴を前提にせず、安全に操作できる場所で再生と再開ができるかを判断材料にします。</p>
<h2>UR-Uが向く人と見送ったほうがよい人</h2>
<p>UR-Uが向くのは、学びたいテーマが具体的で、講義を見た後に自分の仕事や発信で試す予定がある人です。講義LIVEや資料を含め、受け身の視聴以外に使う場面を決めているほど、無料体験で適合性を見極めやすくなります。</p>
<p>一方、受講だけで案件や収入が得られると考えている人、契約条件を読まずに登録する人、試したい講義が決まっていない人には向きません。UR-Uに限らず、オンライン学習は視聴時間より、学んだ内容を実践へ移す時間が取れるかで結果が変わります。</p>
<ul><li>向く人：UR-Uで学ぶテーマと最初の講義が決まっている</li><li>保留する人：料金・自動切替・解約条件をまだ説明できない</li><li>見送る人：受講だけで短期成果が保証されると期待している</li></ul>
<h2>UR-U無料体験で残す記録</h2>
<p>体験中は講義を何本見たかではなく、疑問が解消したか、実践へ移せたか、端末で無理なく再開できたかを記録します。UR-Uの内容と生活の相性を判断できれば、継続しない結論でも無料体験を有効に使えたと言えます。</p>
<ol><li>申込時の料金、通貨、決済日、無料終了日を保存する</li><li>目的のUR-U講義を一つ選び、最後まで視聴する</li><li>講義から試す行動を一つ決め、実行結果を残す</li><li>公式規約を再確認し、継続・保留・解約を期限前に決める</li></ol>
<h2>UR-Uへ申し込む前の最終判断</h2>
<p>UR-Uの無料体験へ進む条件は、費用と契約条件を理解し、使う端末で講義を開け、最初の実践内容まで決まっていることです。この三つがそろわなければ、公式情報へ戻って不足している判断材料を埋めます。</p>
<p>三つがそろった人は、自分の申込画面に表示される最新条件を最後に見直します。この記事の役割は登録を急がせることではなく、UR-Uが自分に合うかを根拠を持って選べる状態にすることです。</p>
HTML;
    return [
        'research' => [
            'primary_keyword' => 'UR-U 無料体験 料金',
            'entities' => ['UR-U'],
            'facts' => $facts,
            'sources' => [['url' => 'https://www.ur-uni.com/info'], ['url' => 'https://www.ur-uni.com/post/_plan'], ['url' => 'https://www.ur-uni.com/post/intro'], ['url' => 'https://member.ur-uni.com/uru/new'], ['url' => 'https://apps.apple.com/jp/app/id1520357842']],
        ],
        'funnel' => [
            'article_type' => 'cv',
            'reader_stage' => 'product_aware',
            'conversion_bridge' => '契約条件と利用環境を理解した読者だけをUR-U公式情報へ案内する。',
        ],
        'article' => [
            'title' => 'UR-Uの無料体験前に確認する5項目｜料金・自動更新・アプリの判断基準',
            'focus_keyword' => 'UR-U 無料体験 料金',
            'answer_summary' => 'UR-Uの無料体験は、料金、自動切替、利用期間、解約方法、端末環境を公式情報で確認し、最初に試す講義と実践内容を決めてから使うと判断材料になります。',
            'content_html' => $content,
            'cta_lead' => '料金や契約条件は変更される可能性があります。申込み前に、あなたの画面へ表示される最新情報を確認してください。',
            'cta_anchor' => 'UR-U公式サイトで最新の料金と条件を見る',
            'image_search_query' => 'adult studying online course laptop',
            'image_alt' => '自宅のノートパソコンでオンライン講義を受ける社会人',
            'source_indexes' => [0, 1, 2, 3, 4],
            'internal_link_post_ids' => [],
            'ymyl' => false,
        ],
        'internal_link_candidates' => [],
    ];
}
