<?php

declare(strict_types=1);

namespace CajeerEngine\Identity;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Runtime\ConfigRepository;

/** Read-only go-live checks for the live Identity service and local session registry. */
final readonly class IdentityAcceptance
{
    public function __construct(private ConfigRepository $config, private DatabaseManager $database) {}

    /** @return array<string,mixed> */
    public function run(bool $requireOidc = false, bool $probeIdp = false, bool $probeSite = false): array
    {
        $checks = [];
        $check = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
            $checks[$name] = ['ok'=>$ok,'detail'=>$detail];
        };
        $version = trim((string)@file_get_contents($this->config->rootPath().'/VERSION'));
        $check('version', $version === '1.5.9', 'Expected release 1.5.9');
        $driver = $this->database->driver();
        $check('database_driver', in_array($driver, ['pgsql','mysql','sqlite'],true), $driver);
        $migration = new IdentitySessionMigration($this->database, max(5,$this->config->int('security.sessions.idle_minutes',720)));
        $stats = null;
        try {
            $migration->requireSchema();
            $stats = $migration->status();
            $check('session_schema',true,'OIDC + assurance migrations applied');
            $check('session_registry_integrity', $stats['inconsistent_registry']===0, 'Incorrect token ownership or credential type: '.$stats['inconsistent_registry']);
            $check('session_migration_complete',$stats['unregistered']===0,'Unregistered active browser tokens: '.$stats['unregistered']);
        } catch (\Throwable $e) {
            $check('session_schema',false,$e->getMessage());
        }
        $strict = filter_var(getenv('IDENTITY_SESSION_STRICT') ?: 'false',FILTER_VALIDATE_BOOLEAN);
        $oidc = filter_var(getenv('SKIF4ER_ID_ENABLED') ?: 'false',FILTER_VALIDATE_BOOLEAN);
        $check('strict_rollout_safe',!$strict || ($stats!==null && $stats['unregistered']===0), $strict?'Strict session registry enabled':'Strict registry off: keep disabled until migration completes');
        if (($requireOidc || $probeIdp) && !$oidc) $check('oidc_enabled',false,'Enable SKIF4ER_ID_ENABLED before go-live');
        if ($oidc) {
            try {
                foreach (['curl','openssl','sodium'] as $extension) {
                    if (!extension_loaded($extension)) throw new \RuntimeException('Missing PHP extension: '.$extension);
                }
                $client = new OidcClient(
                    trim((string)(getenv('SKIF4ER_ID_CLIENT_ID') ?: '')),
                    (string)(getenv('SKIF4ER_ID_CLIENT_SECRET') ?: ''),
                    rtrim($this->config->string('app.url'),'/').'/auth/oidc/callback'
                );
                $vault = SecretVault::fromEnvironment('oidc');
                $sample='identity-go-live-'.bin2hex(random_bytes(8));
                if (!hash_equals($sample,$vault->open('identity:acceptance',$vault->seal('identity:acceptance',$sample)))) {
                    throw new \RuntimeException('OIDC encryption roundtrip failed.');
                }
                $check('oidc_configuration',true,'Confidential client, HTTPS callback and keyring valid');
                if ($probeIdp) {
                    try {
                        $tx = $client->begin(); // Fetch and pin real discovery; no token or callback is issued.
                        $url = parse_url($tx['url']);
                        $check('oidc_discovery',is_array($url) && ($url['scheme']??null)==='https','Pinned Keycloak discovery/authorization endpoint');
                    } catch (\Throwable $e) { $check('oidc_discovery',false,$e->getMessage()); }
                }
            } catch (\Throwable $e) {
                $check('oidc_configuration',false,$e->getMessage());
            }
        }
        if ($probeSite) {
            try {
                $this->verifyDeployedSite($oidc,$strict);
                $check('deployed_site',true,'HTTPS served by 1.5.9 with matching Identity settings');
            } catch (\Throwable $e) { $check('deployed_site',false,$e->getMessage()); }
        }
        $fail = array_keys(array_filter($checks,static fn(array $result):bool=>!$result['ok']));
        return ['ok'=>$fail===[], 'release'=>$version, 'oidc_enabled'=>$oidc,
            'session_strict'=>$strict,'checks'=>$checks,'failed_checks'=>$fail,'sessions'=>$stats];
    }
    /** Check actual public HTTPS deployment instead of assuming updated PHP-FPM workers. */
    private function verifyDeployedSite(bool $oidcEnabled, bool $strict): void
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL required for live site probe.');
        $base=rtrim($this->config->string('app.url'),'/');
        $parts=parse_url($base);
        if (!is_array($parts) || ($parts['scheme']??null)!=='https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('APP_URL must be absolute HTTPS and contain no userinfo/query/fragment.');
        }
        $ch=curl_init($base.'/api/v1/auth/oidc/status');
        if ($ch===false) throw new \RuntimeException('Unable to initialize live site probe.');
        $body=''; $version=null;
        try {
            curl_setopt_array($ch,[
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',
                CURLOPT_HTTPHEADER=>['Accept: application/json','Cache-Control: no-cache'],
                CURLOPT_HEADERFUNCTION=>static function ($ch,string $header) use (&$version):int {
                    if (preg_match('/^X-CajeerEngine-Version:\s*([^\r\n]+)/i',$header,$match)) $version=trim($match[1]);
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION=>static function ($ch,string $chunk) use (&$body):int {
                    if (strlen($body)+strlen($chunk)>32768) return 0;
                    $body.=$chunk;return strlen($chunk);
                },
            ]);
            if (!curl_exec($ch) || (int)curl_getinfo($ch,CURLINFO_HTTP_CODE)!==200) {
                throw new \RuntimeException('Live Identity endpoint unavailable or TLS validation failed.');
            }
        } finally { curl_close($ch); }
        $decoded=json_decode($body,true);
        if ($version!=='1.5.9' || !is_array($decoded) || !is_array($decoded['data']??null)
            || ($decoded['data']['enabled']??null)!==$oidcEnabled
            || ($decoded['data']['session_strict']??null)!==$strict) {
            throw new \RuntimeException('Running PHP workers differ from 1.5.9 Identity configuration; reload all workers.');
        }
    }
}
