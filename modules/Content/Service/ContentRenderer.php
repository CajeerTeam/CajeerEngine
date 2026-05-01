<?php

declare(strict_types=1);

namespace Cajeer\Modules\Content\Service;

use Cajeer\Events\EventDispatcher;
use Cajeer\Modules\Content\Domain\ContentItem;

final class ContentRenderer
{
    public function __construct(private readonly EventDispatcher $events) {}

    public function render(ContentItem $item): string
    {
        $body = (string) $item->body;
        $this->events->dispatch('content.before_render', ['item' => $item, 'body' => $body]);
        $body = function_exists('do_shortcode') ? do_shortcode($body) : $body;
        $body = function_exists('apply_filters') ? (string) apply_filters('the_content', $body) : $body;
        $body = $this->sanitize($body);
        $this->events->dispatch('content.after_render', ['item' => $item, 'body' => $body]);
        return $body;
    }

    private function sanitize(string $html): string
    {
        return strip_tags($html, '<p><br><a><strong><b><em><i><ul><ol><li><blockquote><code><pre><h1><h2><h3><h4><h5><h6><img>');
    }
}
