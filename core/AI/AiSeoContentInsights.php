<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Analytics\Reporting\AnalyticsHub;
use CajeerEngine\Analytics\Reporting\ReportComparison;
use CajeerEngine\Public\RouteIndex;
use CajeerEngine\Runtime\ConfigRepository;

/** Joins stored page-level provider observations to actual published CMS routes.
 * No remote fetches, inferred hostnames, bulk writes, or AI-generated numeric facts.
 */
final readonly class AiSeoContentInsights
{
    public function __construct(
        private AnalyticsHub $hub,
        private AiSeoAssistant $seo,
        private RouteIndex $routes,
        private ConfigRepository $config,
    ) {}

    public function report(string $connectionId, ?string $from = null, ?string $to = null, int $limit = 25): array
    {
        if ($limit < 1 || $limit > 40) throw new \InvalidArgumentException('Invalid SEO insights limit.');
        $data = $this->hub->report($connectionId, $from, $to);
        $current = $data['report'];
        if ($current === null) return ['available'=>false, 'message'=>'Сначала синхронизируйте период в Analytics Hub.', 'items'=>[], 'warnings'=>[]];
        $provider = (string)($current['provider'] ?? '');
        $from = (string)$current['from']; $to = (string)$current['to'];
        $comparison = $data['comparison'];
        $status = (string)($comparison['status'] ?? 'missing');
        $previous = null;
        if ($status === 'ready') {
            $dates = ReportComparison::previous($from, $to);
            if ($dates !== null) {
                $older = $this->hub->report($connectionId, $dates[0], $dates[1]);
                if ($older['connection']['revision'] !== $data['connection']['revision']) throw new \RuntimeException('Analytics configuration changed during comparison.');
                $previous = $older['report'];
                if (($previous['provider'] ?? null) !== $provider || ($previous['resource'] ?? null) !== ($current['resource'] ?? null)
                    || ($previous['metrics'] ?? null) !== ($current['metrics'] ?? null)) $previous = null;
            }
        }
        $pageSection = $current['sections']['pages'] ?? null;
        if (!is_array($pageSection) || !is_array($pageSection['rows'] ?? null)) {
            return ['available'=>true, 'provider'=>$provider, 'from'=>$from, 'to'=>$to,
                'comparison_status'=>$status, 'items'=>[], 'warnings'=>['Этот источник не предоставляет детализацию по URL страниц.'],
                'matched'=>0,'examined'=>0,'source_fetched_at'=>$current['fetched_at'] ?? null];
        }
        $metric = self::primaryMetric($provider, (array)($pageSection['metrics'] ?? $current['metrics'] ?? []));
        $index = array_search($metric, $pageSection['metrics'] ?? $current['metrics'] ?? [], true);
        $warnings = [];
        if ($metric === null || $index === false) {
            $warnings[] = 'Нет сопоставимой метрики для страниц этого источника.';
        }
        $quality = self::usable($pageSection,$provider,$from,$to);
        $priorSection = $previous['sections']['pages'] ?? null;
        $priorQuality = is_array($priorSection) && self::usable($priorSection,$provider,(string)($previous['from'] ?? ''),(string)($previous['to'] ?? ''));
        $canCompare = $status === 'ready' && $previous !== null && $quality && $priorQuality
            && ($priorSection['metrics'] ?? $previous['metrics']) === ($pageSection['metrics'] ?? $current['metrics']);
        if (!$canCompare) $warnings[] = 'Изменения по URL не подтверждены сопоставимыми полными данными двух периодов.';
        if (!empty($pageSection['truncated'])) $warnings[] = 'Провайдер ограничил список страниц: это не аудит всего сайта.';
        if (!$quality) $warnings[] = 'Детализация текущего периода ограничена, семплирована или неполна.';
        if (!empty($pageSection['quality']['limited_by_provider'])) $warnings[] = 'Провайдер может ограничивать список URL: сравниваются только страницы, присутствующие в обоих снимках.';
        if ($to >= gmdate('Y-m-d')) $warnings[] = 'Текущий день может содержать частичные данные.';
        if (($current['fetched_at'] ?? '') === '' || (strtotime((string)$current['fetched_at']) ?: 0) < time()-86400)
            $warnings[] = 'Снимок старше суток или его свежесть неизвестна.';
        $prior = [];
        if ($canCompare && $index !== false) {
            foreach (array_slice($priorSection['rows'],0,100) as $row) {
                if (!is_array($row)) continue;
                $path = self::normalizedPath($row['label'] ?? null, $this->config->string('app.url'));
                $value = self::number($row['values'][$index] ?? null);
                if ($path !== null && $value !== null && !array_key_exists($path,$prior)) $prior[$path] = $value;
            }
        }
        $seen = []; $result = []; $examined = 0;
        foreach (array_slice($pageSection['rows'],0,100) as $row) {
            $examined++;
            if (!is_array($row)) continue;
            $path = self::normalizedPath($row['label'] ?? null,$this->config->string('app.url'));
            if ($path === null || isset($seen[$path])) continue;
            $seen[$path] = true;
            $value = $index === false ? null : self::number($row['values'][$index] ?? null);
            if ($value === null) continue;
            $route = $this->routes->lookup($path);
            if (($route['kind'] ?? '') !== 'page') continue;
            $type = $route['type'] ?? null; $entryId = $route['id'] ?? null;
            if (!is_string($type) || !is_string($entryId)) continue;
            try { $audit = $this->seo->audit($type,$entryId); }
            catch (\DomainException|\InvalidArgumentException) { continue; } // deleted while reading
            if ($audit['status'] !== 'published' || ($audit['seo']['noindex'] ?? false)) continue;
            $trend = self::trend($value,$prior[$path] ?? null, $canCompare);
            $recommendations = self::recommendations($audit,$trend,$metric);
            if ($recommendations === []) continue;
            $priority = self::priority($audit,$trend);
            $result[] = ['type'=>$type,'entry_id'=>$entryId,'revision'=>$audit['revision'],
                'path'=>$path,'title'=>$audit['title'],'locale'=>$audit['locale'],
                'score'=>$audit['score'],'metric'=>$metric,'current'=>$value,
                'previous'=>$trend['previous'],'change_percent'=>$trend['percent'],
                'trend'=>$trend['status'],'priority'=>$priority,
                'issues'=>$audit['issues'],'recommendations'=>$recommendations];
        }
        usort($result,static fn(array $a,array $b): int => $b['priority'] <=> $a['priority'] ?: strcmp($a['path'],$b['path']));
        return ['available'=>true, 'provider'=>$provider,'connection_id'=>$connectionId,'from'=>$from,'to'=>$to,
            'source_fetched_at'=>$current['fetched_at'] ?? null,'comparison_status'=>$status,
            'metric'=>$metric,'warnings'=>array_values(array_unique($warnings)),
            'matched'=>count($result),'examined'=>$examined,'items'=>array_slice($result,0,$limit),
            'notes'=>'Приоритет основан на сохранённых SEO-проверках и достоверных показателях одного источника. Связь показателей с SEO не доказывает причинность. Выборка ограничена строками отчёта провайдера.'];
    }

    /** Rejects foreign hosts, credentials, fragments, relative traversals and ambiguous encoded paths. */
    public static function normalizedPath(mixed $label, string $siteUrl): ?string
    {
        if (!is_string($label) || $label === '' || strlen($label) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$label)) return null;
        $origin = parse_url($siteUrl);
        $siteHost = strtolower((string)($origin['host'] ?? ''));
        if ($siteHost === '') return null;
        if (preg_match('~^https?://~i',$label)) {
            $url = parse_url($label);
            if (!is_array($url) || !in_array(strtolower((string)($url['scheme']??'')),['http','https'],true)
                || strtolower((string)($url['host']??'')) !== $siteHost || isset($url['user']) || isset($url['pass'])
                || isset($url['port']) && $url['port'] !== ($origin['port'] ?? ($url['scheme'] === 'https'?443:80))) return null;
            $path = $url['path'] ?? '/';
        } elseif (str_starts_with($label,'/') && !str_starts_with($label,'//')) {
            $path = (string)(parse_url($label,PHP_URL_PATH) ?? '');
        } else return null;
        if ($path === '' || $path[0] !== '/' || str_contains($path,'//') || preg_match('~%(?:2f|5c|2e|00)~i',$path)
            || preg_match('~(?:^|/)\.\.?(/|$)~',$path) || preg_match('/[\x00-\x20\x7f]/',$path)) return null;
        return $path;
    }

    public static function trend(?float $current, ?float $previous, bool $comparable): array
    {
        if (!$comparable || $current === null || $previous === null || $previous < 10) {
            return ['status'=>'unavailable','previous'=>null,'percent'=>null];
        }
        $percent = round(100*($current-$previous)/$previous,2);
        return ['status'=>$percent<=-20?'decline':($percent>=20?'growth':'stable'),
            'previous'=>$previous,'percent'=>$percent];
    }

    private static function usable(array $section,string $provider,string $from,string $to): bool
    {
        $q = $section['quality'] ?? [];
        if (!empty($section['truncated']) || !empty($q['sampled']) || !empty($q['thresholded'])
            || !empty($q['unavailable']) || !empty($q['data_loss'])) return false;
        if (!empty($q['actual_from']) || !empty($q['actual_to'])) {
            if (!is_string($q['actual_from']??null) || !is_string($q['actual_to']??null)
                || $q['actual_from']>$from || $q['actual_to']<$to) return false;
        }
        // Search Console explicitly marks a provider row limit even when the returned
        // page subset was not truncated. Individual overlapping URLs remain comparable,
        // never treat absent URLs as zero or claim a site-wide exhaustive inventory.
        return empty($q['limited_by_provider']) || $provider === 'search_console'
            || (isset($q['actual_from'],$q['actual_to']) && $q['actual_from']<=$from && $q['actual_to']>=$to);
    }

    private static function primaryMetric(string $provider,array $metrics): ?string
    {
        $candidate = match ($provider) {
            'search_console','webmaster'=>'clicks', 'ga4'=>'sessions','metrika'=>'ym:s:visits',
            'matomo'=>'nb_visits','plausible'=>'visitors','cf_web'=>'visits',default=>null,
        };
        if ($provider === 'matomo' && !in_array($candidate,$metrics,true)) $candidate='visits';
        return $candidate !== null && in_array($candidate,$metrics,true) ? $candidate : null;
    }
    private static function number(mixed $value): ?float
    {
        return (is_int($value)||is_float($value)) && is_finite((float)$value) && $value >= 0 ? (float)$value : null;
    }
    private static function recommendations(array $audit,array $trend,?string $metric): array
    {
        $result = [];
        foreach (array_slice($audit['issues'],0,12) as $issue) {
            if ($issue['severity'] === 'info') continue;
            $result[]=['code'=>$issue['code'],'field'=>match ($issue['code']) {
                'missing_seo_title','long_seo_title','short_seo_title','duplicate_seo_title'=>'seo_title',
                'missing_description','long_description','short_description','duplicate_description'=>'meta_description',
                default=>null},'action'=>$issue['action'],'severity'=>$issue['severity']];
        }
        if ($trend['status'] === 'decline') $result[]=['code'=>'verified_traffic_decline','field'=>null,
            'action'=>'Проверить источники, сезонность, изменения индексации и контент; снижение '.$metric.' само по себе не доказывает SEO-причину.',
            'severity'=>'medium'];
        return $result;
    }
    private static function priority(array $audit,array $trend): int
    {
        $score = max(0,100-(int)$audit['score']);
        if ($trend['status']==='decline') $score+=30+min(30,(int)abs($trend['percent'])/2);
        return $score;
    }
}
