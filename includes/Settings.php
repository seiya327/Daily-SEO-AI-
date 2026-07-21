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
            'nvidia_api_key' => '',
            'nvidia_fallback_enabled' => true,
            'nvidia_model' => 'nvidia/llama-3.3-nemotron-super-49b-v1',
            'model_research' => 'gpt-5.6-terra',
            'model_audit' => 'gpt-5.6-luna',
            'model_refresh' => 'gpt-5.6-terra',
            'article_quality' => 'high',
            'article_image_provider' => 'openverse',
            'ai_images_enabled' => false,
            'keyword_strategy' => 'longtail',
            'post_status' => 'publish',
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
            'ga4_property_id' => '',
            'ga4_enabled' => false,
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
            'publish_default_migrated' => false,
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

    public static function nvidiaModels(): array
    {
        return [
            'nvidia/llama-3.3-nemotron-super-49b-v1' => 'NVIDIA Nemotron Super 49B',
            'meta/llama-3.1-405b-instruct' => 'Llama 3.1 405B Instruct',
            'deepseek-ai/deepseek-r1' => 'DeepSeek R1（NVIDIA提供時）',
            'deepseek-ai/deepseek-v3' => 'DeepSeek V3（NVIDIA提供時）',
            'deepseek-ai/deepseek-r1-distill-llama-70b' => 'DeepSeek R1 Distill Llama 70B（NVIDIA提供時）',
            'zai-org/glm-4.5' => 'GLM-4.5（NVIDIA提供時）',
            'zai-org/glm-4.5-air' => 'GLM-4.5 Air（NVIDIA提供時）',
        ];
    }

    public static function preferredNvidiaModels(): array
    {
        return [
            'nvidia/llama-3.3-nemotron-super-49b-v1' => 'NVIDIA Nemotron Super 49B',
            'meta/llama-3.1-405b-instruct' => 'Llama 3.1 405B Instruct',
            'deepseek-ai/deepseek-r1' => 'DeepSeek R1',
            'deepseek-ai/deepseek-v3' => 'DeepSeek V3',
            'zai-org/glm-4.5' => 'GLM-4.5',
        ];
    }

    public static function imageProviders(): array
    {
        return [
            'openverse' => '無料素材を自動取得（推奨）',
            'openai' => 'OpenAIで生成（有料）',
            'none' => '実画像を使わない',
        ];
    }

    public static function qualityProfiles(): array
    {
        return [
            'standard' => [
                'label' => '標準',
                'min_words' => 1200,
                'max_words' => 3000,
                'audit_score' => 80,
                'max_revisions' => 1,
                'model_research' => 'gpt-5.6-luna',
                'model_audit' => 'gpt-5.6-luna',
                'instruction' => '読みやすさとSEO基本要件を満たす。一般論だけで終わらせず、見出しごとに要点、理由、具体例を入れる。',
            ],
            'high' => [
                'label' => '高品質',
                'min_words' => 1800,
                'max_words' => 4500,
                'audit_score' => 85,
                'max_revisions' => 2,
                'model_research' => 'gpt-5.6-terra',
                'model_audit' => 'gpt-5.6-luna',
                'instruction' => '専門家が監修したような実用記事にする。手順、判断基準、比較、失敗例、注意点、読者が次に取る行動を必ず入れる。薄い要約、同じ意味の繰り返し、根拠のない断定は禁止。',
            ],
            'premium' => [
                'label' => 'かなり高品質',
                'min_words' => 2600,
                'max_words' => 6500,
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
            . 'Normal length range: about ' . (string) $profile['min_words'] . ' to ' . (string) ($profile['max_words'] ?? 4500) . " Japanese characters, but use fewer when the query is fully answered. Never pad to reach the range.\n"
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
        $merged = self::normalizeModels(array_merge(self::defaults(), $current));
        if (!array_key_exists('article_image_provider', $current)) {
            $merged['article_image_provider'] = !empty($current['ai_images_enabled']) ? 'openai' : 'openverse';
        }
        if (empty($merged['publish_default_migrated']) && (string) ($merged['post_status'] ?? '') === 'draft') {
            $merged['post_status'] = 'publish';
        }
        $merged['publish_default_migrated'] = true;
        update_option(self::OPTION, $merged, false);
    }

    public static function get(): array
    {
        $settings = get_option(self::OPTION, []);
        $merged = self::normalizeModels(array_merge(self::defaults(), is_array($settings) ? $settings : []));
        if (is_array($settings) && !array_key_exists('article_image_provider', $settings)) {
            $merged['article_image_provider'] = !empty($settings['ai_images_enabled']) ? 'openai' : 'openverse';
        }
        $merged['ai_images_enabled'] = ($merged['article_image_provider'] ?? '') === 'openai';
        if (empty($merged['publish_default_migrated']) && (string) ($merged['post_status'] ?? '') === 'draft') {
            $merged['post_status'] = 'publish';
            $merged['publish_default_migrated'] = true;
            update_option(self::OPTION, $merged, false);
        }
        return $merged;
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
        if (trim((string) ($settings['nvidia_model'] ?? '')) === '') {
            $settings['nvidia_model'] = 'nvidia/llama-3.3-nemotron-super-49b-v1';
        }
        if (!array_key_exists((string) ($settings['article_image_provider'] ?? ''), self::imageProviders())) {
            $settings['article_image_provider'] = 'openverse';
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

    public static function nvidiaApiKey(): string
    {
        $env = getenv('DSAP_NVIDIA_API_KEY');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $settings = self::get();
        return is_string($settings['nvidia_api_key']) ? $settings['nvidia_api_key'] : '';
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
        $incomingNvidiaKey = isset($input['nvidia_api_key']) ? trim((string) wp_unslash($input['nvidia_api_key'])) : '';

        if (!empty($input['delete_openai_api_key'])) {
            $next['openai_api_key'] = '';
        } elseif ($incomingKey !== '') {
            $next['openai_api_key'] = $incomingKey;
        } else {
            $next['openai_api_key'] = (string) ($old['openai_api_key'] ?? '');
        }
        if (!empty($input['delete_nvidia_api_key'])) {
            $next['nvidia_api_key'] = '';
        } elseif ($incomingNvidiaKey !== '') {
            $next['nvidia_api_key'] = $incomingNvidiaKey;
        } else {
            $next['nvidia_api_key'] = (string) ($old['nvidia_api_key'] ?? '');
        }
        $next['nvidia_fallback_enabled'] = !empty($input['nvidia_fallback_enabled']);
        $preset = sanitize_text_field((string) ($input['nvidia_model_preset'] ?? ''));
        $custom = sanitize_text_field((string) ($input['nvidia_model_custom'] ?? ''));
        $legacy = sanitize_text_field((string) ($input['nvidia_model'] ?? ''));
        $next['nvidia_model'] = $custom !== '' ? $custom : ($preset !== '' ? $preset : ($legacy !== '' ? $legacy : 'nvidia/llama-3.3-nemotron-super-49b-v1'));
        if ($next['nvidia_model'] === '') {
            $next['nvidia_model'] = 'nvidia/llama-3.3-nemotron-super-49b-v1';
        }

        $models = array_keys(self::models());
        $qualityProfiles = array_keys(self::qualityProfiles());
        $next['article_quality'] = in_array(($input['article_quality'] ?? ''), $qualityProfiles, true) ? (string) $input['article_quality'] : 'high';
        $imageProvider = sanitize_key((string) ($input['article_image_provider'] ?? 'openverse'));
        $next['article_image_provider'] = array_key_exists($imageProvider, self::imageProviders()) ? $imageProvider : 'openverse';
        $next['ai_images_enabled'] = $next['article_image_provider'] === 'openai';
        $keywordStrategies = array_keys(self::keywordStrategies());
        $next['keyword_strategy'] = in_array(($input['keyword_strategy'] ?? ''), $keywordStrategies, true) ? (string) $input['keyword_strategy'] : 'longtail';
        $next['model_research'] = in_array(($input['model_research'] ?? ''), $models, true) ? (string) $input['model_research'] : 'gpt-5.6-terra';
        $next['model_audit'] = in_array(($input['model_audit'] ?? ''), $models, true) ? (string) $input['model_audit'] : 'gpt-5.6-luna';
        $next['model_refresh'] = in_array(($input['model_refresh'] ?? ''), $models, true) ? (string) $input['model_refresh'] : 'gpt-5.6-terra';
        $next['post_status'] = in_array(($input['post_status'] ?? ''), ['draft', 'pending', 'publish'], true) ? (string) $input['post_status'] : 'publish';
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
        $next['ga4_property_id'] = preg_replace('/\D+/', '', (string) ($input['ga4_property_id'] ?? '')) ?: '';
        $next['ga4_enabled'] = !empty($input['ga4_enabled']);
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
        $next['publish_default_migrated'] = true;

        Scheduler::rescheduleDaily($next);
        Scheduler::reschedulePdca($next);
        delete_transient(GitHubUpdater::CACHE_KEY);
        return $next;
    }
}
