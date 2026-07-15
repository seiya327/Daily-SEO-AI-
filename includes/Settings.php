<?php

declare(strict_types=1);

namespace DSAP;

final class Settings
{
    public const OPTION = 'dsap_settings';

    public static function defaults(): array
    {
        return [
            'openai_api_key' => '',
            'model_research' => 'gpt-5.6-terra',
            'model_audit' => 'gpt-5.6-luna',
            'model_refresh' => 'gpt-5.6-terra',
            'article_quality' => 'high',
            'keyword_strategy' => 'longtail',
            'post_status' => 'draft',
            'daily_enabled' => false,
            'daily_time' => '09:00',
            'max_daily_new_articles' => 1,
            'attraction_ratio' => 70,
            'site_theme' => '',
            'target_audience' => '',
            'conversion_goal' => '',
            'affiliate_url' => '',
            'affiliate_anchor' => '公式サイトで詳細を見る',
            'affiliate_disclosure' => '本記事には広告・アフィリエイトリンクが含まれます。',
            'strategy_instructions' => '',
            'gsc_client_id' => '',
            'gsc_client_secret' => '',
            'gsc_site_url' => '',
            'gsc_enabled' => false,
            'gsc_sync_time' => '03:00',
            'refresh_enabled' => false,
            'max_daily_refreshes' => 1,
            'refresh_min_impressions' => 100,
            'refresh_cooldown_days' => 28,
            'refresh_auto_apply' => false,
            'refresh_instructions' => '',
            'github_updates_enabled' => true,
            'github_auto_update' => false,
            'github_token' => '',
            'global_instructions' => '',
            'mock_mode' => true,
            'delete_data_on_uninstall' => false,
        ];
    }

    public static function models(): array
    {
        return [
            'gpt-5.6-luna' => 'GPT-5.6 Luna',
            'gpt-5.6-terra' => 'GPT-5.6 Terra',
            'gpt-5-mini' => 'GPT-5 mini',
            'gpt-5.4-mini' => 'GPT-5.4 mini',
            'gpt-5-nano' => 'GPT-5 nano',
        ];
    }

    public static function qualityProfiles(): array
    {
        return [
            'standard' => [
                'label' => '標準',
                'min_words' => 1800,
                'audit_score' => 80,
                'max_revisions' => 1,
                'model_research' => 'gpt-5.6-luna',
                'model_audit' => 'gpt-5.6-luna',
                'instruction' => '読みやすさとSEO基本要件を満たす。一般論だけで終わらせず、見出しごとに要点、理由、具体例を入れる。',
            ],
            'high' => [
                'label' => '高品質',
                'min_words' => 3000,
                'audit_score' => 85,
                'max_revisions' => 2,
                'model_research' => 'gpt-5.6-terra',
                'model_audit' => 'gpt-5.6-luna',
                'instruction' => '専門家が監修したような実用記事にする。手順、判断基準、比較、失敗例、注意点、読者が次に取る行動を必ず入れる。薄い要約、同じ意味の繰り返し、根拠のない断定は禁止。',
            ],
            'premium' => [
                'label' => 'かなり高品質',
                'min_words' => 4500,
                'audit_score' => 90,
                'max_revisions' => 2,
                'model_research' => 'gpt-5.6-terra',
                'model_audit' => 'gpt-5.6-terra',
                'instruction' => '検索上位を狙う柱記事として作る。読者の前提知識、比較表に相当する観点、具体的な選び方、ケース別の結論、よくある誤解、内部リンク前提、CVへの自然な導線まで設計する。独自性のない文章、抽象論、水増しは禁止。',
            ],
        ];
    }

    public static function qualityProfile(?string $quality = null): array
    {
        $profiles = self::qualityProfiles();
        $quality = $quality ?: (string) self::get()['article_quality'];
        return $profiles[$quality] ?? $profiles['high'];
    }

    public static function qualityInstruction(?string $quality = null): string
    {
        $profile = self::qualityProfile($quality);
        return 'Article quality preset: ' . (string) $profile['label'] . "\n"
            . 'Minimum target length: about ' . (string) $profile['min_words'] . " Japanese characters or more when the topic can support it.\n"
            . 'Required quality rule: ' . (string) $profile['instruction'];
    }

    public static function keywordStrategies(): array
    {
        return [
            'balanced' => 'バランス型',
            'longtail' => 'ロングテール重視',
            'unexpected' => '意外な流入重視',
        ];
    }

    public static function boot(): void
    {
        add_action('admin_init', [self::class, 'register']);
    }

    public static function ensureDefaults(): void
    {
        $current = get_option(self::OPTION);
        if (!is_array($current)) {
            add_option(self::OPTION, self::defaults(), '', false);
            return;
        }
        update_option(self::OPTION, self::normalizeModels(array_merge(self::defaults(), $current)), false);
    }

    public static function get(): array
    {
        $settings = get_option(self::OPTION, []);
        return self::normalizeModels(array_merge(self::defaults(), is_array($settings) ? $settings : []));
    }

    private static function normalizeModels(array $settings): array
    {
        $allowed = array_keys(self::models());
        $fallbacks = [
            'model_research' => 'gpt-5.6-terra',
            'model_audit' => 'gpt-5.6-luna',
            'model_refresh' => 'gpt-5.6-terra',
        ];

        foreach ($fallbacks as $key => $fallback) {
            if (!in_array((string) ($settings[$key] ?? ''), $allowed, true)) {
                $settings[$key] = $fallback;
            }
        }

        return $settings;
    }

    public static function apiKey(): string
    {
        $env = getenv('DSAP_OPENAI_API_KEY');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $settings = self::get();
        return is_string($settings['openai_api_key']) ? $settings['openai_api_key'] : '';
    }

