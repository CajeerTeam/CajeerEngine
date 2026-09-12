<?php

declare(strict_types=1);

namespace CajeerEngine\Cms;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

final class CmsBootstrapService
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /** @return array<string,mixed> */
    public function run(bool $withDemoHome = false): array
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $types = new ContentTypeRepository($this->rootPath, $database);
        $entries = new ContentEntryRepository($this->rootPath, $database);
        $theme = new ThemeConfigRepository($this->rootPath, $database);
        $navigation = new NavigationRepository($this->rootPath, $database);

        $created = [];
        $pageType = $types->find('pages');
        if ($pageType === null) {
            $pageType = $types->create($this->pageTypePayload());
            $created[] = 'content_type:pages';
        }

        if ($withDemoHome && $entries->find('pages', 'home') === null) {
            $entries->create('pages', [
                'title' => 'Главная страница',
                'slug' => 'home',
                'status' => 'published',
                'locale' => 'ru',
                'data' => [
                    'heading' => 'CajeerEngine готов к работе',
                    'summary' => 'Базовый публичный слой CMS установлен: страницы, меню, SEO, sitemap, robots и preview.',
                    'body' => '<p>Откройте админку, измените эту страницу или создайте собственную структуру сайта.</p>',
                    'seo_title' => 'Главная — CajeerEngine',
                    'meta_description' => 'Демо-страница CajeerEngine CMS product layer.',
                    'path' => '/',
                    'blocks' => [],
                ],
            ]);
            $created[] = 'entry:pages/home';
        }

        $themeConfig = $theme->save($theme->get());
        if ($navigation->find('primary') === null) {
            $navigation->save('primary', 'Основное меню', [
                ['label' => 'Главная', 'url' => '/', 'target' => '_self'],
            ]);
            $created[] = 'navigation:primary';
        }

        return [
            'ok' => true,
            'storage' => [
                'content_types' => $types->storage(),
                'content_entries' => $entries->storage(),
            ],
            'created' => $created,
            'content_type' => $pageType,
            'theme' => $themeConfig,
            'message' => 'CMS product layer готов: pages, theme config и primary navigation доступны.',
        ];
    }

    /** @return array<string,mixed> */
    private function pageTypePayload(): array
    {
        return [
            'handle' => 'pages',
            'name' => 'Страницы',
            'localized' => true,
            'revisionable' => true,
            'fields' => [
                ['handle' => 'heading', 'type' => 'text', 'label' => 'Заголовок H1', 'required' => false, 'localized' => true],
                ['handle' => 'summary', 'type' => 'textarea', 'label' => 'Краткое описание', 'required' => false, 'localized' => true],
                ['handle' => 'body', 'type' => 'richtext', 'label' => 'Содержимое', 'required' => false, 'localized' => true],
                ['handle' => 'path', 'type' => 'text', 'label' => 'Публичный путь', 'required' => false, 'localized' => true],
                ['handle' => 'parent_id', 'type' => 'relation', 'label' => 'Родительская страница', 'required' => false, 'localized' => false],
                ['handle' => 'sort_order', 'type' => 'number', 'label' => 'Порядок сортировки', 'required' => false, 'localized' => false],
                ['handle' => 'seo_title', 'type' => 'text', 'label' => 'SEO title', 'required' => false, 'localized' => true],
                ['handle' => 'meta_description', 'type' => 'textarea', 'label' => 'Meta description', 'required' => false, 'localized' => true],
                ['handle' => 'canonical_url', 'type' => 'text', 'label' => 'Canonical URL', 'required' => false, 'localized' => true],
                ['handle' => 'og_image', 'type' => 'media', 'label' => 'OpenGraph image', 'required' => false, 'localized' => true],
                ['handle' => 'seo_noindex', 'type' => 'boolean', 'label' => 'Noindex', 'required' => false, 'localized' => false],
                ['handle' => 'blocks', 'type' => 'blocks', 'label' => 'Блоки', 'required' => false, 'localized' => true],
            ],
            'blocks' => [
                [
                    'handle' => 'text',
                    'name' => 'Текстовый блок',
                    'fields' => [
                        ['handle' => 'title', 'type' => 'text', 'label' => 'Заголовок', 'required' => false],
                        ['handle' => 'content', 'type' => 'richtext', 'label' => 'Текст', 'required' => false],
                    ],
                ],
                [
                    'handle' => 'cta',
                    'name' => 'CTA-блок',
                    'fields' => [
                        ['handle' => 'title', 'type' => 'text', 'label' => 'Заголовок', 'required' => false],
                        ['handle' => 'content', 'type' => 'textarea', 'label' => 'Описание', 'required' => false],
                        ['handle' => 'button_label', 'type' => 'text', 'label' => 'Текст кнопки', 'required' => false],
                        ['handle' => 'button_url', 'type' => 'text', 'label' => 'Ссылка кнопки', 'required' => false],
                    ],
                ],
            ],
        ];
    }
}
