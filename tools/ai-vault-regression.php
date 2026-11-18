<?php

declare(strict_types=1);

/** Executable without Composer or PDO: real XChaCha20 credential update/rotation tests. */
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CajeerEngine\\')) {
        $path = $root . '/core/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($path)) require $path;
    }
});
use CajeerEngine\AI\AiSettings;
use CajeerEngine\Integration\SecretVault;
$test = static function (bool $truth, string $label): void { if (!$truth) throw new RuntimeException('FAILED: '.$label); echo 'PASS ' . $label . "\n"; };
$dir = sys_get_temp_dir().'/ce-ai-vault-'.bin2hex(random_bytes(7));
mkdir($dir,0700);
$key1='base64:'.base64_encode(random_bytes(32));$key2='base64:'.base64_encode(random_bytes(32));
$previous = getenv('INTEGRATIONS_KEY'); $prevList = getenv('INTEGRATIONS_PREVIOUS_KEYS'); $oldEnv = getenv('OPENAI_API_KEY');
try {
    putenv('OPENAI_API_KEY=');putenv('INTEGRATIONS_KEY='.$key1);putenv('INTEGRATIONS_PREVIOUS_KEYS=');
    $settings = new AiSettings($dir);
    $test(!$settings->status()['configured'], 'AI disabled without credential');
    $configured = $settings->update(['api_key'=>'sk-test-12345678901234567890', 'model'=>'gpt-4.1-mini']);
    $test($configured['configured'] && $configured['credential_source'] === 'vault', 'AI credential configured');
    $saved = file_get_contents($dir.'/storage/app/ai/settings.json');
    $test(str_contains($saved,'v2:') && !str_contains($saved,'sk-test-12345678901234567890'),'vault encrypted and non-leaking');
    $test($settings->key() === 'sk-test-12345678901234567890', 'vault decrypts current key');
    $test((fileperms($dir.'/storage/app/ai/settings.json') & 0777) === 0600, 'vault file mode 0600');
    putenv('INTEGRATIONS_KEY='.$key2);
    try { $settings->key(); throw new LogicException('wrong key decrypted'); } catch (RuntimeException $e) { $test(true,'wrong key denied'); }
    putenv('INTEGRATIONS_PREVIOUS_KEYS='.$key1);
    $test($settings->key() === 'sk-test-12345678901234567890', 'previous key supported');
    $vault = SecretVault::fromEnvironment();
    $data = json_decode($saved,true,512,JSON_THROW_ON_ERROR);
    $data['key_ciphertext'] = $vault->rewrap('cajeerengine:ai:openai:key', $data['key_ciphertext']);
    file_put_contents($dir.'/storage/app/ai/settings.json',json_encode($data,JSON_THROW_ON_ERROR));
    putenv('INTEGRATIONS_PREVIOUS_KEYS=');
    $test($settings->key() === 'sk-test-12345678901234567890', 'rotation reads with new key alone');
    try { $settings->update(['model'=>"bad\nmodel"]); throw new LogicException('invalid model'); } catch (InvalidArgumentException $e) { $test(true,'invalid model blocked'); }
    $settings->update(['remove_key'=>true]);$test(!$settings->status()['configured'],'stored API key removed');
    echo "AI credential regression PASSED\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        if ($f->isDir()&&!$f->isLink()) rmdir($f->getPathname()); else unlink($f->getPathname());
    }
    rmdir($dir);
    foreach (['INTEGRATIONS_KEY'=>$previous,'INTEGRATIONS_PREVIOUS_KEYS'=>$prevList,'OPENAI_API_KEY'=>$oldEnv] as $name=>$value) putenv($name.'='.($value===false?'':$value));
}
