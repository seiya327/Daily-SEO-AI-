<?php

declare(strict_types=1);

namespace DSAP;

final class AdminPage
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_dsap_create_topic', [self::class, 'createTopic']);
        add_action('admin_post_dsap_run_now', [self::class, 'runNow']);
        add_action('admin_post_dsap_test_run', [self::class, 'testRun']);
        add_action('admin_post_dsap_save_api_key', [self::class, 'saveApiKey']);
        add_action('admin_post_dsap_save_quality', [self::class, 'saveQuality']);
        add_action('admin_post_dsap_save_keyword_strategy', [self::class, 'saveKeywordStrategy']);
        add_action('admin_post_dsap_auto_setup', [self::class, 'autoSetup']);
        add_action('admin_post_dsap_reset_article_plan', [self::class, 'resetArticlePlan']);
        add_action('admin_post_dsap_generate_strategy', [self::class, 'generateStrategy']);
        add_action('admin_post_dsap_retry_job', [self::class, 'retryJob']);
        add_action('admin_post_dsap_delete_api_key', [self::class, 'deleteApiKey']);
        add_action('admin_post_dsap_gsc_connect', [self::class, 'gscConnect']);
        add_action('admin_post_dsap_gsc_callback', [self::class, 'gscCallback']);
        add_action('admin_post_dsap_gsc_disconnect', [self::class, 'gscDisconnect']);
        add_action('admin_post_dsap_gsc_sync_now', [self::class, 'gscSyncNow']);
        add_action('admin_post_dsap_refresh_candidates', [self::class, 'refreshCandidates']);
        add_action('admin_post_dsap_refresh_post', [self::class, 'refreshPost']);
        add_action('admin_post_dsap_apply_refresh_draft', [self::class, 'applyRefreshDraft']);
        add_action('admin_post_dsap_discard_refresh_draft', [self::class, 'discardRefreshDraft']);
        add_action('admin_post_dsap_import_google_oauth', [self::class, 'importGoogleOAuth']);
        add_action('admin_post_dsap_save_gsc_property', [self::class, 'saveGscProperty']);
        add_action('admin_post_dsap_enable_pdca', [self::class, 'enablePdca']);
        add_action('admin_post_dsap_check_github_updates', [self::class, 'checkGitHubUpdates']);
        add_action('admin_post_dsap_install_github_update', [self::class, 'installGitHubUpdate']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function menu(): void
    {
        add_menu_page('Daily SEO AI', 'Daily SEO AI', 'manage_options', 'dsap', [self::class, 'render'], 'dashicons-chart-line', 58);
    }

    public static function assets(string $hook): void
    {
        if ($hook === 'toplevel_page_dsap') {
            wp_enqueue_style('dsap-admin', DSAP_URL . 'assets/admin.css', [], DSAP_VERSION);
            wp_enqueue_script('dsap-admin', DSAP_URL . 'assets/admin.js', [], DSAP_VERSION, true);
        }
    }

    public static function render(): void
    {
        self::requirePermission();
        Settings::ensureDefaults();
        $settings = Settings::get();
        $topics = (new TopicRepository())->latest(50);
        $jobs = (new JobRepository())->latest(30);
        $strategy = get_option('dsap_strategy_plan', []);
        $hasKey = Settings::apiKey() !== '';
        $hasGoogleSecret = (string) $settings['gsc_client_secret'] !== '';
        $gscConnected = GoogleOAuth::connected();
        $lastSync = get_option('dsap_gsc_last_sync', []);
        $gscSites = get_option('dsap_gsc_sites', []);
        $hasGithubToken = (string) $settings['github_token'] !== '';
        $autoSetupState = self::autoSetupState($settings, $hasKey, is_array($strategy) ? $strategy : []);
        ?>
        <div class="wrap dsap-wrap">
            <h1>Daily SEO AI Publisher</h1>
            <?php self::notice(); ?>

            <nav class="nav-tab-wrapper dsap-tabs">
                <a class="nav-tab nav-tab-active" href="#dsap-initial-setup">初期設定</a>
                <a class="nav-tab" href="#dsap-dashboard">運用</a>
                <a class="nav-tab" href="#dsap-strategy">戦略</a>
                <a class="nav-tab" href="#dsap-pdca">改善</a>
                <a class="nav-tab" href="#dsap-settings">設定</a>
            </nav>

            <section id="dsap-initial-setup" class="dsap-section is-active">
                <div class="dsap-hero">
                    <div>
                        <h2>最初だけここで設定する</h2>
                        <p>APIキーを保存したあと、このタブの順番どおりに進めれば記事生成に必要な初期設定、Google連携、テスト実行まで完了できます。</p>
                    </div>
                </div>

                <div class="dsap-panel">
                    <h2>進捗</h2>
                    <?php self::autoSetupProgress($autoSetupState); ?>
                </div>

                <div class="dsap-panel">
                    <h2>初期設定の順番</h2>
                    <ol class="dsap-cycle">
                        <li>OpenAI APIキーを保存する</li>
                        <li>自動初期設定でサイト戦略と記事計画を作る</li>
                        <li>Google Search Consoleを接続して改善データを取れるようにする</li>
                        <li>最後にテスト実行で1記事を作成する</li>
                        <li>下のパイプラインで完了またはエラーを確認する</li>
                    </ol>
                </div>

                <div class="dsap-panel">
                    <h2>1. OpenAI APIキー</h2>
                    <?php if ($hasKey) : ?>
                        <p><strong>設定済みです。</strong> 変更したい場合だけ新しいキーを保存してください。</p>
                    <?php else : ?>
                        <p class="description">最初にAPIキーを保存してください。保存済みキーは画面に再表示しません。</p>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('dsap_save_api_key'); ?>
                        <input type="hidden" name="action" value="dsap_save_api_key">
                        <input type="password" name="<?php echo esc_attr(Settings::OPTION); ?>[openai_api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr($hasKey ? '設定済み（空欄なら維持）' : 'APIキーを入力'); ?>">
                        <?php submit_button($hasKey ? 'APIキーを更新' : 'APIキーを保存', 'primary', 'submit', false); ?>
                    </form>
                </div>

                <div class="dsap-panel">
                    <h2>記事品質</h2>
                    <p class="description">検索意図への即答、情報利得、比較基準、失敗例、注意点、根拠、自然なCV導線を含む標準編集ルールは常に適用されます。ここでは文字量、監査基準、自動再執筆回数をまとめて調整します。不合格時の再執筆では追加のAPI利用料が発生します。</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('dsap_save_quality'); ?>
                        <input type="hidden" name="action" value="dsap_save_quality">
                        <?php self::qualitySelect((string) $settings['article_quality']); ?>
                        <?php submit_button('記事品質を保存', 'primary', 'submit', false); ?>
                    </form>
                </div>

                <div class="dsap-panel">
                    <h2>2. 自動設定と記事計画</h2>
                    <p class="description">設定を直したあとに押すと、古い記事計画をリセットして新しい戦略を作り直します。</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dsap-inline-setting">
                        <?php wp_nonce_field('dsap_save_keyword_strategy'); ?>
                        <input type="hidden" name="action" value="dsap_save_keyword_strategy">
                        <label>キーワード戦略 <?php self::keywordStrategySelect((string) $settings['keyword_strategy']); ?></label>
                        <?php submit_button('戦略設定を保存', 'secondary', 'submit', false); ?>
                    </form>
                    <div class="dsap-actions">
                        <?php self::actionForm('dsap_auto_setup', '自動初期設定を実行', 'primary'); ?>
                        <?php self::actionForm('dsap_reset_article_plan', '記事計画をリセット', 'delete'); ?>
                    </div>
                </div>

                <div class="dsap-panel dsap-setup-panel">
                    <h2>3. Google連携と自動改善</h2>
                    <?php self::gscSetupWizard($settings, $gscConnected, is_array($gscSites) ? $gscSites : [], is_array($lastSync) ? $lastSync : []); ?>
                </div>

                <div class="dsap-panel">
                    <h2>4. 最後にテスト実行</h2>
                    <p class="description">ここで1記事のテストジョブを作成します。下書きまたは設定した投稿状態で記事ができれば初期設定は成功です。</p>
                    <div class="dsap-actions">
                        <?php self::actionForm('dsap_test_run', 'テスト実行', 'primary'); ?>
                    </div>
                </div>

                <div class="dsap-panel">
                    <h2>5. テスト結果とパイプライン</h2>
                    <?php self::jobsTable(array_slice($jobs, 0, 10)); ?>
                </div>

                <div class="dsap-panel">
                    <h2>実行前チェック</h2>
                    <div class="dsap-setup-checks">
                        <span class="<?php echo $hasKey ? 'is-done' : 'is-needed'; ?>">OpenAI APIキー: <?php echo esc_html($hasKey ? '設定済み' : '未設定'); ?></span>
                        <span class="<?php echo trim((string) $settings['site_theme']) !== '' ? 'is-done' : 'is-optional'; ?>">サイトテーマ: <?php echo esc_html(trim((string) $settings['site_theme']) !== '' ? '入力済み' : 'AIが補完'); ?></span>
                        <span class="<?php echo trim((string) $settings['conversion_goal']) !== '' ? 'is-done' : 'is-optional'; ?>">CV目標: <?php echo esc_html(trim((string) $settings['conversion_goal']) !== '' ? '入力済み' : 'AIが補完'); ?></span>
                    </div>
                    <?php if (!$hasKey) : ?>
                        <p class="description">先に「設定」タブでOpenAI APIキーを保存してください。保存後にこのタブへ戻って自動初期設定を実行します。</p>
                    <?php endif; ?>
                </div>

                <div class="dsap-panel">
                    <h2>現在の初期設定</h2>
                    <table class="widefat striped">
                        <tbody>
                            <tr><th>サイトテーマ</th><td><?php echo esc_html((string) ($settings['site_theme'] ?: '-')); ?></td></tr>
                            <tr><th>対象読者</th><td><?php echo esc_html((string) ($settings['target_audience'] ?: '-')); ?></td></tr>
                            <tr><th>CV目標</th><td><?php echo esc_html((string) ($settings['conversion_goal'] ?: '-')); ?></td></tr>
                            <tr><th>毎日実行</th><td><?php echo esc_html(!empty($settings['daily_enabled']) ? '有効 ' . (string) $settings['daily_time'] : '停止中'); ?></td></tr>
                            <tr><th>投稿状態</th><td><?php echo esc_html(['draft' => '下書き', 'pending' => 'レビュー待ち', 'publish' => '公開'][(string) $settings['post_status']] ?? (string) $settings['post_status']); ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="dsap-panel">
                    <h2>自動で行うこと</h2>
                    <ol class="dsap-cycle">
                        <li>API利用モードへ切り替え</li>
                        <li>毎日実行、下書き投稿、記事数、CTA、集客/CV比率を初期化</li>
                        <li>サイト名からテーマ・読者・CV目標の不足分を補完</li>
                        <li>GitHub更新確認を有効化</li>
                        <li>AIサイト戦略の作成ジョブを開始</li>
                    </ol>
                </div>
            </section>

            <section id="dsap-dashboard" class="dsap-section">
                <div class="dsap-hero">
                    <div>
                        <h2>AIに任せて、毎日のSEO運用を回す</h2>
                        <p>最初は商材・読者・誘導先だけ入れれば動きます。細かいモデルや比率はAI標準設定で始められます。</p>
                    </div>
                    <div class="dsap-actions">
                        <?php self::actionForm('dsap_generate_strategy', 'AIで戦略を作る', 'primary'); ?>
                        <?php self::actionForm('dsap_run_now', '今日の分を実行', 'secondary'); ?>
                    </div>
                </div>

                <div class="dsap-stats">
                    <?php self::stat('今日の記事数', (string) $settings['max_daily_new_articles']); ?>
                    <?php self::stat('集客 / CV', (string) $settings['attraction_ratio'] . ' / ' . (100 - (int) $settings['attraction_ratio'])); ?>
                    <?php self::stat('計画済み', (string) count($topics) . '記事'); ?>
                    <?php self::stat('自動実行', !empty($settings['daily_enabled']) ? '有効 ' . (string) $settings['daily_time'] : '停止中'); ?>
                </div>

                <div class="dsap-panel">
                    <h2>パイプライン進捗</h2>
                    <?php self::jobsTable($jobs); ?>
                </div>
            </section>

            <section id="dsap-strategy" class="dsap-section">
                <div class="dsap-panel">
                    <h2>サイト戦略</h2>
                    <p>AIが集客記事、CV記事、内部リンク、アフィリエイト誘導をまとめて設計します。</p>
                    <?php self::strategySummary($strategy); ?>
                </div>
                <div class="dsap-panel">
                    <h2>記事を個別追加</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('dsap_create_topic'); ?>
                        <input type="hidden" name="action" value="dsap_create_topic">
                        <table class="form-table" role="presentation">
                            <tr><th><label for="dsap-keyword">キーワード</label></th><td><input id="dsap-keyword" name="keyword" class="regular-text" required></td></tr>
                            <tr><th>記事タイプ</th><td><select name="article_type"><option value="attraction">集客記事</option><option value="cv">CV記事</option></select></td></tr>
                            <tr><th><label for="dsap-cluster">クラスター</label></th><td><input id="dsap-cluster" name="cluster_name" class="regular-text"></td></tr>
                            <tr><th><label for="dsap-target-url">誘導先URL</label></th><td><input id="dsap-target-url" name="target_url" type="url" class="regular-text"></td></tr>
                            <tr><th><label for="dsap-anchor">リンク文言</label></th><td><input id="dsap-anchor" name="anchor_text" class="regular-text"></td></tr>
                            <tr><th><label for="dsap-topic-instructions">個別指示</label></th><td><textarea id="dsap-topic-instructions" name="instructions" rows="4" class="large-text"></textarea></td></tr>
                        </table>
                        <?php submit_button('記事計画に追加'); ?>
                    </form>
                </div>
                <div class="dsap-panel"><h2>記事計画</h2><?php self::topicsTable($topics); ?></div>
            </section>

            <section id="dsap-pdca" class="dsap-section">
                <div class="dsap-panel dsap-setup-panel">
                    <h2>Google連携と自動改善</h2>
                    <?php self::gscSetupWizard($settings, $gscConnected, is_array($gscSites) ? $gscSites : [], is_array($lastSync) ? $lastSync : []); ?>
                </div>
                <div class="dsap-grid">
                    <div class="dsap-panel">
                        <h2>Search Console</h2>
                        <p><strong>状態:</strong> <?php echo esc_html($gscConnected ? '接続済み' : '未接続'); ?></p>
                        <p><strong>プロパティ:</strong> <?php echo esc_html((string) ($settings['gsc_site_url'] ?: '-')); ?></p>
                        <p><strong>最終同期:</strong> <?php echo esc_html(is_array($lastSync) ? (string) (($lastSync['synced_at'] ?? '-') . (!empty($lastSync['error']) ? ' / エラー: ' . $lastSync['error'] : ' / ' . ($lastSync['rows'] ?? 0) . '行')) : '-'); ?></p>
                        <div class="dsap-actions">
                            <?php if ($gscConnected) : ?>
                                <?php self::actionForm('dsap_gsc_sync_now', 'データ同期', 'secondary'); ?>
                                <?php self::actionForm('dsap_gsc_disconnect', '接続解除', 'delete'); ?>
                            <?php else : ?>
                                <?php self::actionForm('dsap_gsc_connect', 'Search Consoleに接続', 'primary'); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="dsap-panel">
                        <h2>改善サイクル</h2>
                        <ol class="dsap-cycle"><li>Search Consoleデータ取得</li><li>28日対28日で悪化・機会を判定</li><li>AIが改善計画と原稿を作成</li><li>監査後に下書きまたは自動反映</li><li>次回データで再評価</li></ol>
                        <?php self::actionForm('dsap_refresh_candidates', '改善候補を今すぐ判定', 'primary'); ?>
                    </div>
                </div>
                <div class="dsap-panel"><h2>記事別パフォーマンス</h2><?php self::metricsTable(); ?></div>
            </section>

            <section id="dsap-settings" class="dsap-section">
                <div class="dsap-panel">
                    <h2>かんたん設定</h2>
                    <form method="post" action="options.php">
                        <?php settings_fields('dsap_settings_group'); ?>
                        <div class="dsap-quick-grid">
                            <label>サイトテーマ・商材
                                <input name="<?php echo esc_attr(Settings::OPTION); ?>[site_theme]" value="<?php echo esc_attr((string) $settings['site_theme']); ?>" class="large-text" placeholder="例: WordPress高速化サービス、転職エージェント比較">
                            </label>
                            <label>誰に届けたいか
                                <textarea name="<?php echo esc_attr(Settings::OPTION); ?>[target_audience]" rows="3" class="large-text" placeholder="例: 個人ブロガー、法人マーケ担当、購入直前の比較ユーザー"><?php echo esc_textarea((string) $settings['target_audience']); ?></textarea>
                            </label>
                            <label>CV目標
                                <textarea name="<?php echo esc_attr(Settings::OPTION); ?>[conversion_goal]" rows="3" class="large-text" placeholder="例: 無料相談、資料請求、アフィリエイトリンククリック"><?php echo esc_textarea((string) $settings['conversion_goal']); ?></textarea>
                            </label>
                            <label>アフィリエイトURL
                                <input type="url" name="<?php echo esc_attr(Settings::OPTION); ?>[affiliate_url]" value="<?php echo esc_attr((string) $settings['affiliate_url']); ?>" class="large-text" placeholder="https://">
                            </label>
                        </div>

                        <div class="dsap-compact-options">
                            <label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[daily_enabled]" value="1" <?php checked($settings['daily_enabled']); ?>> 毎日自動で実行</label>
                            <label>時刻 <input type="time" name="<?php echo esc_attr(Settings::OPTION); ?>[daily_time]" value="<?php echo esc_attr((string) $settings['daily_time']); ?>"></label>
                            <label>1日の新規記事数 <input class="small-text" type="number" min="1" max="10" name="<?php echo esc_attr(Settings::OPTION); ?>[max_daily_new_articles]" value="<?php echo esc_attr((string) $settings['max_daily_new_articles']); ?>"></label>
                            <label>投稿状態 <select name="<?php echo esc_attr(Settings::OPTION); ?>[post_status]"><?php foreach (['draft' => '下書き', 'pending' => 'レビュー待ち', 'publish' => '公開'] as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($settings['post_status'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                        </div>

                        <div class="dsap-advanced">
                            <h3>詳細設定</h3>
                            <table class="form-table" role="presentation">
                                <tr><th><label for="dsap-key">OpenAI APIキー</label></th><td><input id="dsap-key" type="password" name="<?php echo esc_attr(Settings::OPTION); ?>[openai_api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr($hasKey ? '設定済み（空欄なら維持）' : 'APIキーを入力'); ?>"><p class="description">保存済みキーは画面に再表示しません。</p></td></tr>
                                <tr><th>記事品質</th><td><?php self::qualitySelect((string) $settings['article_quality'], Settings::OPTION . '[article_quality]'); ?></td></tr>
                                <tr><th>リサーチ・執筆モデル</th><td><?php self::modelSelect('model_research', (string) $settings['model_research']); ?></td></tr>
                                <tr><th>監査モデル</th><td><?php self::modelSelect('model_audit', (string) $settings['model_audit']); ?></td></tr>
                                <tr><th>改善モデル</th><td><?php self::modelSelect('model_refresh', (string) $settings['model_refresh']); ?></td></tr>
                                <tr><th><label for="dsap-anchor-default">CTA文言</label></th><td><input id="dsap-anchor-default" name="<?php echo esc_attr(Settings::OPTION); ?>[affiliate_anchor]" value="<?php echo esc_attr((string) $settings['affiliate_anchor']); ?>" class="regular-text"></td></tr>
                                <tr><th><label for="dsap-disclosure">広告表記</label></th><td><input id="dsap-disclosure" name="<?php echo esc_attr(Settings::OPTION); ?>[affiliate_disclosure]" value="<?php echo esc_attr((string) $settings['affiliate_disclosure']); ?>" class="large-text"></td></tr>
                                <tr><th><label for="dsap-ratio">集客記事の割合</label></th><td><input id="dsap-ratio" type="number" min="0" max="100" step="10" name="<?php echo esc_attr(Settings::OPTION); ?>[attraction_ratio]" value="<?php echo esc_attr((string) $settings['attraction_ratio']); ?>"> %</td></tr>
                                <tr><th>キーワード戦略</th><td><?php self::keywordStrategySelect((string) $settings['keyword_strategy'], Settings::OPTION . '[keyword_strategy]'); ?></td></tr>
                                <tr><th><label for="dsap-strategy-instructions">戦略への追加指示</label></th><td><textarea id="dsap-strategy-instructions" name="<?php echo esc_attr(Settings::OPTION); ?>[strategy_instructions]" rows="4" class="large-text"><?php echo esc_textarea((string) $settings['strategy_instructions']); ?></textarea></td></tr>
                                <tr><th><label for="dsap-global">全記事への指示</label></th><td><textarea id="dsap-global" name="<?php echo esc_attr(Settings::OPTION); ?>[global_instructions]" rows="5" class="large-text"><?php echo esc_textarea((string) $settings['global_instructions']); ?></textarea></td></tr>
                                <tr><th><label for="dsap-gsc-client-id">GoogleクライアントID</label></th><td><input id="dsap-gsc-client-id" name="<?php echo esc_attr(Settings::OPTION); ?>[gsc_client_id]" value="<?php echo esc_attr((string) $settings['gsc_client_id']); ?>" class="large-text"></td></tr>
                                <tr><th><label for="dsap-gsc-secret">Googleクライアントシークレット</label></th><td><input id="dsap-gsc-secret" type="password" name="<?php echo esc_attr(Settings::OPTION); ?>[gsc_client_secret]" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo esc_attr($hasGoogleSecret ? '設定済み（空欄なら維持）' : 'クライアントシークレット'); ?>"></td></tr>
                                <tr><th><label for="dsap-gsc-site">Search Consoleプロパティ</label></th><td><?php self::gscSiteField((string) $settings['gsc_site_url'], is_array($gscSites) ? $gscSites : []); ?></td></tr>
                                <tr><th>GSC日次同期</th><td><label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[gsc_enabled]" value="1" <?php checked($settings['gsc_enabled']); ?>> 有効</label> <input type="time" name="<?php echo esc_attr(Settings::OPTION); ?>[gsc_sync_time]" value="<?php echo esc_attr((string) $settings['gsc_sync_time']); ?>"></td></tr>
                                <tr><th>PDCA自動実行</th><td><label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[refresh_enabled]" value="1" <?php checked($settings['refresh_enabled']); ?>> 有効</label></td></tr>
                                <tr><th><label for="dsap-refresh-count">1日の改善記事数</label></th><td><input id="dsap-refresh-count" type="number" min="0" max="5" name="<?php echo esc_attr(Settings::OPTION); ?>[max_daily_refreshes]" value="<?php echo esc_attr((string) $settings['max_daily_refreshes']); ?>"></td></tr>
                                <tr><th><label for="dsap-refresh-impressions">最低表示回数</label></th><td><input id="dsap-refresh-impressions" type="number" min="10" name="<?php echo esc_attr(Settings::OPTION); ?>[refresh_min_impressions]" value="<?php echo esc_attr((string) $settings['refresh_min_impressions']); ?>"> / 28日</td></tr>
                                <tr><th><label for="dsap-refresh-cooldown">再改善までの日数</label></th><td><input id="dsap-refresh-cooldown" type="number" min="14" max="180" name="<?php echo esc_attr(Settings::OPTION); ?>[refresh_cooldown_days]" value="<?php echo esc_attr((string) $settings['refresh_cooldown_days']); ?>"> 日</td></tr>
                                <tr><th>改善原稿の扱い</th><td><label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[refresh_auto_apply]" value="1" <?php checked($settings['refresh_auto_apply']); ?>> 監査85点以上だけ既存記事へ自動反映</label></td></tr>
                                <tr><th><label for="dsap-refresh-instructions">改善への追加指示</label></th><td><textarea id="dsap-refresh-instructions" name="<?php echo esc_attr(Settings::OPTION); ?>[refresh_instructions]" rows="4" class="large-text"><?php echo esc_textarea((string) $settings['refresh_instructions']); ?></textarea></td></tr>
                                <tr><th>GitHub更新</th><td><label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[github_updates_enabled]" value="1" <?php checked($settings['github_updates_enabled']); ?>> GitHub Releasesから更新を確認</label><br><label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[github_auto_update]" value="1" <?php checked($settings['github_auto_update']); ?>> 安定Releaseを自動更新</label></td></tr>
                                <tr><th><label for="dsap-github-token">GitHubトークン</label></th><td><input id="dsap-github-token" type="password" name="<?php echo esc_attr(Settings::OPTION); ?>[github_token]" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo esc_attr($hasGithubToken ? '設定済み（空欄なら維持）' : '非公開リポジトリの場合のみ'); ?>"> <?php if ($hasGithubToken) : ?><label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[delete_github_token]" value="1"> 保存済みトークンを削除</label><?php endif; ?><p class="description">公開リポジトリでは不要です。非公開の場合は対象リポジトリのContents読み取り権限を持つfine-grained tokenを使います。</p></td></tr>
                                <tr><th>テストモード</th><td><label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[mock_mode]" value="1" <?php checked($settings['mock_mode']); ?>> APIを使わずサンプルデータで動作確認</label></td></tr>
                                <tr><th>アンインストール</th><td><label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[delete_data_on_uninstall]" value="1" <?php checked($settings['delete_data_on_uninstall']); ?>> 削除時にプラグインデータも消す</label></td></tr>
                            </table>
                        </div>
                        <?php submit_button('設定を保存'); ?>
                    </form>
                    <div class="dsap-actions dsap-settings-actions">
                        <?php if ($hasKey) : ?><?php self::actionForm('dsap_delete_api_key', 'APIキーを削除', 'delete'); ?><?php endif; ?>
                        <?php self::actionForm('dsap_check_github_updates', 'GitHub更新を確認', 'secondary'); ?>
                        <?php self::actionForm('dsap_install_github_update', 'GitHubから今すぐ更新', 'primary'); ?>
                    </div>
                    <?php $updatePermissionError = self::updatePermissionError(); ?>
                    <?php if ($updatePermissionError !== '') : ?>
                        <p class="description"><strong>更新実行不可:</strong> <?php echo esc_html($updatePermissionError); ?></p>
                    <?php else : ?>
                        <p class="description">「今すぐ更新」はWordPress標準の更新処理を直接開始します。サーバーがFTP/SSH認証を必要とする場合だけ、接続情報の入力画面が表示されます。</p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
        <?php
    }

    public static function createTopic(): void
    {
        self::guard('dsap_create_topic');
        $keyword = sanitize_text_field((string) wp_unslash($_POST['keyword'] ?? ''));
        if ($keyword !== '') {
            (new TopicRepository())->create(
                $keyword,
                sanitize_textarea_field((string) wp_unslash($_POST['instructions'] ?? '')),
                sanitize_key((string) ($_POST['article_type'] ?? 'attraction')),
                sanitize_text_field((string) wp_unslash($_POST['cluster_name'] ?? '')),
                esc_url_raw((string) wp_unslash($_POST['target_url'] ?? '')),
                sanitize_text_field((string) wp_unslash($_POST['anchor_text'] ?? ''))
            );
        }
        self::redirect('記事計画に追加しました。');
    }

    public static function runNow(): void
    {
        self::guard('dsap_run_now');
        $before = count((new JobRepository())->latest(100));
        Scheduler::dailyGenerate();
        $after = count((new JobRepository())->latest(100));
        self::redirect($after > $before ? '今日の実行計画をキューに追加しました。' : '実行できる記事計画がありません。先にAIでサイト戦略を作成してください。');
    }

    public static function testRun(): void
    {
        self::guard('dsap_test_run');
        $jobId = Scheduler::queueDailyJob('test');
        if ($jobId > 0) {
            wp_schedule_single_event(time() + 1, Scheduler::HOOK_RETRY_JOB, [$jobId]);
        }
        self::redirect($jobId > 0 ? 'テストジョブを開始しました。進捗欄を更新して確認できます。' : 'テスト対象の記事計画がありません。');
    }

    public static function saveApiKey(): void
    {
        self::guard('dsap_save_api_key');
        $input = is_array($_POST[Settings::OPTION] ?? null) ? wp_unslash($_POST[Settings::OPTION]) : [];
        $apiKey = trim((string) ($input['openai_api_key'] ?? ''));
        if ($apiKey === '') {
            self::redirect(Settings::apiKey() !== '' ? 'APIキーは設定済みです。変更する場合だけ新しいキーを入力してください。' : 'OpenAI APIキーを入力してください。');
        }

        $settings = Settings::get();
        $settings['openai_api_key'] = $apiKey;
        update_option(Settings::OPTION, $settings, false);
        self::redirect('OpenAI APIキーを保存しました。次に自動初期設定を実行してください。');
    }

    public static function saveQuality(): void
    {
        self::guard('dsap_save_quality');
        $quality = sanitize_key((string) ($_POST['article_quality'] ?? 'high'));
        $profile = Settings::qualityProfile($quality);
        $settings = Settings::get();
        $settings['article_quality'] = array_key_exists($quality, Settings::qualityProfiles()) ? $quality : 'high';
        $settings['model_research'] = (string) $profile['model_research'];
        $settings['model_audit'] = (string) $profile['model_audit'];
        if (trim((string) $settings['global_instructions']) === '') {
            $settings['global_instructions'] = (string) $profile['instruction'];
        }
        update_option(Settings::OPTION, $settings, false);
        self::redirect('記事品質を「' . (string) $profile['label'] . '」に設定しました。次のテスト実行から反映されます。');
    }

    public static function saveKeywordStrategy(): void
    {
        self::guard('dsap_save_keyword_strategy');
        $strategy = sanitize_key((string) ($_POST['keyword_strategy'] ?? 'longtail'));
        $strategies = Settings::keywordStrategies();
        $settings = Settings::get();
        $settings['keyword_strategy'] = array_key_exists($strategy, $strategies) ? $strategy : 'longtail';
        update_option(Settings::OPTION, $settings, false);
        self::redirect('キーワード戦略を「' . (string) $strategies[$settings['keyword_strategy']] . '」に設定しました。次に記事計画をリセットして自動初期設定を実行してください。');
    }

    public static function autoSetup(): void
    {
        self::guard('dsap_auto_setup');
        if (Settings::apiKey() === '') {
            self::redirect('先にOpenAI APIキーを保存してください。その後に自動初期設定を実行できます。');
        }

        $settings = Settings::get();
        $siteName = trim(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES));
        $description = trim(wp_specialchars_decode((string) get_bloginfo('description'), ENT_QUOTES));
        $siteTheme = trim((string) $settings['site_theme']);
        if ($siteTheme === '') {
            $siteTheme = $siteName !== '' ? $siteName : wp_parse_url(home_url('/'), PHP_URL_HOST);
            if ($description !== '') {
                $siteTheme .= ' - ' . $description;
            }
        }

        $settings['mock_mode'] = false;
        $settings['daily_enabled'] = true;
        $settings['daily_time'] = (string) ($settings['daily_time'] ?: '09:00');
        $settings['max_daily_new_articles'] = max(1, min(3, (int) ($settings['max_daily_new_articles'] ?: 1)));
        $settings['post_status'] = in_array((string) $settings['post_status'], ['draft', 'pending', 'publish'], true) ? $settings['post_status'] : 'draft';
        $settings['attraction_ratio'] = (int) ($settings['attraction_ratio'] ?: 70);
        if (!array_key_exists((string) ($settings['keyword_strategy'] ?? ''), Settings::keywordStrategies())) {
            $settings['keyword_strategy'] = 'longtail';
        }
        $settings['site_theme'] = $siteTheme;
        if (trim((string) $settings['target_audience']) === '') {
            $settings['target_audience'] = 'このサイトのテーマに関心があり、比較・検討しながら信頼できる情報を探している見込み客。';
        }
        if (trim((string) $settings['conversion_goal']) === '') {
            $settings['conversion_goal'] = trim((string) $settings['affiliate_url']) !== ''
                ? '読者の悩みを解決したうえで、自然にアフィリエイトリンクのクリックへ誘導する。'
                : '読者の悩みを解決したうえで、問い合わせ・申込み・資料請求などの行動へ誘導する。';
        }
        if (trim((string) $settings['affiliate_anchor']) === '') {
            $settings['affiliate_anchor'] = '公式サイトで詳細を見る';
        }
        if (trim((string) $settings['affiliate_disclosure']) === '') {
            $settings['affiliate_disclosure'] = '本記事には広告・アフィリエイトリンクが含まれます。';
        }
        $qualityProfile = Settings::qualityProfile((string) ($settings['article_quality'] ?? 'high'));
        $settings['article_quality'] = (string) ($settings['article_quality'] ?: 'high');
        $settings['model_research'] = (string) ($qualityProfile['model_research'] ?? ($settings['model_research'] ?: 'gpt-5.6-terra'));
        $settings['model_audit'] = (string) ($qualityProfile['model_audit'] ?? ($settings['model_audit'] ?: 'gpt-5.6-luna'));
        $settings['model_refresh'] = (string) ($settings['model_refresh'] ?: 'gpt-5.6-terra');
        $settings['github_updates_enabled'] = true;
        $settings['refresh_min_impressions'] = max(100, (int) $settings['refresh_min_impressions']);
        $settings['refresh_cooldown_days'] = max(28, (int) $settings['refresh_cooldown_days']);
        $settings['max_daily_refreshes'] = max(1, (int) $settings['max_daily_refreshes']);
        if (GoogleOAuth::connected() && trim((string) $settings['gsc_site_url']) !== '') {
            $settings['gsc_enabled'] = true;
            $settings['refresh_enabled'] = true;
        }

        update_option(Settings::OPTION, $settings, false);
        Scheduler::rescheduleDaily($settings);
        Scheduler::reschedulePdca($settings);

        self::clearArticlePlan();
        $jobId = Scheduler::queueStrategyJob('auto_setup');
        update_option('dsap_auto_setup_status', [
            'started_at' => current_time('mysql'),
            'job_id' => $jobId,
            'settings_saved' => true,
        ], false);
        if ($jobId > 0) {
            wp_schedule_single_event(time() + 1, Scheduler::HOOK_RETRY_JOB, [$jobId]);
        }

        self::redirect($jobId > 0 ? '自動初期設定を保存し、AIサイト戦略の作成を開始しました。進捗ゲージで状態を確認できます。' : '自動初期設定を保存しました。AIサイト戦略ジョブは作成できませんでした。');
    }

    public static function resetArticlePlan(): void
    {
        self::guard('dsap_reset_article_plan');
        self::clearArticlePlan();
        self::redirect('記事計画とAIサイト戦略をリセットしました。設定を直してから自動初期設定を実行すると、新しい計画を作り直します。');
    }

    public static function generateStrategy(): void
    {
        self::guard('dsap_generate_strategy');
        $settings = Settings::get();
        if ((string) $settings['site_theme'] === '' || (string) $settings['conversion_goal'] === '') {
            self::redirect('先に「サイトテーマ・商材」と「CV目標」を設定してください。');
        }
        $jobId = Scheduler::queueStrategyJob('manual');
        if ($jobId > 0) {
            wp_schedule_single_event(time() + 1, Scheduler::HOOK_RETRY_JOB, [$jobId]);
        }
        self::redirect($jobId > 0 ? 'サイト戦略の作成を開始しました。' : '戦略ジョブを作成できませんでした。');
    }

    public static function retryJob(): void
    {
        self::guard('dsap_retry_job');
        $jobId = absint($_POST['job_id'] ?? 0);
        if ($jobId > 0) {
            wp_schedule_single_event(time() + 1, Scheduler::HOOK_RETRY_JOB, [$jobId]);
        }
        self::redirect('再実行を予約しました。');
    }

    public static function deleteApiKey(): void
    {
        self::guard('dsap_delete_api_key');
        $settings = Settings::get();
        $settings['openai_api_key'] = '';
        update_option(Settings::OPTION, $settings, false);
        self::redirect('APIキーを削除しました。');
    }

    public static function gscConnect(): void
    {
        self::guard('dsap_gsc_connect');
        $url = GoogleOAuth::authorizationUrl();
        if (is_wp_error($url)) {
            self::redirect($url->get_error_message());
        }
        wp_redirect($url);
        exit;
    }

    public static function gscCallback(): void
    {
        self::requirePermission();
        if (!empty($_GET['error'])) {
            self::redirect('Google接続がキャンセルされました: ' . sanitize_text_field((string) wp_unslash($_GET['error'])));
        }
        $result = GoogleOAuth::handleCallback(
            sanitize_text_field((string) wp_unslash($_GET['code'] ?? '')),
            sanitize_text_field((string) wp_unslash($_GET['state'] ?? ''))
        );
        if (is_wp_error($result)) {
            self::redirect($result->get_error_message());
        }
        Scheduler::reschedulePdca(Settings::get());
        $sites = (new SearchConsoleClient())->listSites();
        if (!is_wp_error($sites)) {
            update_option('dsap_gsc_sites', $sites, false);
        }
        self::redirect('Google Search Consoleに接続しました。過去データは「データ同期」で取得できます。');
    }

    public static function gscDisconnect(): void
    {
        self::guard('dsap_gsc_disconnect');
        GoogleOAuth::disconnect();
        delete_option('dsap_gsc_sites');
        Scheduler::reschedulePdca(Settings::get());
        self::redirect('Google Search Consoleとの接続を解除しました。保存済み設定は残しています。');
    }

    public static function gscSyncNow(): void
    {
        self::guard('dsap_gsc_sync_now');
        $result = (new SearchConsoleClient())->backfill(59);
        $sites = (new SearchConsoleClient())->listSites();
        if (!is_wp_error($sites)) {
            update_option('dsap_gsc_sites', $sites, false);
        }
        self::redirect(is_wp_error($result) ? $result->get_error_message() : '過去59日分を同期しました。保存行数: ' . (int) $result);
    }

    public static function refreshCandidates(): void
    {
        self::guard('dsap_refresh_candidates');
        $count = (new RefreshSelector())->queueCandidates('manual');
        Scheduler::scheduleQueuedRefreshJobs();
        self::redirect($count > 0 ? $count . '件の改善ジョブを開始しました。' : '現在の条件に該当する改善候補はありません。');
    }

    public static function refreshPost(): void
    {
        self::guard('dsap_refresh_post');
        $jobId = (new RefreshSelector())->queuePost(absint($_POST['post_id'] ?? 0), 'manual');
        if ($jobId > 0) {
            wp_schedule_single_event(time() + 1, Scheduler::HOOK_RETRY_JOB, [$jobId]);
        }
        self::redirect($jobId > 0 ? '指定記事の改善ジョブを開始しました。' : '改善ジョブを作成できませんでした。同じ週に実行済みか、対象記事を確認してください。');
    }

    public static function applyRefreshDraft(): void
    {
        self::guard('dsap_apply_refresh_draft');
        $result = (new RefreshPublisher())->applyDraft(absint($_POST['job_id'] ?? 0));
        self::redirect(is_wp_error($result) ? $result->get_error_message() : '改善案を元記事へ反映しました。反映前の内容はリビジョンに保存されています。');
    }

    public static function discardRefreshDraft(): void
    {
        self::guard('dsap_discard_refresh_draft');
        $result = (new RefreshPublisher())->discardDraft(absint($_POST['job_id'] ?? 0));
        self::redirect(is_wp_error($result) ? $result->get_error_message() : '改善案を破棄しました。');
    }

    public static function importGoogleOAuth(): void
    {
        self::guard('dsap_import_google_oauth');
        $file = $_FILES['google_oauth_json'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > 65536) {
            self::redirect('Google OAuth JSONを読み込めませんでした。64KB以下のJSONファイルを選択してください。');
        }
        $raw = file_get_contents((string) $file['tmp_name']);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        $client = is_array($json) && is_array($json['web'] ?? null) ? $json['web'] : null;
        if (!is_array($client) || empty($client['client_id']) || empty($client['client_secret'])) {
            self::redirect('ウェブアプリ用OAuthクライアントのJSONではありません。Google Cloudで種類を「ウェブアプリケーション」にしてください。');
        }
        $settings = Settings::get();
        $settings['gsc_client_id'] = sanitize_text_field((string) $client['client_id']);
        $settings['gsc_client_secret'] = sanitize_text_field((string) $client['client_secret']);
        update_option(Settings::OPTION, $settings, false);
        self::redirect('OAuthクライアントJSONを読み込みました。次はGoogleへ接続してください。');
    }

    public static function saveGscProperty(): void
    {
        self::guard('dsap_save_gsc_property');
        $property = sanitize_text_field((string) wp_unslash($_POST['gsc_site_url'] ?? ''));
        $sites = get_option('dsap_gsc_sites', []);
        $allowed = is_array($sites) ? array_column($sites, 'siteUrl') : [];
        $validManual = preg_match('#^(https?://.+/|sc-domain:[a-z0-9.-]+)$#i', $property) === 1;
        if ($property === '' || ($allowed !== [] && !in_array($property, $allowed, true)) || ($allowed === [] && !$validManual)) {
            self::redirect('接続済みアカウントから取得したSearch Consoleプロパティを選択してください。');
        }
        $settings = Settings::get();
        if ((string) $settings['gsc_site_url'] !== '' && (string) $settings['gsc_site_url'] !== $property) {
            global $wpdb;
            $wpdb->query('DELETE FROM ' . Database::table('metrics_daily'));
            delete_option('dsap_gsc_last_sync');
        }
        $settings['gsc_site_url'] = $property;
        update_option(Settings::OPTION, $settings, false);
        Scheduler::reschedulePdca($settings);
        self::redirect('Search Consoleプロパティを保存しました。');
    }

    public static function enablePdca(): void
    {
        self::guard('dsap_enable_pdca');
        if (!GoogleOAuth::connected() || (string) Settings::get()['gsc_site_url'] === '') {
            self::redirect('Google接続とSearch Consoleプロパティの選択を先に完了してください。');
        }
        $settings = Settings::get();
        $settings['gsc_enabled'] = true;
        $settings['refresh_enabled'] = true;
        update_option(Settings::OPTION, $settings, false);
        Scheduler::reschedulePdca($settings);
        self::redirect('Search Console日次同期とPDCA自動実行を有効にしました。');
    }

    public static function checkGitHubUpdates(): void
    {
        self::guard('dsap_check_github_updates');
        delete_transient(GitHubUpdater::CACHE_KEY);
        $release = (new GitHubUpdater())->release();
        if (is_wp_error($release)) {
            self::redirect($release->get_error_message());
        }
        wp_clean_plugins_cache(true);
        wp_update_plugins();
        $message = version_compare((string) $release['version'], DSAP_VERSION, '>')
            ? '新しいバージョン ' . $release['version'] . ' を検出しました。続けて「GitHubから今すぐ更新」を押してください。'
            : '最新版です。現在: ' . DSAP_VERSION;
        self::redirect($message);
    }

    public static function installGitHubUpdate(): void
    {
        self::guard('dsap_install_github_update');
        $permissionError = self::updatePermissionError();
        if ($permissionError !== '') {
            self::redirect($permissionError);
        }

        delete_transient(GitHubUpdater::CACHE_KEY);
        $release = (new GitHubUpdater())->release();
        if (is_wp_error($release)) {
            self::redirect($release->get_error_message());
        }
        if (version_compare((string) $release['version'], DSAP_VERSION, '<=')) {
            self::redirect('最新版です。現在: ' . DSAP_VERSION);
        }

        wp_clean_plugins_cache(true);
        wp_update_plugins();

        $plugin = plugin_basename(DSAP_FILE);
        $updates = get_site_transient('update_plugins');
        if (!is_object($updates) || empty($updates->response[$plugin])) {
            self::redirect('GitHub更新情報をWordPressへ登録できませんでした。「GitHub更新を確認」を押してから、もう一度実行してください。');
        }

        $updateUrl = add_query_arg(
            [
                'action' => 'upgrade-plugin',
                'plugin' => $plugin,
                '_wpnonce' => wp_create_nonce('upgrade-plugin_' . $plugin),
            ],
            self_admin_url('update.php')
        );
        wp_safe_redirect($updateUrl);
        exit;
    }

    private static function modelSelect(string $key, string $current): void
    {
        echo '<select name="' . esc_attr(Settings::OPTION) . '[' . esc_attr($key) . ']">';
        foreach (Settings::models() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    private static function qualitySelect(string $current, string $name = 'article_quality'): void
    {
        echo '<select name="' . esc_attr($name) . '">';
        foreach (Settings::qualityProfiles() as $value => $profile) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html((string) $profile['label']) . '（目安 ' . esc_html((string) $profile['min_words']) . '字以上 / 監査 ' . esc_html((string) $profile['audit_score']) . '点 / 再執筆 最大' . esc_html((string) ($profile['max_revisions'] ?? 1)) . '回）</option>';
        }
        echo '</select>';
    }

    private static function keywordStrategySelect(string $current, string $name = 'keyword_strategy'): void
    {
        echo '<select name="' . esc_attr($name) . '">';
        foreach (Settings::keywordStrategies() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    private static function gscSiteField(string $current, array $sites): void
    {
        if ($sites === []) {
            echo '<input id="dsap-gsc-site" name="' . esc_attr(Settings::OPTION) . '[gsc_site_url]" value="' . esc_attr($current) . '" class="regular-text" placeholder="https://example.com/ または sc-domain:example.com">';
            return;
        }
        echo '<select id="dsap-gsc-site" name="' . esc_attr(Settings::OPTION) . '[gsc_site_url]">';
        echo '<option value="">選択してください</option>';
        foreach ($sites as $site) {
            $url = is_array($site) ? (string) ($site['siteUrl'] ?? '') : '';
            if ($url !== '') {
                echo '<option value="' . esc_attr($url) . '" ' . selected($current, $url, false) . '>' . esc_html($url) . '</option>';
            }
        }
        if ($current !== '' && !in_array($current, array_column($sites, 'siteUrl'), true)) {
            echo '<option value="' . esc_attr($current) . '" selected>' . esc_html($current) . '</option>';
        }
        echo '</select>';
    }

    private static function gscSetupWizard(array $settings, bool $connected, array $sites, array $lastSync): void
    {
        static $instance = 0;
        $instance++;
        $redirectId = 'dsap-redirect-uri-' . (string) $instance;
        $copyId = 'dsap-copy-redirect-' . (string) $instance;
        $hasCredentials = (string) $settings['gsc_client_id'] !== '' && (string) $settings['gsc_client_secret'] !== '';
        $hasProperty = (string) $settings['gsc_site_url'] !== '';
        $hasSync = !empty($lastSync['synced_at']) && empty($lastSync['error']);
        $enabled = !empty($settings['gsc_enabled']) && !empty($settings['refresh_enabled']);
        $steps = [
            ['OAuth設定', $hasCredentials],
            ['Google接続', $connected],
            ['プロパティ選択', $hasProperty],
            ['初回データ同期', $hasSync],
            ['自動改善', $enabled],
        ];
        echo '<div class="dsap-setup-progress">';
        foreach ($steps as [$label, $done]) {
            echo '<span class="' . ($done ? 'is-done' : '') . '">' . esc_html($done ? '完了: ' . $label : $label) . '</span>';
        }
        echo '</div>';

        if (!$hasCredentials) {
            echo '<div class="dsap-setup-step"><h3>1. Google CloudでOAuthを作る</h3>';
            echo '<p><a class="button" target="_blank" rel="noopener noreferrer" href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com">Search Console APIを有効化</a> <a class="button" target="_blank" rel="noopener noreferrer" href="https://console.cloud.google.com/auth/overview">同意画面を設定</a> <a class="button" target="_blank" rel="noopener noreferrer" href="https://console.cloud.google.com/apis/credentials">OAuthクライアントを作成</a></p>';
            echo '<p>種類は「ウェブアプリケーション」。承認済みリダイレクトURIには次を登録します。</p>';
            echo '<ol class="dsap-cycle"><li>「OAuthクライアントを作成」を開きます。</li><li>アプリケーションの種類で「ウェブアプリケーション」を選びます。</li><li>名前は「Daily SEO AI Publisher」など分かる名前にします。</li><li>「承認済みのリダイレクトURI」で「URIを追加」を押し、下のURLを貼り付けます。</li><li>「作成」を押し、JSONをダウンロードします。</li><li>ダウンロードしたJSONをこの画面のファイル選択から読み込みます。</li></ol>';
            echo '<div class="dsap-copy-row"><code id="' . esc_attr($redirectId) . '">' . esc_html(GoogleOAuth::redirectUri()) . '</code><button type="button" class="button" id="' . esc_attr($copyId) . '">コピー</button></div>';
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('dsap_import_google_oauth');
            echo '<input type="hidden" name="action" value="dsap_import_google_oauth"><label><strong>GoogleからダウンロードしたJSON:</strong> <input type="file" name="google_oauth_json" accept="application/json,.json" required></label> ';
            submit_button('JSONを読み込む', 'primary', 'submit', false);
            echo '</form></div>';
        } elseif (!$connected) {
            echo '<div class="dsap-setup-step"><h3>2. Googleアカウントへ接続</h3><p>Search Consoleを管理しているGoogleアカウントで許可します。</p>';
            self::actionForm('dsap_gsc_connect', 'Google Search Consoleに接続', 'primary');
            echo '</div>';
        } elseif (!$hasProperty) {
            echo '<div class="dsap-setup-step"><h3>3. 計測するサイトを選ぶ</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('dsap_save_gsc_property');
            echo '<input type="hidden" name="action" value="dsap_save_gsc_property">';
            self::gscSiteField('', $sites);
            submit_button('プロパティを保存', 'primary', 'submit', false);
            echo '</form></div>';
        } elseif (!$hasSync) {
            echo '<div class="dsap-setup-step"><h3>4. 比較用データを取得</h3><p>直近28日と前28日を比較できるよう、過去59日分を取得します。</p>';
            self::actionForm('dsap_gsc_sync_now', '59日分を初回同期', 'primary');
            echo '</div>';
        } elseif (!$enabled) {
            echo '<div class="dsap-setup-step"><h3>5. 自動PDCAを開始</h3><p>日次同期と改善候補の自動判定を有効にします。</p>';
            self::actionForm('dsap_enable_pdca', '自動PDCAを有効にする', 'primary');
            echo '</div>';
        } else {
            echo '<div class="dsap-setup-complete"><strong>セットアップ完了</strong><span>Search Console取得と改善判定が自動実行されます。</span></div>';
        }
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var b=document.getElementById("' . esc_js($copyId) . '"),c=document.getElementById("' . esc_js($redirectId) . '");if(b&&c){b.addEventListener("click",function(){navigator.clipboard.writeText(c.textContent||"");b.textContent="コピー済み";});}});</script>';
    }

    private static function clearArticlePlan(): void
    {
        global $wpdb;

        delete_option('dsap_strategy_plan');
        delete_option('dsap_auto_setup_status');
        $wpdb->query('DELETE FROM ' . Database::table('topics'));
        $wpdb->query(
            "UPDATE " . Database::table('jobs') . " SET status = 'failed_permanent', error_message = 'Reset by user.', updated_at = '" . esc_sql(current_time('mysql')) . "' WHERE job_type = 'site_strategy' AND status IN ('queued', 'running', 'failed_retryable')"
        );
    }

    private static function autoSetupState(array $settings, bool $hasKey, array $strategy): array
    {
        $status = get_option('dsap_auto_setup_status', []);
        $status = is_array($status) ? $status : [];
        $jobId = (int) ($status['job_id'] ?? 0);
        $job = $jobId > 0 ? (new JobRepository())->find($jobId) : null;
        $hasStrategy = is_array($strategy['plan'] ?? null);
        $settingsSaved = !empty($status['settings_saved']);
        $basicReady = $hasKey
            && trim((string) ($settings['site_theme'] ?? '')) !== ''
            && trim((string) ($settings['target_audience'] ?? '')) !== ''
            && trim((string) ($settings['conversion_goal'] ?? '')) !== ''
            && !empty($settings['daily_enabled']);

        $steps = [
            ['label' => 'APIキー確認', 'status' => $hasKey ? 'done' : 'current'],
            ['label' => '基本設定保存', 'status' => 'pending'],
            ['label' => 'AI戦略ジョブ作成', 'status' => 'pending'],
            ['label' => 'AI戦略作成', 'status' => 'pending'],
            ['label' => '記事計画準備', 'status' => 'pending'],
        ];

        if (!$hasKey) {
            return [
                'progress' => 0,
                'label' => 'APIキー待ち',
                'current' => '設定タブでOpenAI APIキーを保存してください。',
                'steps' => $steps,
            ];
        }

        if ($hasStrategy || (is_array($job) && (string) ($job['status'] ?? '') === 'complete')) {
            foreach ($steps as $index => $step) {
                $steps[$index]['status'] = 'done';
            }
            return [
                'progress' => 100,
                'label' => '初期設定完了',
                'current' => 'AIサイト戦略と記事計画の準備が完了しました。',
                'steps' => $steps,
            ];
        }

        $steps[0]['status'] = 'done';
        if (!$settingsSaved && !$basicReady) {
            $steps[1]['status'] = 'current';
            return [
                'progress' => 15,
                'label' => '実行待ち',
                'current' => '自動初期設定ボタンを押すと、空欄の基本設定をAI運用向けに補完します。',
                'steps' => $steps,
            ];
        }

        $steps[1]['status'] = 'done';
        if (!is_array($job)) {
            $steps[2]['status'] = $jobId > 0 ? 'error' : 'current';
            return [
                'progress' => $jobId > 0 ? 55 : 45,
                'label' => $jobId > 0 ? 'ジョブ確認中' : '基本設定保存済み',
                'current' => $jobId > 0
                    ? '作成済みのAI戦略ジョブを確認できません。もう一度自動初期設定を実行してください。'
                    : '基本設定は保存済みです。次にAI戦略ジョブを作成します。',
                'steps' => $steps,
            ];
        }

        $steps[2]['status'] = 'done';
        $jobStatus = (string) ($job['status'] ?? '');
        if (in_array($jobStatus, ['failed_retryable', 'failed_permanent'], true)) {
            $steps[3]['status'] = 'error';
            return [
                'progress' => 65,
                'label' => 'AI戦略で要確認',
                'current' => (string) ($job['error_message'] ?? 'AI戦略ジョブでエラーが発生しました。パイプライン進捗から再実行できます。'),
                'steps' => $steps,
            ];
        }

        $steps[3]['status'] = 'current';
        return [
            'progress' => $jobStatus === 'running' ? 80 : 65,
            'label' => $jobStatus === 'running' ? 'AI戦略作成中' : 'AI戦略待機中',
            'current' => $jobStatus === 'running'
                ? 'AIが集客記事、CV記事、内部リンク、アフィリエイト導線を設計しています。'
                : 'WordPress CronでAI戦略作成を開始します。少し待ってから画面を更新してください。',
            'steps' => $steps,
        ];
    }

    private static function autoSetupProgress(array $state): void
    {
        $progress = max(0, min(100, (int) ($state['progress'] ?? 0)));
        $steps = is_array($state['steps'] ?? null) ? $state['steps'] : [];

        echo '<div class="dsap-setup-meter" aria-label="初期設定進捗" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr((string) $progress) . '"><span style="width:' . esc_attr((string) $progress) . '%"></span></div>';
        echo '<p class="dsap-setup-current"><strong>' . esc_html((string) ($state['label'] ?? '確認中')) . '</strong><span>' . esc_html((string) ($state['current'] ?? '初期設定の状態を確認しています。')) . '</span></p>';
        echo '<ol class="dsap-setup-steps">';
        foreach ($steps as $step) {
            $stepStatus = in_array((string) ($step['status'] ?? 'pending'), ['done', 'current', 'error', 'pending'], true) ? (string) $step['status'] : 'pending';
            echo '<li class="is-' . esc_attr($stepStatus) . '">' . esc_html((string) ($step['label'] ?? '')) . '</li>';
        }
        echo '</ol>';
    }

    private static function jobsTable(array $jobs): void
    {
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>種類</th><th>進捗</th><th>状態</th><th>投稿</th><th>品質 / エラー</th><th></th></tr></thead><tbody>';
        if ($jobs === []) {
            echo '<tr><td colspan="7">まだジョブはありません。</td></tr>';
        }
        foreach ($jobs as $job) {
            $progress = self::progress((string) $job['stage'], (string) $job['job_type']);
            $post = !empty($job['post_id']) ? '<a href="' . esc_url(get_edit_post_link((int) $job['post_id'])) . '">編集</a>' : '-';
            $typeLabel = ['site_strategy' => '戦略', 'refresh' => '改善', 'new_article' => '新規記事'][(string) $job['job_type']] ?? (string) $job['job_type'];
            echo '<tr><td>' . esc_html((string) $job['id']) . '</td><td>' . esc_html($typeLabel) . '</td>';
            echo '<td><div class="dsap-progress"><span style="width:' . esc_attr((string) $progress) . '%"></span></div><small>' . esc_html(self::stageLabel((string) $job['stage'])) . ' ' . esc_html((string) $progress) . '%</small></td>';
            $detail = trim((string) ($job['error_message'] ?? ''));
            if ($detail === '') {
                $detail = self::jobQualityDetail($job);
            }
            echo '<td><span class="dsap-status dsap-status-' . esc_attr((string) $job['status']) . '">' . esc_html((string) $job['status']) . '</span></td><td>' . $post . '</td><td class="dsap-error">' . esc_html($detail) . '</td>';
            echo '<td><div class="dsap-row-actions">';
            $isReviewDraft = ($job['job_type'] ?? '') === 'refresh' && ($job['status'] ?? '') === 'complete' && !empty($job['post_id']) && (int) $job['post_id'] !== (int) $job['target_post_id'] && get_post_status((int) $job['post_id']) === 'draft';
            if ($isReviewDraft) {
                self::compactJobAction('dsap_apply_refresh_draft', '適用', (int) $job['id'], 'primary small');
                self::compactJobAction('dsap_discard_refresh_draft', '破棄', (int) $job['id'], 'delete small');
            } elseif (!in_array((string) $job['status'], ['complete', 'running'], true)) {
                self::compactJobAction('dsap_retry_job', '再実行', (int) $job['id'], 'secondary small');
            } else {
                echo '-';
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function topicsTable(array $topics): void
    {
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>状態</th><th>タイプ</th><th>役割</th><th>キーワード</th><th>クラスター</th><th>誘導先</th><th>最終実行</th></tr></thead><tbody>';
        if ($topics === []) {
            echo '<tr><td colspan="8">サイト戦略を作ると、AIが記事計画を登録します。</td></tr>';
        }
        foreach ($topics as $topic) {
            echo '<tr><td>' . esc_html((string) $topic['id']) . '</td><td>' . esc_html((string) ($topic['status'] ?? '')) . '</td><td>' . esc_html(($topic['article_type'] ?? '') === 'cv' ? 'CV' : '集客') . '</td><td>' . esc_html((string) ($topic['content_role'] ?? '')) . '</td><td>' . esc_html((string) $topic['keyword']) . '</td><td>' . esc_html((string) ($topic['cluster_name'] ?? '')) . '</td><td>' . esc_html((string) ($topic['target_keyword'] ?? $topic['anchor_text'] ?? '')) . '</td><td>' . esc_html((string) ($topic['last_job_at'] ?? '-')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function strategySummary($strategy): void
    {
        if (!is_array($strategy) || !is_array($strategy['plan'] ?? null)) {
            echo '<p class="description">まだ戦略は作成されていません。</p>';
            return;
        }
        $plan = $strategy['plan'];
        $diagnostics = is_array($strategy['diagnostics'] ?? null) ? $strategy['diagnostics'] : [];
        $metrics = is_array($diagnostics['metrics'] ?? null) ? $diagnostics['metrics'] : [];
        echo '<div class="dsap-strategy-summary"><h3>現在の戦略</h3><p>' . esc_html((string) ($plan['strategy_summary'] ?? '')) . '</p><p><strong>商材分析:</strong> ' . esc_html((string) ($plan['offer_analysis'] ?? '')) . '</p><p><strong>狙う顧客:</strong> ' . esc_html((string) ($plan['ideal_customer_profile'] ?? '')) . '</p><p><strong>勝ち筋:</strong> ' . esc_html((string) ($plan['positioning'] ?? '')) . '</p><p><strong>導線:</strong> ' . esc_html((string) ($plan['funnel_summary'] ?? '')) . '</p>';
        if ($metrics !== []) {
            echo '<p><strong>戦略品質:</strong> ' . esc_html((string) ($diagnostics['score'] ?? 0)) . '点 / 記事 ' . esc_html((string) ($metrics['articles'] ?? 0)) . '件 / クラスター ' . esc_html((string) ($metrics['clusters'] ?? 0)) . '件 / ロングテール ' . esc_html((string) round((float) ($metrics['long_tail_ratio'] ?? 0) * 100)) . '%</p>';
        }
        foreach ((array) ($diagnostics['warnings'] ?? []) as $warning) {
            echo '<p class="description">注意: ' . esc_html((string) $warning) . '</p>';
        }
        echo '<p><small>作成: ' . esc_html((string) ($strategy['created_at'] ?? '')) . ' / 生成 ' . esc_html((string) ($strategy['generation_attempts'] ?? 1)) . '回</small></p></div>';
    }

    private static function metricsTable(): void
    {
        $repo = new MetricsRepository();
        $ids = array_slice($repo->postIds(50), 0, 20);
        echo '<table class="widefat striped"><thead><tr><th>記事</th><th>検索クリック</th><th>CTAクリック / PV</th><th>表示回数</th><th>CTR</th><th>平均順位</th><th>前期間比</th><th></th></tr></thead><tbody>';
        if ($ids === []) {
            echo '<tr><td colspan="8">まだSearch Consoleデータがありません。</td></tr>';
        }
        foreach ($ids as $postId) {
            $comparison = $repo->comparison($postId);
            $current = $comparison['current'];
            $previous = $comparison['previous'];
            $deltaClicks = (float) $current['clicks'] - (float) $previous['clicks'];
            $deltaPosition = (float) $current['position'] - (float) $previous['position'];
            $eventType = (string) get_post_meta($postId, '_dsap_article_type', true) === 'cv' ? 'affiliate_click' : 'internal_cta_click';
            $ctaClicks = (int) ($comparison['current_cta'][$eventType] ?? 0);
            $pageViews = (int) ($comparison['current_cta']['page_view'] ?? 0);
            $ctaRate = $pageViews > 0 ? $ctaClicks / $pageViews : 0;
            echo '<tr><td><a href="' . esc_url(get_edit_post_link($postId)) . '">' . esc_html(get_the_title($postId)) . '</a></td>';
            $ctaDisplay = $pageViews > 0 ? number_format_i18n($ctaClicks, 0) . ' / ' . number_format_i18n($pageViews, 0) . 'PV (' . number_format_i18n($ctaRate * 100, 1) . '%)' : number_format_i18n($ctaClicks, 0);
            echo '<td>' . esc_html(number_format_i18n((float) $current['clicks'], 0)) . '</td><td>' . esc_html($ctaDisplay) . '</td><td>' . esc_html(number_format_i18n((float) $current['impressions'], 0)) . '</td><td>' . esc_html(number_format_i18n((float) $current['ctr'] * 100, 2) . '%') . '</td><td>' . esc_html(number_format_i18n((float) $current['position'], 1)) . '</td>';
            echo '<td>クリック ' . esc_html(($deltaClicks >= 0 ? '+' : '') . number_format_i18n($deltaClicks, 0)) . ' / 順位 ' . esc_html(($deltaPosition >= 0 ? '+' : '') . number_format_i18n($deltaPosition, 1)) . '</td>';
            echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('dsap_refresh_post');
            echo '<input type="hidden" name="action" value="dsap_refresh_post"><input type="hidden" name="post_id" value="' . esc_attr((string) $postId) . '">';
            submit_button('この記事を改善', 'secondary small', 'submit', false);
            echo '</form></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function progress(string $stage, string $jobType): int
    {
        if ($jobType === 'site_strategy') {
            return $stage === 'complete' ? 100 : 50;
        }
        if ($jobType === 'refresh') {
            return ['refresh_plan' => 20, 'refresh_draft' => 50, 'refresh_audit' => 75, 'refresh_apply' => 90, 'complete' => 100][$stage] ?? 0;
        }
        return ['research' => 15, 'draft' => 40, 'audit' => 65, 'revise' => 82, 'publish' => 95, 'complete' => 100][$stage] ?? 0;
    }

    private static function jobQualityDetail(array $job): string
    {
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        if (!is_array($payload)) {
            return '';
        }
        if (($job['job_type'] ?? '') === 'site_strategy') {
            $diagnostics = is_array($payload['strategy_diagnostics'] ?? null) ? $payload['strategy_diagnostics'] : [];
            return $diagnostics !== [] ? '戦略品質 ' . (string) ($diagnostics['score'] ?? 0) . '点' : '';
        }
        $parts = [];
        $metrics = is_array($payload['quality_diagnostics']['metrics'] ?? null) ? $payload['quality_diagnostics']['metrics'] : [];
        if ($metrics !== []) {
            $parts[] = (string) ($metrics['text_characters'] ?? 0) . '字';
            $parts[] = 'H2 ' . (string) ($metrics['h2_count'] ?? 0);
        }
        if (is_array($payload['publish_decision'] ?? null)) {
            $parts[] = '品質 ' . (string) ($payload['publish_decision']['score'] ?? 0) . '点';
        }
        if (!empty($payload['revision_count'])) {
            $parts[] = '再執筆 ' . (string) $payload['revision_count'] . '回';
        }
        return implode(' / ', $parts);
    }

    private static function stageLabel(string $stage): string
    {
        return ['strategy' => '戦略作成', 'research' => 'リサーチ', 'draft' => '執筆・機械検査', 'audit' => 'AI監査', 'revise' => '品質改善の再執筆', 'publish' => '投稿', 'refresh_plan' => '改善診断', 'refresh_draft' => 'リライト', 'refresh_audit' => '改善監査', 'refresh_apply' => '反映', 'complete' => '完了'][$stage] ?? $stage;
    }

    private static function stat(string $label, string $value): void
    {
        echo '<div class="dsap-stat"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong></div>';
    }

    private static function actionForm(string $action, string $label, string $class): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        submit_button($label, $class, 'submit', false);
        echo '</form>';
    }

    private static function compactJobAction(string $action, string $label, int $jobId, string $class): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="job_id" value="' . esc_attr((string) $jobId) . '">';
        submit_button($label, $class, 'submit', false);
        echo '</form>';
    }

    private static function notice(): void
    {
        if (!isset($_GET['dsap_notice'])) {
            return;
        }
        echo '<div class="notice notice-info is-dismissible"><p>' . esc_html((string) wp_unslash($_GET['dsap_notice'])) . '</p></div>';
    }

    private static function guard(string $nonceAction): void
    {
        self::requirePermission();
        check_admin_referer($nonceAction);
    }

    private static function requirePermission(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission.', 'daily-seo-ai-publisher'));
        }
    }

    private static function updatePermissionError(): string
    {
        if (!wp_is_file_mod_allowed('capability_update_core')) {
            return 'WordPressまたはサーバーの設定でプラグインファイルの変更が禁止されています。wp-config.php の DISALLOW_FILE_MODS、またはホスティング側の更新制限を確認してください。';
        }
        if (is_multisite() && !is_super_admin()) {
            return 'マルチサイトではネットワーク管理者だけがプラグインを更新できます。ネットワーク管理者でログインしてください。';
        }
        if (!current_user_can('update_plugins')) {
            return '現在のユーザーに update_plugins 権限がありません。WordPress管理者でログインするか、ユーザー権限を確認してください。';
        }
        return '';
    }

    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg(['page' => 'dsap', 'dsap_notice' => $notice], admin_url('admin.php')));
        exit;
    }
}
