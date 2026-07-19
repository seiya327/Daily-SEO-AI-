<?php

declare(strict_types=1);

namespace DSAP;

final class ArticleVisuals
{
    public static function enhance(string $html, string $title, string $articleType = 'attraction'): string
    {
        $enhanced = $html;
        if (!str_contains($enhanced, 'dsap-article-illustration')) {
            $enhanced = self::insertAfterFirstParagraph($enhanced, self::illustration($title, $articleType));
        }
        if (!str_contains($enhanced, 'dsap-key-takeaways')) {
            $takeaways = self::takeaways($enhanced);
            if ($takeaways !== '') {
                $enhanced = self::insertAfterIllustration($enhanced, $takeaways);
            }
        }
        return $enhanced;
    }

    private static function insertAfterFirstParagraph(string $html, string $insert): string
    {
        $inserted = preg_replace_callback('/(<\/p>)/i', static fn (array $matches): string => (string) $matches[1] . $insert, $html, 1);
        return is_string($inserted) && $inserted !== $html ? $inserted : $insert . $html;
    }

    private static function insertAfterIllustration(string $html, string $insert): string
    {
        $inserted = preg_replace('/(<figure\b[^>]*class=["\'][^"\']*\bdsap-article-illustration\b[^"\']*["\'][^>]*>.*?<\/figure>)/is', '$1' . $insert, $html, 1);
        return is_string($inserted) && $inserted !== $html ? $inserted : self::insertAfterFirstParagraph($html, $insert);
    }

    private static function illustration(string $title, string $articleType): string
    {
        $label = sanitize_text_field($title) !== '' ? sanitize_text_field($title) : 'Article illustration';
        $tone = $articleType === 'cv' ? 'is-cv' : 'is-attraction';
        return '<figure class="dsap-article-illustration ' . esc_attr($tone) . '" role="img" aria-label="' . esc_attr($label) . '">'
            . '<div class="dsap-art-sky"><span></span><span></span><span></span></div>'
            . '<div class="dsap-art-card"><i></i><i></i><i></i></div>'
            . '<div class="dsap-art-bars"><span></span><span></span><span></span></div>'
            . '<div class="dsap-art-path"><span></span><span></span></div>'
            . '</figure>';
    }

    private static function takeaways(string $html): string
    {
        preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>/is', $html, $matches);
        $items = [];
        foreach (($matches[1] ?? []) as $heading) {
            $text = trim(wp_strip_all_tags((string) $heading));
            if ($text === '' || str_contains($text, '参考') || str_contains($text, 'あわせて')) {
                continue;
            }
            $items[] = $text;
            if (count($items) >= 3) {
                break;
            }
        }
        if ($items === []) {
            return '';
        }
        $lis = '';
        foreach ($items as $item) {
            $lis .= '<li>' . esc_html($item) . '</li>';
        }
        return '<aside class="dsap-key-takeaways"><h2>この記事の要点</h2><ul>' . $lis . '</ul></aside>';
    }
}
