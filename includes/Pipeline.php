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
        $result = $this->client->respond(
            'strategy_v1',
            Contracts::schema('strategy_v1'),
            PromptBuilder::strategy($job),
            false,
            (string) Settings::get()['model_research']
        );
        if (is_wp_error($result)) {
            $repo->fail((int) $job['id'], $result->get_error_message(), $this->isPermanent($result));
            return;
        }

        $plan = is_array($result['data'] ?? null) ? $result['data'] : [];
        $articles = is_array($plan['articles'] ?? null) ? $plan['articles'] : [];
        if ($articles === []) {
            $repo->fail((int) $job['id'], 'Strategy did not contain any article plans.', true);
            return;
        }

        $topicRepo = new TopicRepository();
        foreach ($articles as $article) {
            if (!is_array($article) || empty($article['keyword'])) {
                continue;
            }
            $articleType = ($article['article_type'] ?? '') === 'cv' ? 'cv' : 'attraction';
            $topicRepo->create(
                sanitize_text_field((string) $article['keyword']),
                sanitize_textarea_field((string) ($article['brief'] ?? '')),
                $articleType,
                sanitize_text_field((string) ($article['cluster_name'] ?? '')),
                $articleType === 'cv' ? esc_url_raw((string) Settings::get()['affiliate_url']) : '',
                sanitize_text_field((string) ($article['anchor_text'] ?? '')),
                absint($article['priority'] ?? 50)
            );
        }

        update_option('dsap_strategy_plan', [
            'plan' => $plan,
            'created_at' => current_time('mysql'),
            'job_id' => (int) $job['id'],
        ], false);
        $repo->savePayload((int) $job['id'], ['strategy' => $plan, 'usage' => $result['usage'] ?? []]);
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
            'target_url' => $topic['target_url'] ?? '',
            'anchor_text' => $topic['anchor_text'] ?? '',
        ];
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

        $hardError = QualityGate::hardChecks($payload);
        if ($hardError !== '') {
            $repo->fail((int) $job['id'], $hardError, true);
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
        $payload['usage']['audit'] = $result['usage'] ?? [];
        $payload['publish_decision'] = QualityGate::decision($payload, Settings::get());

        $repo->savePayload((int) $job['id'], $payload);
        $repo->advance((int) $job['id'], 'publish');
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

        (new JobRepository())->complete((int) $job['id'], (int) $postId);
    }

    private function payload(array $job): array
    {
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        return is_array($payload) ? $payload : [];
    }

    private function isPermanent(\WP_Error $error): bool
    {
        return !in_array($error->get_error_code(), ['dsap_openai_network', 'dsap_openai_retryable'], true);
    }

    private function targetPost(array $job): \WP_Post|\WP_Error
    {
        $post = get_post((int) ($job['target_post_id'] ?? 0));
        return $post instanceof \WP_Post ? $post : new \WP_Error('dsap_refresh_target_missing', '改善対象の記事が見つかりません。');
    }
}
