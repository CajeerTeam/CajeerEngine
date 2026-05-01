<?php

declare(strict_types=1);

namespace Cajeer\Themes;

final class ThemeAssetManager
{
    public function __construct(private readonly ThemeManager $themes) {}
    public function url(string $path, ?string $theme = null): string { return $this->themes->assetUrl($path, $theme); }
}
