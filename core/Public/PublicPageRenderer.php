<?php

declare(strict_types=1);

namespace CajeerEngine\Public;

use CajeerEngine\Cms\NavigationRepository;
use CajeerEngine\Cms\ThemeConfigRepository;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Template\CajeerTemplateEngine;
use CajeerEngine\Security\SafeHtml;

final readonly class PublicPageRenderer
{
    public function __construct(
        private string $rootPath,
        private ContentTypeRepository $types,
        private ContentEntryRepository $entries,
        private CajeerTemplateEngine $templates,
        private MediaRepository $media,
        private ?ThemeConfigRepository $theme = null,
        private ?NavigationRepository $navigation = null,
        private ?RouteIndex $routes = null,
    ) {
    }

    public function routeIndex(): RouteIndex { return $this->routes ?? new RouteIndex($this->rootPath); }

    public function baseUrl(): string
    {
        return PublicPath::base((new \CajeerEngine\Runtime\ConfigRepository($this->rootPath))->string('app.url'));
    }

    public function renderByTypeAndId(string $typeHandle, string $idOrSlug, bool $preview = false): ?array
    {
        $type=$this->types->find($typeHandle); if($type===null)return null;
        $entry=$this->entries->find($typeHandle,$idOrSlug); if($entry===null)return null;
        $index=$this->routeIndex();$route=$index->forEntry($entry['id']);
        if(!$preview&&($route===null||$entry['status']!=='published'))return null;
        if(!$preview)return $this->renderByPath($route['path']);
        $path=$index->previewPath($entry['id'])??'/';
        return $this->renderEntry($type,$entry,true,$path);
    }

    public function renderPreviewId(string $typeHandle,string $id): ?array
    {
        $entry=$this->entries->findById($typeHandle,$id);$type=$this->types->find($typeHandle);
        if($entry===null||$type===null)return null;
        return $this->renderEntry($type,$entry,true,$this->routeIndex()->previewPath($id)??'/');
    }

    public function renderBySlug(string $slug, bool $preview = false): ?array { return $this->renderByPath($slug,$preview); }

    public function renderByPath(string $path, bool $preview = false): ?array
    {
        // Drafts are only resolved by authenticated ID or preview token, never a public path.
        $resolved=$this->routeIndex()->publishedEntry($path);if($resolved===null)return null;
        $route=$resolved['route'];$entry=$resolved['entry'];$type=$this->types->find($route['type']);
        if($type===null||$entry===null||$entry['status']!=='published')return null;
        return $this->renderEntry($type,$entry,$preview,$route['path']);
    }

    public function renderHome(bool $preview = false): ?array { return $this->renderByPath('/',$preview); }

    public function errorPage(int $status): ?array
    {
        $path=$this->themeConfig()['error_'.$status.'_path']??'';
        return $path!==''?$this->renderByPath($path):null;
    }

    public function errorStatus(string $path): ?int
    {
        $theme=$this->themeConfig();foreach([404,410] as $status)if(($theme['error_'.$status.'_path']??'')===$path)return $status;return null;
    }

    public function sitemap(): array
    {
        $theme=$this->themeConfig();if(!$theme['sitemap_enabled']||!$theme['robots_index'])return [];
        $items=[];$base=$this->baseUrl();$routes=$this->routeIndex()->all();
        foreach($routes as $r){
            if($r['kind']!=='page'||$r['noindex']||in_array($r['path'],[$theme['error_404_path'],$theme['error_410_path']],true))continue;
            if($this->canonical($r['canonical'],$r['path'],$routes)!==$base.$r['path'])continue;
            $items[]=array_intersect_key($r,array_flip(['type','id','title','slug','path','locale','updated_at','translation_group']))+['priority'=>$r['path']==='/'?'1.0':'0.7','changefreq'=>$r['path']==='/'?'daily':'weekly'];
        }
        usort($items,static fn($a,$b)=>strcmp($a['path'],$b['path']));return $items;
    }

    public function sitemapXml(string $baseUrl, ?int $page=null): string
    {
        $baseUrl = PublicPath::base($baseUrl);
        $items=$this->sitemap();$size=500;$pages=(int)ceil(count($items)/$size);
        if($page!==null&&($page<1||$page>max(1,$pages)))throw new \InvalidArgumentException('Invalid sitemap page');
        if($page===null&&$pages>1){$xml='<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';for($i=1;$i<=$pages;$i++)$xml.='<sitemap><loc>'.htmlspecialchars($baseUrl.'/sitemap.xml?page='.$i,ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></sitemap>';return $xml.'</sitemapindex>'; }
        $defaultLocale=$this->themeConfig()['locale'];$groups=[];foreach($items as $item)$groups[$item['translation_group']][$item['locale']]=$item['path'];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        foreach (array_slice($items,(($page??1)-1)*$size,$size) as $item) {
            $loc = $baseUrl . PublicPath::normalize((string) ($item['path'] ?? '/'));
            $lastmod = $this->dateOnly((string) ($item['updated_at'] ?? ''));
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</loc>\n";
            if ($lastmod !== '') {
                $xml .= '    <lastmod>' . htmlspecialchars($lastmod, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</lastmod>\n";
            }
            $xml .= '    <changefreq>' . htmlspecialchars((string) ($item['changefreq'] ?? 'weekly'), ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</changefreq>\n";
            $xml .= '    <priority>' . htmlspecialchars((string) ($item['priority'] ?? '0.7'), ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</priority>\n";
            $alternates=$groups[$item['translation_group']]??[];
            if(count($alternates)>1){foreach($alternates as $locale=>$alternate)$xml.='    <xhtml:link rel="alternate" hreflang="'.htmlspecialchars($locale,ENT_XML1|ENT_QUOTES,'UTF-8').'" href="'.htmlspecialchars($baseUrl.$alternate,ENT_XML1|ENT_QUOTES,'UTF-8').'" />'."\n";
                $default=$defaultLocale;if(isset($alternates[$default]))$xml.='    <xhtml:link rel="alternate" hreflang="x-default" href="'.htmlspecialchars($baseUrl.$alternates[$default],ENT_XML1|ENT_QUOTES,'UTF-8').'" />'."\n";
            }
            $xml .= "  </url>\n";
        }
        return $xml . "</urlset>\n";
    }

    public function robotsTxt(string $baseUrl): string
    {
        $theme = $this->themeConfig();
        $index = filter_var($theme['robots_index'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $lines = [
            'User-agent: *',
            $index ? 'Allow: /' : 'Disallow: /',
        ];
        if ($index && filter_var($theme['sitemap_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            $lines[] = 'Sitemap: ' . rtrim($baseUrl, '/') . '/sitemap.xml';
        }
        return implode("\n", $lines) . "\n";
    }

    /** @param array<string,mixed> $type @param array<string,mixed> $entry @return array{type:array<string,mixed>,entry:array<string,mixed>,html:string,path:string,seo:array<string,mixed>} */
    private function renderEntry(array $type, array $entry, bool $preview, string $path): array
    {
        $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
        $template = $this->templateFor($type, $entry);
        $theme = $this->themeConfig();
        $theme['accent_color'] = preg_match('/^#[0-9a-fA-F]{3,8}$/D', (string) ($theme['accent_color'] ?? '')) ? $theme['accent_color'] : '#2563eb';
        $theme['layout']['container_width'] = preg_match('/^[0-9]{1,4}(?:px|rem|%)$/D', (string) ($theme['layout']['container_width'] ?? '')) ? $theme['layout']['container_width'] : '1040px';
        $theme['favicon_url'] = SafeHtml::url((string) ($theme['favicon_url'] ?? ''));
        $navigation = $this->navigation();
        foreach ($navigation['primary']['items'] ?? [] as $i => $item) { $navigation['primary']['items'][$i]['url'] = SafeHtml::url((string) ($item['url'] ?? '')) ?: '#'; }
        if (is_array($data['blocks'] ?? null)) foreach ($data['blocks'] as $i => $block) { if (is_array($block)) $data['blocks'][$i]['content_html'] = SafeHtml::sanitize((string) ($block['content'] ?? '')); }
        $seo = $this->seo($entry, $theme, $path);
        if($preview||$this->errorStatus($path)!==null) $seo['robots']='noindex,nofollow';
        $content = (string) ($data['body'] ?? $data['content'] ?? '');
        $title = (string) ($entry['title'] ?? ($data['title'] ?? $data['heading'] ?? ''));
        $context = [
            'title' => $title,
            'heading' => (string) ($data['heading'] ?? $title),
            'summary' => (string) ($data['summary'] ?? $data['excerpt'] ?? ''),
            'content' => $content,
            'content_html' => SafeHtml::sanitize($content),
            'data' => $data,
            'entry' => $entry,
            'type' => $type,
            'locale' => (string) ($entry['locale'] ?? ($theme['locale'] ?? 'ru')),
            'preview' => $preview,
            'media' => $this->media->publicItems(),
            'published_at' => (string) ($entry['published_at'] ?? ''),
            'updated_at' => (string) ($entry['updated_at'] ?? ''),
            'theme' => $theme,
            'navigation' => $navigation,
            'seo' => $seo,
            'canonical_url' => (string) ($seo['canonical_url'] ?? ''),
            'path' => $path,
        ];

        $html = $this->renderActiveTheme($context) ?? $this->templates->render($template, $context);
        return [
            'type' => $type,
            'entry' => $entry,
            'html' => $html,
            'path' => $path,
            'seo' => $seo,
        ];
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $theme @return array<string,mixed> */
    private function seo(array $entry, array $theme, string $path): array
    {
        $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
        $themeSeo = is_array($theme['seo'] ?? null) ? $theme['seo'] : [];
        $title = trim((string) ($data['seo_title'] ?? $data['meta_title'] ?? $entry['title'] ?? $themeSeo['default_title'] ?? $theme['site_name'] ?? 'CajeerEngine'));
        $suffix = trim((string) ($themeSeo['title_suffix'] ?? ''));
        if ($suffix !== '' && !str_ends_with($title, $suffix)) {
            $title .= ' ' . $suffix;
        }
        $description = trim((string) ($data['meta_description'] ?? $data['summary'] ?? $themeSeo['default_description'] ?? $theme['site_description'] ?? ''));
        return [
            'title' => $title,
            'description' => $description,
            'canonical_url' => $this->canonical((string)($data['canonical_url']??''),$path),
            'og_title' => trim((string) ($data['og_title'] ?? $title)),
            'og_description' => trim((string) ($data['og_description'] ?? $description)),
            'og_image' => SafeHtml::url((string) ($data['og_image'] ?? $themeSeo['og_image'] ?? '')),
            'robots' => (filter_var($data['seo_noindex'] ?? false, FILTER_VALIDATE_BOOLEAN)||!$theme['robots_index']) ? 'noindex,nofollow' : ($theme['robots_follow']?'index,follow':'index,nofollow'),
        ];
    }

    private function canonical(string $value,string $path,?array $routes=null): string
    {
        $base=$this->baseUrl();if($value==='')return $base.$path;
        try {
            PublicPath::target($value);$p=parse_url($value);
            if(isset($p['fragment'])||isset($p['query']))return $base.$path;
            $local=PublicPath::localTarget($value,$base);
            if($local!==null){if($local===$path)return $base.$path;$row=$routes===null?$this->routeIndex()->lookup($local):($routes[$local]??null);return $base.(($row['kind']??null)==='alias'?$row['target']:(($row['kind']??null)==='page'?$local:$path));}
            return $value;
        }catch(\InvalidArgumentException){return $base.$path;}
    }

    /** @return array<string,mixed> */
    private function themeConfig(): array
    {
        return ($this->theme ?? new ThemeConfigRepository($this->rootPath))->get();
    }

    /** @return array<string,mixed> */
    private function navigation(): array
    {
        $repo = $this->navigation ?? new NavigationRepository($this->rootPath);
        $theme = $this->themeConfig();
        $handle = (string) ($theme['primary_menu'] ?? 'primary');
        $menus=$repo->all();$primary=$repo->find($handle)??$repo->primary();$ids=[];
        $collect=function(array $items)use(&$collect,&$ids){foreach($items as $item){if(!empty($item['entry_id']))$ids[]=$item['entry_id'];$collect($item['children']??[]);}};
        foreach(array_merge($menus,[$primary]) as $menu)$collect($menu['items']??[]);
        $paths=$this->routeIndex()->pathsForEntries($ids);
        $resolve=function(array $items)use(&$resolve,$paths){$out=[];foreach($items as $item){$id=$item['entry_id']??null;if($id){if(!isset($paths[$id]))continue;$item['url']=$paths[$id];}$item['children']=$resolve($item['children']??[]);$out[]=$item;}return $out;};
        foreach($menus as &$menu)$menu['items']=$resolve($menu['items']??[]);unset($menu);$primary['items']=$resolve($primary['items']??[]);
        return ['primary'=>$primary,'menus'=>$menus];
    }

    /** @param array<string,mixed> $type @param array<string,mixed> $entry */
    private function templateFor(array $type, array $entry): string
    {
        $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
        $candidates = [
            (string) ($data['_template'] ?? ''),
            'types.' . (string) ($type['handle'] ?? '') . '.show',
            'default.page',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            try {
                return $this->templates->resolve($candidate);
            } catch (\Throwable) {
                continue;
            }
        }

        return $this->rootPath . '/templates/default/page.cjr';
    }

    private function renderActiveTheme(array $context): ?string
    {
        $registry=new \CajeerEngine\Extension\ExtensionRegistry($this->rootPath,new \CajeerEngine\Runtime\ConfigRepository($this->rootPath));
        foreach (\CajeerEngine\Extension\DependencyGraph::order($registry->all()) as $item) {
            if ($item['type']!=='theme') continue;
            $root=$this->rootPath.'/'.$item['path'].'/templates';
            if (!is_file($root.'/page.cjr')) throw new \RuntimeException('Active theme has no templates/page.cjr');
            $engine=new CajeerTemplateEngine(new \CajeerEngine\Template\TemplateSandbox($root),$root);
            $context['theme_assets']='/extensions/'.$item['slug'];
            return $engine->render($root.'/page.cjr',$context);
        }
        return null;
    }

    private function dateOnly(string $date): string
    {
        if ($date === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($date))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }
}
