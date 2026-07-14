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
        ?>
        <div class="wrap dsap-wrap">
            <h1>Daily SEO AI Publisher</h1>
            <?php self::notice(); ?>

            <nav class="nav-tab-wrapper dsap-tabs">
                <a class="nav-tab nav-tab-active" href="#dsap-dashboard">運用</a>
                <a class="nav-tab" href="#dsap-strategy">戦略</a>
                <a class="nav-tab" href="#dsap-pdca">改善</a>
                <a class="nav-tab" href="#dsap-settings">設定</a>
            </nav>

            <section id="dsap-dashboard" class="dsap-section is-active">
                <div class="dsap-hero">
                    <div>
                        <h2>AIに任せて、毎日のSEO運用を回す</h2>
                        <p>最初は商材・読者・誘導先だけ入れれば動きます。細かいモデルや比率はAI標準設定で始められます。</p>
                    </div>
                    <div class="dsap-actions">
                        <?php self::actionForm('dsap_generate_strategy', 'AIで戦略を作る', 'primary'); ?>
                        <?php self::actionForm('dsap_test_run', 'テスト実行', 'secondary'); ?>
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
                                <tr><th>リサーチ・執筆モデル</th><td><?php self::modelSelect('model_research', (string) $settings['model_research']); ?></td></tr>
                                <tr><th>監査モデル</th><td><?php self::modelSelect('model_audit', (string) $settings['model_audit']); ?></td></tr>
                                <tr><th>改善モデル</th><td><?php self::modelSelect('model_refresh', (string) $settings['model_refresh']); ?></td></tr>
                                <tr><th><label for="dsap-anchor-default">CTA文言</label></th><td><input id="dsap-anchor-default" name="<?php echo esc_attr(Settings::OPTION); ?>[affiliate_anchor]" value="<?php echo esc_attr((string) $settings['affiliate_anchor']); ?>" class="regular-text"></td></tr>
                                <tr><th><label for="dsap-disclosure">広告表記</label></th><td><input id="dsap-disclosure" name="<?php echo esc_attr(Settings::OPTION); ?>[affiliate_disclosure]" value="<?php echo esc_attr((string) $settings['affiliate_disclosure']); ?>" class="large-text"></td></tr>
                                <tr><th><label for="dsap-ratio">集客記事の割合</label></th><td><input id="dsap-ratio" type="number" min="0" max="100" step="10" name="<?php echo esc_attr(Settings::OPTION); ?>[attraction_ratio]" value="<?php echo esc_attr((string) $settings['attraction_ratio']); ?>"> %</td></tr>
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
                    </div>
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
            ? '新しいバージョン ' . $release['version'] . ' を検出しました。WordPressのプラグイン更新画面から更新できます。'
            : '最新版です。現在: ' . DSAP_VERSION;
        self::redirect($message);
    }

    private static function modelSelect(string $key, string $current): void
    {
        echo '<select name="' . esc_attr(Settings::OPTION) . '[' . esc_attr($key) . ']">';
        foreach (Settings::models() as $value => $label) {
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
        $hasCredentials = (string) $settings['gsc_client_id'] !== '' && (string) $settings['gsc_client_secret'] !== '';
        $hasProperty = (string) $settings['gsc_site_url'] !== '';
        $hasSync = !empty($lastSync['synced_at']) && empty($lastSync['error']);
        $enabled = !empty($settings['gsc_enabled']) && !empty($settings['refresh_enabled']);
        $steps = [
            ['OAuth設定', $hasCredentials],
            ['Google接続', $connected],
            ['プロパティ選択', $hasProperty],
            ['初回データ同期', $hasSync],
            ['自動PDCA', $enabled],
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
            echo '<div class="dsap-copy-row"><code id="dsap-redirect-uri">' . esc_html(GoogleOAuth::redirectUri()) . '</code><button type="button" class="button" id="dsap-copy-redirect">コピー</button></div>';
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
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var b=document.getElementById("dsap-copy-redirect"),c=document.getElementById("dsap-redirect-uri");if(b&&c){b.addEventListener("click",function(){navigator.clipboard.writeText(c.textContent||"");b.textContent="コピー済み";});}});</script>';
    }

    private static function jobsTable(array $jobs): void
    {
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>種類</th><th>進捗</th><th>状態</th><th>投稿</th><th>エラー</th><th></th></tr></thead><tbody>';
        if ($jobs === []) {
            echo '<tr><td colspan="7">まだジョブはありません。</td></tr>';
        }
        foreach ($jobs as $job) {
            $progress = self::progress((string) $job['stage'], (string) $job['job_type']);
            $post = !empty($job['post_id']) ? '<a href="' . esc_url(get_edit_post_link((int) $job['post_id'])) . '">編集</a>' : '-';
            $typeLabel = ['site_strategy' => '戦略', 'refresh' => '改善', 'new_article' => '新規記事'][(string) $job['job_type']] ?? (string) $job['job_type'];
            echo '<tr><td>' . esc_html((string) $job['id']) . '</td><td>' . esc_html($typeLabel) . '</td>';
            echo '<td><div class="dsap-progress"><span style="width:' . esc_attr((string) $progress) . '%"></span></div><small>' . esc_html(self::stageLabel((string) $job['stage'])) . ' ' . esc_html((string) $progress) . '%</small></td>';
            echo '<td><span class="dsap-status dsap-status-' . esc_attr((string) $job['status']) . '">' . esc_html((string) $job['status']) . '</span></td><td>' . $post . '</td><td class="dsap-error">' . esc_html((string) ($job['error_message'] ?? '')) . '</td>';
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
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>タイプ</th><th>キーワード</th><th>クラスター</th><th>誘導</th><th>最終実行</th></tr></thead><tbody>';
        if ($topics === []) {
            echo '<tr><td colspan="6">サイト戦略を作ると、AIが記事計画を登録します。</td></tr>';
        }
        foreach ($topics as $topic) {
            echo '<tr><td>' . esc_html((string) $topic['id']) . '</td><td>' . esc_html(($topic['article_type'] ?? '') === 'cv' ? 'CV' : '集客') . '</td><td>' . esc_html((string) $topic['keyword']) . '</td><td>' . esc_html((string) ($topic['cluster_name'] ?? '')) . '</td><td>' . esc_html((string) ($topic['anchor_text'] ?? '')) . '</td><td>' . esc_html((string) ($topic['last_job_at'] ?? '-')) . '</td></tr>';
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
        echo '<div class="dsap-strategy-summary"><h3>現在の戦略</h3><p>' . esc_html((string) ($plan['strategy_summary'] ?? '')) . '</p><p><strong>導線:</strong> ' . esc_html((string) ($plan['funnel_summary'] ?? '')) . '</p><p><small>作成: ' . esc_html((string) ($strategy['created_at'] ?? '')) . '</small></p></div>';
    }

    private static function metricsTable(): void
    {
        $repo = new MetricsRepository();
        $ids = array_slice($repo->postIds(50), 0, 20);
        echo '<table class="widefat striped"><thead><tr><th>記事</th><th>クリック</th><th>表示回数</th><th>CTR</th><th>平均順位</th><th>前期間比</th><th></th></tr></thead><tbody>';
        if ($ids === []) {
            echo '<tr><td colspan="7">まだSearch Consoleデータがありません。</td></tr>';
        }
        foreach ($ids as $postId) {
            $comparison = $repo->comparison($postId);
            $current = $comparison['current'];
            $previous = $comparison['previous'];
            $deltaClicks = (float) $current['clicks'] - (float) $previous['clicks'];
            $deltaPosition = (float) $current['position'] - (float) $previous['position'];
            echo '<tr><td><a href="' . esc_url(get_edit_post_link($postId)) . '">' . esc_html(get_the_title($postId)) . '</a></td>';
            echo '<td>' . esc_html(number_format_i18n((float) $current['clicks'], 0)) . '</td><td>' . esc_html(number_format_i18n((float) $current['impressions'], 0)) . '</td><td>' . esc_html(number_format_i18n((float) $current['ctr'] * 100, 2) . '%') . '</td><td>' . esc_html(number_format_i18n((float) $current['position'], 1)) . '</td>';
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
        return ['research' => 15, 'draft' => 45, 'audit' => 75, 'publish' => 90, 'complete' => 100][$stage] ?? 0;
    }

    private static function stageLabel(string $stage): string
    {
        return ['strategy' => '戦略作成', 'research' => 'リサーチ', 'draft' => '執筆', 'audit' => '監査', 'publish' => '投稿', 'refresh_plan' => '改善診断', 'refresh_draft' => 'リライト', 'refresh_audit' => '改善監査', 'refresh_apply' => '反映', 'complete' => '完了'][$stage] ?? $stage;
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

    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg(['page' => 'dsap', 'dsap_notice' => $notice], admin_url('admin.php')));
        exit;
    }
}
