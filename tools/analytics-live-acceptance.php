#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Runs real vendor requests on an isolated staging host; never prints bearer tokens or report rows. */
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'CajeerEngine\\';
    if (str_starts_with($class, $prefix)) {
        $path = $root . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require_once $path;
    }
});

use CajeerEngine\Analytics\Reporting\{AnalyticsHub, AlternativeReports, ApiReports, CloudflareReports, SearchReports};

try {
    $provider = getenv('ANALYTICS_ACCEPTANCE_PROVIDER') ?: '';
    $resource = getenv('ANALYTICS_ACCEPTANCE_RESOURCE') ?: '';
    $credentialFile = getenv('ANALYTICS_ACCEPTANCE_CREDENTIAL_FILE') ?: '';
    $from = getenv('ANALYTICS_ACCEPTANCE_FROM') ?: '';
    $to = getenv('ANALYTICS_ACCEPTANCE_TO') ?: '';
    $goal = getenv('ANALYTICS_ACCEPTANCE_GOAL') ?: null;
    if (!in_array($provider, ['metrika', 'ga4', 'webmaster', 'search_console', 'matomo', 'plausible', 'cf_zone', 'cf_web'], true)) {
        throw new RuntimeException('Unsupported acceptance provider');
    }
    if ($credentialFile === '' || !is_file($credentialFile) || is_link($credentialFile) || !is_readable($credentialFile)) {
        throw new RuntimeException('Secure credential file is required');
    }
    $real = realpath($credentialFile);
    if ($real === false || str_starts_with($real, realpath($root) . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Credential file must be outside the repository');
    }
    if (DIRECTORY_SEPARATOR === '/' && (fileperms($real) & 0077) !== 0) {
        throw new RuntimeException('Credential file must not be readable by group or others');
    }
    [$from, $to] = AnalyticsHub::period($from, $to);
    $raw = file_get_contents($real);
    if ($raw === false || strlen($raw) > 65536) throw new RuntimeException('Invalid credential file');
    $credential = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($credential)) throw new RuntimeException('Invalid credential file');
    $handler = match ($provider) {
        'metrika', 'ga4' => new ApiReports($provider),
        'webmaster', 'search_console' => new SearchReports($provider),
        'matomo', 'plausible' => new AlternativeReports($provider),
        'cf_zone', 'cf_web' => new CloudflareReports($provider),
    };
    $handler->validateCredential($credential);
    if (in_array($provider, ['matomo','plausible'], true)) {
        $host = parse_url(AlternativeReports::base($credential['endpoint']), PHP_URL_HOST);
        $allow = array_filter(array_map('strtolower', array_map('trim', explode(',', getenv('ANALYTICS_ACCEPTANCE_ALLOWED_HOSTS') ?: 'plausible.io'))));
        if (!in_array(strtolower((string)$host), $allow, true)) throw new RuntimeException('Staging analytics endpoint is not allowlisted');
    }
    if (in_array($provider, ['webmaster','search_console'], true) && !SearchReports::validResource($provider, $resource)) {
        throw new RuntimeException('Invalid property identifier');
    }
    if (in_array($provider, ['cf_zone','cf_web'], true) && !CloudflareReports::validResource($resource)) {
        throw new RuntimeException('Invalid Cloudflare identifier');
    }
    if (in_array($provider, ['matomo','plausible'], true) && !AlternativeReports::validResource($provider, $resource)) {
        throw new RuntimeException('Invalid analytics resource');
    }
    $report = $handler->report($resource, $credential, $from, $to, $goal);
    if (($report['provider'] ?? null) !== $provider || ($report['resource'] ?? null) !== $resource
        || ($report['from'] ?? null) !== $from || ($report['to'] ?? null) !== $to
        || !is_array($report['metrics'] ?? null) || count($report['metrics']) === 0
        || !is_array($report['sections'] ?? null) || !isset($report['sections']['overview'])
        || !is_array($report['sections']['overview']['rows'] ?? null)
        || !is_string($report['fetched_at'] ?? null) || strtotime($report['fetched_at']) === false) {
        throw new RuntimeException('Provider returned an invalid report');
    }
    $sections = [];
    foreach ($report['sections'] as $name => $section) {
        if (!is_array($section) || !is_array($section['rows'] ?? null)) throw new RuntimeException('Invalid report section');
        $sections[$name] = ['rows' => count($section['rows']), 'truncated' => (bool)($section['truncated'] ?? false)];
    }
    // Only aggregate counts are printed; URL, labels, token, credentials, reports and individual queries are excluded.
    echo json_encode(['ok' => true, 'version' => trim((string)file_get_contents($root . '/VERSION')),
        'provider' => $provider, 'from' => $from, 'to' => $to,
        'sections' => $sections, 'validated_at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Analytics live acceptance failed; check the restricted staging log and provider configuration.\n");
    exit(1);
}
