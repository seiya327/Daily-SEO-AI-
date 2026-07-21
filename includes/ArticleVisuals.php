<?php

declare(strict_types=1);

namespace DSAP;

final class ArticleVisuals
{
    public static function enhance(string $html, string $title, string $articleType = 'attraction', string $answerSummary = ''): string
    {
        $enhanced = $html;
        if (!str_contains($enhanced, 'dsap-article-illustration')) {
            $enhanced = self::insertAfterFirstParagraph($enhanced, self::illustration($title, $articleType));
        }
        if (!str_contains($enhanced, 'dsap-key-takeaways')) {
            $takeaways = self::takeaways($enhanced, $answerSummary);
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
        $label = sanitize_text_field($title) !== '' ? sanitize_text_field($title) : '記事内容のイメージ';
        $tone = $articleType === 'cv' ? 'is-cv' : 'is-attraction';
        $steps = $articleType === 'cv'
            ? [['条件を確認', '料金・契約・制約'], ['候補を比較', '向く人・向かない人'], ['公式情報で決定', '申込前に最終確認']]
            : [['悩みを特定', '困っている場面を整理'], ['解決策を実行', '手順と注意点を確認'], ['次の判断へ', '必要な比較記事へ進む']];
        $items = '';
        foreach ($steps as $index => $step) {
            $items .= '<div class="dsap-visual-step"><span>' . esc_html((string) ($index + 1)) . '</span><strong>' . esc_html($step[0]) . '</strong><small>' . esc_html($step[1]) . '</small></div>';
        }
        return '<figure class="dsap-article-illustration ' . esc_attr($tone) . '" role="group" aria-label="' . esc_attr($label) . '">'
            . '<figcaption>この記事の判断ルート</figcaption>'
            . '<div class="dsap-visual-flow">' . $items . '</div>'
            . '</figure>';
    }

    private static function takeaways(string $html, string $answerSummary): string
    {
        $items = [];
        $summary = trim(wp_strip_all_tags($answerSummary));
        if ($summary !== '') {
            $sentences = preg_split('/(?<=[。！？!?])\s*/u', $summary, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($sentences as $sentence) {
                $text = trim((string) $sentence);
                if ($text !== '') {
                    $items[] = $text;
                }
                if (count($items) >= 3) {
                    break;
                }
            }
        }
        if (count($items) < 3) {
            preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>/is', $html, $matches);
            foreach (($matches[1] ?? []) as $heading) {
                $text = trim(wp_strip_all_tags((string) $heading));
                if ($text === '' || str_contains($text, '参考') || str_contains($text, 'あわせて')) {
                    continue;
                }
                if (!in_array($text, $items, true)) {
                    $items[] = $text;
                }
                if (count($items) >= 3) {
                    break;
                }
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
