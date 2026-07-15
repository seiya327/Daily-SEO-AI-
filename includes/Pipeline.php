<?php

declare(strict_types=1);

namespace DSAP;

final class Pipeline
{
    private AiClientInterface $client;

    public function __construct(AiClientInterface $client)
    {
        $this->client = $client;
    }

    public function run(int $jobId): void
    {
        $repo = new JobRepository();
        $job = $repo->acquire($jobId);
        if (!$job) {
            return;
        }

        try {
            match ((string) $job['stage']) {
                'strategy' => $this->strategy($job),
                'refresh_plan' => $this->refreshPlan($job),
                'refresh_draft' => $this->refreshDraft($job),
                'refresh_audit' => $this->refreshAudit($job),
                'refresh_apply' => $this->refreshApply($job),
                'research' => $this->research($job),
                'draft' => $this->draft($job),
                'audit' => $this->audit($job),
                'revise' => $this->revise($job),
                'publish' => $this->publish($job),
                default => $repo->fail($jobId, 'Unknown stage.', true),
            };
        } catch (\Throwable $error) {
            $repo->fail($jobId, $error->getMessage(), false);
        }
    }

    private function refreshPlan(array $job): void
    {
        $repo = new JobRepository();
        $post = $this->targetPost($job);
        if (is_wp_error($post)) {
            $repo->fail((int) $job['id'], $post->get_error_message(), true);
            return;
        }
        $payload = $this->payload($job);
        $result = $this->client->respond('refresh_plan_v1', Contracts::schema('refresh_plan_v1'), PromptBuilder::refreshPlan($job, $post, $payload), false, (string) Settings::get()['model_refresh']);
        if (is_wp_error($result)) {
            $repo->fail((int) $job['id'], $result->get_error_message(), $this->isPermanent($result));
            return;
        }
        $payload['refresh_plan'] = $result['data'] ?? [];
        $payload['usage']['refresh_plan'] = $result['usage'] ?? [];
        $repo->savePayload((int) $job['id'], $payload);
        if (empty($payload['refresh_plan']['should_refresh'])) {
            delete_post_meta($post->ID, '_dsap_refresh_pending_job_id');
            $repo->complete((int) $job['id'], $post->ID);
            return;
        }
        $repo->advance((int) $job['id'], 'refresh_draft');
        Scheduler::scheduleNextStage((int) $job['id']);
    }

    private function refreshDraft(array $job): void
    {
        $repo = new JobRepository();
        $post = $this->targetPost($job);
        if (is_wp_error($post)) {
            $repo->fail((int) $job['id'], $post->get_error_message(), true);
            return;
        }
        $payload = $this->payload($job);
        $webSearch = !empty($payload['refresh_plan']['requires_web_research']);
        $result = $this->client->respond('refresh_article_v1', Contracts::schema('refresh_article_v1'), PromptBuilder::refreshArticle($job, $post, $payload), $webSearch, (string) Settings::get()['model_refresh']);
        if (is_wp_error($result)) {
            $repo->fail((int) $job['id'], $result->get_error_message(), $this->isPermanent($result));
            return;
        }
        $article = is_array($result['data'] ?? null) ? $result['data'] : [];
        $article['content_html'] = wp_kses_post((string) ($article['content_html'] ?? ''));
        $sources = is_array($result['sources'] ?? null) ? $result['sources'] : [];
        $hardError = QualityGate::hardChecksRefresh($article);
        if ($hardError === '') {
            $hardError = SourceValidator::validateRefresh($article, $sources, $webSearch);
        }
        if ($hardError !== '') {
            $repo->fail((int) $job['id'], $hardError, true);
            return;
        }
        $payload['refresh_article'] = $article;
        $payload['refresh_sources'] = $sources;
        $payload['usage']['refresh_draft'] = $result['usage'] ?? [];
        $repo->savePayload((int) $job['id'], $payload);
        $repo->advance((int) $job['id'], 'refresh_audit');
        Scheduler::scheduleNextStage((int) $job['id']);
    }