    public static function register(): void
    {
        register_setting('dsap_settings_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => self::defaults(),
        ]);
    }

    public static function sanitize($input): array
    {
        $old = self::get();
        $input = is_array($input) ? $input : [];
        $next = self::defaults();
        $incomingKey = isset($input['openai_api_key']) ? trim((string) wp_unslash($input['openai_api_key'])) : '';

        if (!empty($input['delete_openai_api_key'])) {
            $next['openai_api_key'] = '';
        } elseif ($incomingKey !== '') {
            $next['openai_api_key'] = $incomingKey;
        } else {
            $next['openai_api_key'] = (string) ($old['openai_api_key'] ?? '');
        }

        $models = array_keys(self::models());
        $qualityProfiles = array_keys(self::qualityProfiles());
        $next['article_quality'] = in_array(($input['article_quality'] ?? ''), $qualityProfiles, true) ? (string) $input['article_quality'] : 'high';
        $keywordStrategies = array_keys(self::keywordStrategies());
        $next['keyword_strategy'] = in_array(($input['keyword_strategy'] ?? ''), $keywordStrategies, true) ? (string) $input['keyword_strategy'] : 'longtail';
        $next['model_research'] = in_array(($input['model_research'] ?? ''), $models, true) ? (string) $input['model_research'] : 'gpt-5.6-terra';
        $next['model_audit'] = in_array(($input['model_audit'] ?? ''), $models, true) ? (string) $input['model_audit'] : 'gpt-5.6-luna';
        $next['model_refresh'] = in_array(($input['model_refresh'] ?? ''), $models, true) ? (string) $input['model_refresh'] : 'gpt-5.6-terra';
        $next['post_status'] = in_array(($input['post_status'] ?? ''), ['draft', 'pending', 'publish'], true) ? (string) $input['post_status'] : 'draft';
        $next['daily_enabled'] = !empty($input['daily_enabled']);
        $next['daily_time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($input['daily_time'] ?? '')) ? (string) $input['daily_time'] : '09:00';
        $next['max_daily_new_articles'] = max(1, min(10, absint($input['max_daily_new_articles'] ?? 1)));
        $next['attraction_ratio'] = max(0, min(100, absint($input['attraction_ratio'] ?? 70)));
        $next['site_theme'] = sanitize_text_field((string) ($input['site_theme'] ?? ''));
        $next['target_audience'] = sanitize_textarea_field((string) ($input['target_audience'] ?? ''));
        $next['conversion_goal'] = sanitize_textarea_field((string) ($input['conversion_goal'] ?? ''));
        $next['affiliate_url'] = esc_url_raw((string) ($input['affiliate_url'] ?? ''));
        $next['affiliate_anchor'] = sanitize_text_field((string) ($input['affiliate_anchor'] ?? ''));
        $next['affiliate_disclosure'] = sanitize_text_field((string) ($input['affiliate_disclosure'] ?? ''));
        $next['strategy_instructions'] = sanitize_textarea_field((string) ($input['strategy_instructions'] ?? ''));
        $next['gsc_client_id'] = sanitize_text_field((string) ($input['gsc_client_id'] ?? ''));
        $incomingGoogleSecret = isset($input['gsc_client_secret']) ? trim((string) wp_unslash($input['gsc_client_secret'])) : '';
        if (!empty($input['delete_gsc_client_secret'])) {
            $next['gsc_client_secret'] = '';
        } elseif ($incomingGoogleSecret !== '') {
            $next['gsc_client_secret'] = $incomingGoogleSecret;
        } else {
            $next['gsc_client_secret'] = (string) ($old['gsc_client_secret'] ?? '');
        }
        $next['gsc_site_url'] = sanitize_text_field((string) ($input['gsc_site_url'] ?? ''));
        $next['gsc_enabled'] = !empty($input['gsc_enabled']);
        $next['gsc_sync_time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($input['gsc_sync_time'] ?? '')) ? (string) $input['gsc_sync_time'] : '03:00';
        $next['refresh_enabled'] = !empty($input['refresh_enabled']);
        $next['max_daily_refreshes'] = max(0, min(5, absint($input['max_daily_refreshes'] ?? 1)));
        $next['refresh_min_impressions'] = max(10, min(1000000, absint($input['refresh_min_impressions'] ?? 100)));
        $next['refresh_cooldown_days'] = max(14, min(180, absint($input['refresh_cooldown_days'] ?? 28)));
        $next['refresh_auto_apply'] = !empty($input['refresh_auto_apply']);
        $next['refresh_instructions'] = sanitize_textarea_field((string) ($input['refresh_instructions'] ?? ''));
        $next['github_updates_enabled'] = !empty($input['github_updates_enabled']);
        $next['github_auto_update'] = !empty($input['github_auto_update']);
        $incomingGithubToken = isset($input['github_token']) ? trim((string) wp_unslash($input['github_token'])) : '';
        if (!empty($input['delete_github_token'])) {
            $next['github_token'] = '';
        } elseif ($incomingGithubToken !== '') {
            $next['github_token'] = sanitize_text_field($incomingGithubToken);
        } else {
            $next['github_token'] = (string) ($old['github_token'] ?? '');
        }
        $next['global_instructions'] = sanitize_textarea_field((string) ($input['global_instructions'] ?? ''));
        $next['mock_mode'] = !empty($input['mock_mode']);
        $next['delete_data_on_uninstall'] = !empty($input['delete_data_on_uninstall']);

        Scheduler::rescheduleDaily($next);
        Scheduler::reschedulePdca($next);
        delete_transient(GitHubUpdater::CACHE_KEY);
        return $next;
    }
}
