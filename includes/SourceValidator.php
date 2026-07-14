<?php

declare(strict_types=1);

namespace DSAP;

final class SourceValidator
{
    public static function validateResearch(array $research, array $apiSources): string
    {
        if ($apiSources === []) {
            return 'OpenAI web search did not return verifiable citations.';
        }
        $sources = is_array($research['sources'] ?? null) ? $research['sources'] : [];
        if (count($sources) < 3) {
            return 'Research must include at least 3 sources.';
        }

        $sourceUrls = [];
        foreach ($sources as $source) {
            $url = esc_url_raw((string) ($source['url'] ?? ''));
            if ($url === '') {
                return 'Research includes an invalid source URL.';
            }
            $sourceUrls[] = self::normalize($url);
        }

        $verified = array_map([self::class, 'normalize'], $apiSources);
        foreach ($sourceUrls as $url) {
            if (!in_array($url, $verified, true)) {
                return 'Research source URL was not present in OpenAI web search citations.';
            }
        }

        foreach (($research['facts'] ?? []) as $fact) {
            foreach (($fact['source_indexes'] ?? []) as $index) {
                if (!isset($sources[(int) $index])) {
                    return 'Research fact references an out-of-range source index.';
                }
            }
        }

        return '';
    }

    public static function normalize(string $url): string
    {
        $url = strtolower(trim($url));
        $url = preg_replace('/#.*$/', '', $url) ?: $url;
        return rtrim($url, '/');
    }

    public static function validateRefresh(array $article, array $apiSources, bool $required): string
    {
        $sources = is_array($article['sources'] ?? null) ? $article['sources'] : [];
        if ($required && ($sources === [] || $apiSources === [])) {
            return 'Web research was required but no verifiable sources were returned.';
        }
        $verified = array_map([self::class, 'normalize'], $apiSources);
        foreach ($sources as $source) {
            $url = esc_url_raw((string) ($source['url'] ?? ''));
            if ($url === '' || ($required && !in_array(self::normalize($url), $verified, true))) {
                return 'Refresh source URL was not present in OpenAI web search citations.';
            }
        }
        foreach (($article['source_indexes'] ?? []) as $index) {
            if (!isset($sources[(int) $index])) {
                return 'Refresh article references an out-of-range source index.';
            }
        }
        return '';
    }
}