    private function refreshAudit(array $job): void
    {
        $repo = new JobRepository();
        $post = $this->targetPost($job);
        if (is_wp_error($post)) {
            $repo->fail((int) $job['id'], $post->get_error_message(), true);
            return;
        }
        $payload = $this->payload($job);
        $result = $this->client->respond('audit_v1', Contracts::schema('audit_v1'), PromptBuilder::refreshAudit($job, $post, $payload), false, (string) Settings::get()['model_audit']);
        if (is_wp_error($result)) {
            $repo->fail((int) $job['id'], $result->get_error_message(), $this->isPermanent($result));
            return;
        }
        $payload['audit'] = $result['data'] ?? [];
        $payload['usage']['refresh_audit'] = $result['usage'] ?? [];
        $repo->savePayload((int) $job['id'], $payload);
        $repo->advance((int) $job['id'], 'refresh_apply');
        Scheduler::scheduleNextStage((int) $job['id']);
    }

    private function refreshApply(array $job): void
    {
        $payload = $this->payload($job);
        $postId = (new RefreshPublisher())->apply($job, $payload);
        if (is_wp_error($postId)) {
            (new JobRepository())->fail((int) $job['id'], $postId->get_error_message(), false);
            return;
        }
        (new JobRepository())->complete((int) $job['id'], (int) $postId);
    }

