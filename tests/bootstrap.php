<?php

declare(strict_types=1);

const MINUTE_IN_SECONDS = 60;
const MB_IN_BYTES = 1048576;

$GLOBALS['dsap_test_options'] = [];
$GLOBALS['dsap_test_meta'] = [];

class WP_Error
{
    public function __construct(
        private string $code = '',
        private string $message = '',
        private mixed $data = null
    ) {
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_data(): mixed
    {
        return $this->data;
    }
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['dsap_test_options'][$name] ?? $default;
}

function wp_parse_args(mixed $args, array $defaults = []): array
{
    return array_merge($defaults, is_array($args) ? $args : []);
}

function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
{
    return json_encode($value, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, $depth);
}

function wp_strip_all_tags(string $value): string
{
    return trim(strip_tags($value));
}

function sanitize_text_field(mixed $value): string
{
    return trim(preg_replace('/[\r\n\t]+/u', ' ', strip_tags((string) $value)) ?: '');
}

function sanitize_textarea_field(mixed $value): string
{
    return trim(strip_tags((string) $value));
}

function sanitize_key(mixed $value): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)) ?: '';
}

function esc_html(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function esc_attr(mixed $value): string
{
    return esc_html($value);
}

function esc_url(mixed $value): string
{
    return filter_var((string) $value, FILTER_VALIDATE_URL) ? (string) $value : '';
}

function get_post_meta(int $postId, string $key, bool $single = false): mixed
{
    $value = $GLOBALS['dsap_test_meta'][$postId][$key] ?? '';
    return $single ? $value : [$value];
}

function wp_get_attachment_image(int $attachmentId, string $size = 'thumbnail', bool $icon = false, array $attributes = []): string
{
    unset($size, $icon);
    $src = (string) get_post_meta($attachmentId, '_dsap_test_src', true);
    if ($src === '') {
        return '';
    }
    $alt = esc_attr((string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true));
    $class = esc_attr((string) ($attributes['class'] ?? ''));
    return '<img src="' . esc_attr($src) . '" alt="' . $alt . '" class="' . $class . '">';
}

function absint(mixed $value): int
{
    return abs((int) $value);
}
