<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

/** Ed25519 detached signature over the exact ZIP digest (domain-separated). Keys are provisioned out of band. */
final class ReleaseSignature
{
    private const PREFIX = "CajeerEngine release ZIP SHA256 v1\n";
    public static function sign(string $zip, string $secretFile): array
    {
        if (!function_exists('sodium_crypto_sign_detached')) throw new \RuntimeException('ext-sodium required');
        if ((fileperms($secretFile) & 0077) !== 0) throw new \RuntimeException('Signing key must be private (0600)');
        $key = self::key($secretFile, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        try {
            $sha = OperationFiles::hash($zip);
            $public = sodium_crypto_sign_publickey_from_secretkey($key);
            $data = ['format'=>'cajeerengine.release-signature.v1','key_id'=>hash('sha256',$public),'sha256'=>$sha,'signature'=>base64_encode(sodium_crypto_sign_detached(self::PREFIX.$sha,$key))];
            OperationFiles::json($zip.'.sig', $data, 0644);
            OperationFiles::write($zip.'.sha256', $sha.'  '.basename($zip)."\n", 0644);
            return ['ok'=>true,'sha256'=>$sha,'signature'=>$zip.'.sig','key_id'=>$data['key_id']];
        } finally { sodium_memzero($key); }
    }
    public static function verify(string $zip, string $signatureFile, string $publicFile, ?string $expected = null): array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) throw new \RuntimeException('ext-sodium required');
        $public = self::key($publicFile, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        if (!is_file($signatureFile)||is_link($signatureFile)||filesize($signatureFile)>8192) throw new \RuntimeException('Invalid signature file');
        $data = OperationFiles::read($signatureFile);
        $sha = OperationFiles::hash($zip);
        $sig = base64_decode((string)($data['signature'] ?? ''), true);
        if (($data['format']??null)!=='cajeerengine.release-signature.v1' || ($data['key_id']??null)!==hash('sha256',$public) || ($data['sha256']??null)!==$sha || ($expected!==null && !hash_equals($sha,strtolower($expected))) || !is_string($sig) || strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig,self::PREFIX.$sha,$public)) throw new \RuntimeException('Release signature or SHA256 mismatch');
        return ['sha256'=>$sha,'key_id'=>$data['key_id']];
    }
    public static function generate(string $secretFile, string $publicFile): array
    {
        if (!function_exists('sodium_crypto_sign_keypair')) throw new \RuntimeException('ext-sodium required');
        if (file_exists($secretFile) || is_link($secretFile) || file_exists($publicFile) || is_link($publicFile) || $secretFile===$publicFile) throw new \RuntimeException('Refusing to overwrite signing keys');
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair); $public = sodium_crypto_sign_publickey($pair);
        try { OperationFiles::write($secretFile,base64_encode($secret)."\n"); OperationFiles::write($publicFile,base64_encode($public)."\n",0644); }
        finally { sodium_memzero($secret); sodium_memzero($pair); }
        return ['ok'=>true,'key_id'=>hash('sha256',$public),'public_key'=>$publicFile];
    }
    private static function key(string $path, int $size): string
    {
        if (!is_file($path) || is_link($path) || filesize($path)>4096) throw new \RuntimeException('Trusted key file missing');
        $key=base64_decode(trim((string)file_get_contents($path)),true);
        if (!is_string($key)||strlen($key)!==$size) throw new \RuntimeException('Invalid Ed25519 key');
        return $key;
    }
}
