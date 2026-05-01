<?php

add_action('init', static function (): void {
    update_option('theme_booted', true);
});

add_filter('the_content', static function (string $content): string {
    return $content;
});