    private function strategy(array $job): void
    {
        $repo = new JobRepository();
        $settings = Settings::get();
        $siteContext = SiteContext::forStrategy();
        $payload = $this->payload($job);
        $plan = is_array($payload['strategy'] ?? null) ? $payload['strategy'] : [];
        $diagnostics = is_array($payload['strategy_diagnostics'] ?? null) ? $payload['strategy_diagnostics'] : [];
        $sources = is_array($payload['strategy_sources'] ?? null) ? $payload['strategy_sources'] : [];
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        $attempts = max(
            0,
            (int) ($payload['strategy_generation_attempts'] ?? 0),
            isset($usage['strategy_repair']) ? 2 : ($plan !== [] ? 1 : 0)
        );
        $state = is_array($payload['strategy_generation'] ?? null) ? $payload['strategy_generation'] : [];

        if ($plan !== [] && !empty($diagnostics['passed'])) {
            $this->completeStrategy($job, $settings, $plan, $diagnostics, $sources, $usage, max(1, $attempts), $payload);
            return;
        }
        if ($plan !== [] && $attempts >= 2) {
            $message = implode(' / ', array_slice(is_array($diagnostics['errors'] ?? null) ? $diagnostics['errors'] : ['戦略品質基準を満たせませんでした。'], 0, 5));
            $repo->fail((int) $job['id'], $message, true);
            return;
        }

        $phase = $plan !== [] && $attempts >= 1 ? 'repair' : 'initial';
        $responseId = (string) (($state['phase'] ?? '') === $phase ? ($state['response_id'] ?? '') : '');
        $prompt = $phase === 'repair'
            ? PromptBuilder::strategyRepair($job, $plan, $diagnostics, $siteContext)
            : PromptBuilder::strategy($job, $siteContext);
        $result = $this->client->respond(
            'strategy_v1',
            Contracts::schema('strategy_v1'),
            $prompt,
            true,
            (string) $settings['model_research'],
            true,
            $responseId
        );
        if (is_wp_error($result)) {
            if (in_array($result->get_error_code(), ['dsap_openai_response_missing', 'dsap_openai_output_limit'], true)) {
                $state['response_id'] = '';
                $state['status'] = $result->get_error_code() === 'dsap_openai_output_limit' ? 'output_limit' : 'expired';
                $state['updated_at'] = current_time('mysql');
                $payload['strategy_generation'] = $state;
                $repo->savePayload((int) $job['id'], $payload);
            }
            $repo->fail((int) $job['id'], $result->get_error_message(), $this->isPermanent($result));
            return;
        }

        if (!empty($result['pending'])) {
            $state = [
                'phase' => $phase,
                'response_id' => sanitize_text_field((string) ($result['response_id'] ?? '')),
                'status' => sanitize_key((string) ($result['status'] ?? 'queued')),
                'poll_count' => max(0, (int) ($state['poll_count'] ?? 0)) + 1,
                'started_at' => (string) ($state['started_at'] ?? current_time('mysql')),
                'updated_at' => current_time('mysql'),
            ];
            $payload['strategy_generation'] = $state;
            $payload['strategy_generation_attempts'] = $attempts;
            $repo->savePayload((int) $job['id'], $payload);
            $repo->advance((int) $job['id'], 'strategy');
            Scheduler::scheduleNextStage((int) $job['id'], 15);
            return;
        }

        $plan = is_array($result['data'] ?? null) ? $result['data'] : [];
        $diagnostics = StrategyGate::inspect($plan, $settings, $siteContext);
        $attempts = $phase === 'repair' ? 2 : 1;
        $usage[$phase === 'repair' ? 'strategy_repair' : 'strategy'] = $result['usage'] ?? [];
        $sources = array_values(array_unique(array_merge($sources, is_array($result['sources'] ?? null) ? $result['sources'] : [])));
        $payload['strategy'] = $plan;
        $payload['strategy_diagnostics'] = $diagnostics;
        $payload['strategy_sources'] = $sources;
        $payload['strategy_generation_attempts'] = $attempts;
        $payload['usage'] = $usage;
        $payload['strategy_generation'] = [
            'phase' => $phase,
            'response_id' => '',
            'status' => 'completed',
            'poll_count' => max(0, (int) ($state['poll_count'] ?? 0)),
            'started_at' => (string) ($state['started_at'] ?? current_time('mysql')),
            'updated_at' => current_time('mysql'),
        ];
        $repo->savePayload((int) $job['id'], $payload);

        if (empty($diagnostics['passed'])) {
            if ($attempts < 2) {
                $payload['strategy_generation'] = [
                    'phase' => 'repair',
                    'response_id' => '',
                    'status' => 'queued',
                    'poll_count' => 0,
                    'started_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ];
                $repo->savePayload((int) $job['id'], $payload);
                $repo->advance((int) $job['id'], 'strategy');
                Scheduler::scheduleNextStage((int) $job['id']);
                return;
            }
            $message = implode(' / ', array_slice(is_array($diagnostics['errors'] ?? null) ? $diagnostics['errors'] : ['戦略品質基準を満たせませんでした。'], 0, 5));
            $repo->fail((int) $job['id'], $message, true);
            return;
        }

        $this->completeStrategy($job, $settings, $plan, $diagnostics, $sources, $usage, $attempts, $payload);
    }

    private function completeStrategy(array $job, array $settings, array $plan, array $diagnostics, array $sources, array $usage, int $attempts, array $payload): void
    {
        $articles = is_array($plan['articles'] ?? null) ? $plan['articles'] : [];
        $topicRepo = new TopicRepository();
        foreach ($articles as $article) {
            if (!is_array($article) || empty($article['keyword'])) {
                continue;
            }
            $keyword = sanitize_text_field((string) $article['keyword']);
            if ($keyword === '' || $topicRepo->existsByKeyword($keyword)) {
                continue;
            }
            $articleType = ($article['article_type'] ?? '') === 'cv' ? 'cv' : 'attraction';
            $configuredAffiliate = esc_url_raw((string) $settings['affiliate_url']);
            $targetUrl = $articleType === 'cv' ? $configuredAffiliate : '';
            $topicRepo->create(
                $keyword,
                self::topicInstructions($article),
                $articleType,
                sanitize_text_field((string) ($article['cluster_name'] ?? '')),
                $targetUrl,
                sanitize_text_field((string) ($article['anchor_text'] ?? '')),
                absint($article['priority'] ?? 50),
                $article
            );
        }

        update_option('dsap_strategy_plan', [
            'plan' => $plan,
            'created_at' => current_time('mysql'),
            'job_id' => (int) $job['id'],
            'diagnostics' => $diagnostics,
            'research_sources' => $sources,
            'generation_attempts' => $attempts,
        ], false);
        $payload['strategy'] = $plan;
        $payload['strategy_diagnostics'] = $diagnostics;
        $payload['strategy_sources'] = $sources;
        $payload['strategy_generation_attempts'] = $attempts;
        $payload['usage'] = $usage;
        $payload['strategy_generation'] = array_merge(
            is_array($payload['strategy_generation'] ?? null) ? $payload['strategy_generation'] : [],
            ['response_id' => '', 'status' => 'completed', 'updated_at' => current_time('mysql')]
        );
        $repo = new JobRepository();
        $repo->savePayload((int) $job['id'], $payload);
        $repo->complete((int) $job['id'], 0);
    }

    private function research(array $job): void
    {
        $repo = new JobRepository();
        $topic = (new TopicRepository())->find((int) $job['topic_id']);
        if (!$topic) {
            $repo->fail((int) $job['id'], 'Topic not found.', true);
            return;
        }

        $prompt = PromptBuilder::research($topic, $job);
        $result = $this->client->respond('research_v1', Contracts::schema('research_v1'), $prompt, true, (string) Settings::get()['model_research']);
        if (is_wp_error($result)) {
            $repo->fail((int) $job['id'], $result->get_error_message(), $this->isPermanent($result));
            return;
        }

        $payload = $this->payload($job);
        $snapshot = json_decode((string) ($job['instruction_snapshot'] ?? ''), true);
        $payload['test_mode'] = is_array($snapshot) && ($snapshot['trigger'] ?? '') === 'test';
        $payload['funnel'] = [
            'article_type' => $topic['article_type'] ?? 'attraction',
            'cluster_name' => $topic['cluster_name'] ?? '',
            'content_role' => $topic['content_role'] ?? '',
            'reader_stage' => $topic['reader_stage'] ?? '',
            'target_keyword' => $topic['target_keyword'] ?? '',
            'entry_angle' => $topic['entry_angle'] ?? '',
            'conversion_bridge' => $topic['conversion_bridge'] ?? '',
            'target_url' => $topic['target_url'] ?? '',
            'anchor_text' => $topic['anchor_text'] ?? '',
        ];
        $payload['internal_link_candidates'] = SiteContext::internalLinkCandidates((string) ($topic['cluster_name'] ?? ''));
        $payload['research'] = $result['data'] ?? [];
        $payload['api_sources'] = $result['sources'] ?? [];
        $payload['usage']['research'] = $result['usage'] ?? [];

        $sourceError = SourceValidator::validateResearch($payload['research'], $payload['api_sources']);
        if ($sourceError !== '') {
            $repo->fail((int) $job['id'], $sourceError, true);
            return;
        }

        $repo->savePayload((int) $job['id'], $payload);
        $repo->advance((int) $job['id'], 'draft');
        Scheduler::scheduleNextStage((int) $job['id']);
    }

    private function draft(array $job): void
    {
        $repo = new JobRepository();
        $payload = $this->payload($job);
        $prompt = PromptBuilder::article($payload, $job);
        $result = $this->client->respond('article_v1', Contracts::schema('article_v1'), $prompt, false, (string) Settings::get()['model_research']);
        if (is_wp_error($result)) {
            $repo->fail((int) $job['id'], $result->get_error_message(), $this->isPermanent($result));
            return;
        }

        $article = $result['data'] ?? [];
        $article['content_html'] = wp_kses_post((string) ($article['content_html'] ?? ''));
        $payload['article'] = $article;
        $payload['usage']['draft'] = $result['usage'] ?? [];
        $payload['quality_diagnostics'] = QualityGate::diagnostics($payload);

        if (empty($payload['quality_diagnostics']['passed'])) {
            $repair = $this->client->respond(
                'article_v1',
                Contracts::schema('article_v1'),
                PromptBuilder::repairArticle($payload, $job, $payload['quality_diagnostics']),
                false,
                (string) Settings::get()['model_research']
            );
            if (is_wp_error($repair)) {
                $repo->fail((int) $job['id'], $repair->get_error_message(), $this->isPermanent($repair));
                return;
            }
            $article = is_array($repair['data'] ?? null) ? $repair['data'] : [];
            $article['content_html'] = wp_kses_post((string) ($article['content_html'] ?? ''));
            $payload['article'] = $article;
            $payload['usage']['draft_repair'] = $repair['usage'] ?? [];
            $payload['quality_diagnostics'] = QualityGate::diagnostics($payload);
        }
        if (empty($payload['quality_diagnostics']['passed'])) {
            $message = implode(' / ', array_slice($payload['quality_diagnostics']['errors'] ?? ['記事品質基準を満たせませんでした。'], 0, 5));
            $repo->savePayload((int) $job['id'], $payload);
            $repo->fail((int) $job['id'], $message, true);
            return;
        }

        $repo->savePayload((int) $job['id'], $payload);
        $repo->advance((int) $job['id'], 'audit');
        Scheduler::scheduleNextStage((int) $job['id']);
    }

    private function audit(array $job): void
    {
        $repo = new JobRepository();
        $payload = $this->payload($job);
        $prompt = PromptBuilder::audit($payload, $job);
        $result = $this->client->respond('audit_v1', Contracts::schema('audit_v1'), $prompt, false, (string) Settings::get()['model_audit']);
        if (is_wp_error($result)) {
            $repo->fail((int) $job['id'], $result->get_error_message(), $this->isPermanent($result));
            return;
        }

        $payload['audit'] = $result['data'] ?? [];
        $revisionCount = max(0, (int) ($payload['revision_count'] ?? 0));
        $payload['usage']['audit_' . $revisionCount] = $result['usage'] ?? [];
        $payload['publish_decision'] = QualityGate::decision($payload, Settings::get());

        $repo->savePayload((int) $job['id'], $payload);
        $profile = Settings::qualityProfile();
        $maxRevisions = max(0, (int) ($profile['max_revisions'] ?? 1));
        $nextStage = QualityGate::needsRevision($payload, Settings::get()) && $revisionCount < $maxRevisions ? 'revise' : 'publish';
        $repo->advance((int) $job['id'], $nextStage);
        Scheduler::scheduleNextStage((int) $job['id']);
    }

    private function revise(array $job): void
    {
        $repo = new JobRepository();
        $payload = $this->payload($job);
        $previousArticle = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $result = $this->client->respond('article_v1', Contracts::schema('article_v1'), PromptBuilder::revision($payload, $job), false, (string) Settings::get()['model_research']);
        if (is_wp_error($result)) {
            $repo->fail((int) $job['id'], $result->get_error_message(), $this->isPermanent($result));
            return;
        }
        $revisionCount = max(0, (int) ($payload['revision_count'] ?? 0)) + 1;
        $article = is_array($result['data'] ?? null) ? $result['data'] : [];
        $article['content_html'] = wp_kses_post((string) ($article['content_html'] ?? ''));
        $payload['audit_history'][] = $payload['audit'] ?? [];
        $payload['article'] = $article;
        $payload['revision_count'] = $revisionCount;
        $payload['usage']['revision_' . $revisionCount] = $result['usage'] ?? [];
        $payload['quality_diagnostics'] = QualityGate::diagnostics($payload);
        if (empty($payload['quality_diagnostics']['passed'])) {
            $payload['article'] = $previousArticle;
            $payload['revision_failure'] = $payload['quality_diagnostics'];
            $payload['quality_diagnostics'] = QualityGate::diagnostics($payload);
            $payload['publish_decision'] = QualityGate::decision($payload, array_merge(Settings::get(), ['post_status' => 'draft']));
            $repo->savePayload((int) $job['id'], $payload);
            $repo->advance((int) $job['id'], 'publish');
            Scheduler::scheduleNextStage((int) $job['id']);
            return;
        }
        $repo->savePayload((int) $job['id'], $payload);
        $repo->advance((int) $job['id'], 'audit');
        Scheduler::scheduleNextStage((int) $job['id']);
    }

    private function publish(array $job): void
    {
        $payload = $this->payload($job);
        $postId = (new Publisher())->publish((int) $job['id'], $payload);
        if (is_wp_error($postId)) {
            (new JobRepository())->fail((int) $job['id'], $postId->get_error_message(), false);
            return;
        }

        if (empty($payload['test_mode']) && !empty($job['topic_id'])) {
            (new TopicRepository())->markCompleted((int) $job['topic_id']);
        }

        (new JobRepository())->complete((int) $job['id'], (int) $postId);
    }

    private function payload(array $job): array
    {
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        return is_array($payload) ? $payload : [];
    }

    private function isPermanent(\WP_Error $error): bool
    {
        return !in_array($error->get_error_code(), ['dsap_openai_network', 'dsap_openai_retryable', 'dsap_openai_response_missing', 'dsap_openai_output_limit', 'dsap_nvidia_network', 'dsap_nvidia_retryable'], true);
    }

    private static function topicInstructions(array $article): string
    {
        $lines = [
            '記事概要: ' . (string) ($article['brief'] ?? ''),
            '検索意図: ' . (string) ($article['search_intent'] ?? ''),
            'コンテンツ役割: ' . (string) ($article['content_role'] ?? ''),
            '読者段階: ' . (string) ($article['reader_stage'] ?? ''),
            '意外な入口: ' . (string) ($article['entry_angle'] ?? ''),
            '隠れた悩み: ' . (string) ($article['hidden_pain'] ?? ''),
            '記事の約束: ' . (string) ($article['content_promise'] ?? ''),
            'CVへの橋渡し: ' . (string) ($article['conversion_bridge'] ?? ''),
            '解消する反論: ' . (string) ($article['objection'] ?? ''),
            '誘導先キーワード: ' . (string) ($article['target_keyword'] ?? ''),
        ];
        return sanitize_textarea_field(implode("\n", $lines));
    }

    private function targetPost(array $job): \WP_Post|\WP_Error
    {
        $post = get_post((int) ($job['target_post_id'] ?? 0));
        return $post instanceof \WP_Post ? $post : new \WP_Error('dsap_refresh_target_missing', '改善対象の記事が見つかりません。');
    }
}
