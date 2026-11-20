<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

/**
 * Last-mile protection for information crossing the CMS/OpenAI trust boundary.
 * A matched secret fails the request closed; it is never logged or included in the error.
 * This is intentionally limited to credential-shaped values, not ordinary PII or prose.
 */
final class AiOutboundSafety
{
    /** @param list<array<string,mixed>> $input */
    public static function assertNoCredentials(array $input): void
    {
        $serialized = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        // The limit applies before curl is initialized, even if an upstream caller forgot its own cap.
        if (strlen($serialized) > 100000) {
            throw new AiProviderException('AI request context exceeds safety limits.', 422);
        }
        $patterns = [
            '/-----BEGIN (?:ENCRYPTED |RSA |EC |OPENSSH )?PRIVATE KEY-----/i',
            '/\bsk-(?:proj-|svcacct-)?[A-Za-z0-9_-]{20,}\b/',
            '/\bBearer\s+(?:eyJ[A-Za-z0-9_-]{12,}\.[A-Za-z0-9_-]{12,}\.[A-Za-z0-9_-]{8,}|[A-Za-z0-9._~+\/-]{28,})/i',
            '/\beyJ[A-Za-z0-9_-]{16,}\.eyJ[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{8,}\b/',
            '/\b(?:OPENAI_API_KEY|INTEGRATIONS_KEY|DB_PASSWORD|JWT_SECRET|API_TOKEN_SECRET|WEBHOOK_SIGNING_SECRET|client_secret|refresh_token|session_token)\s*[=:]\s*["\x27]?[^\s"\x27&,;}{]{12,}/i',
            '/\b(?:password|api[_-]?key|access[_-]?token)\s*[=:]\s*["\x27]?[A-Za-z0-9_\/.+=-]{24,}/i',
        ];
        // JSON-encoded outputs are common for AI tools. Inspect their keys as well
        // as prose: short credentials in a field named client_secret or password
        // would otherwise bypass the value-only patterns.
        $credentialFields = ['password'=>true, 'passwd'=>true, 'api_key'=>true,
            'access_token'=>true, 'refresh_token'=>true, 'session_token'=>true,
            'client_secret'=>true, 'jwt_secret'=>true, 'secret_key'=>true,
            'private_key'=>true, 'authorization'=>true, 'webhook_signing_secret'=>true,
            'openai_api_key'=>true, 'db_password'=>true, 'integrations_key'=>true,
            'aws_secret_access_key'=>true];
        $walk = static function (mixed $node, int $depth = 0) use (&$walk, $patterns, $credentialFields): void {
            if ($depth > 24) throw new AiProviderException('AI context nesting exceeds safety limits.', 422);
            if (is_string($node)) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $node) === 1) {
                        throw new AiProviderException('AI context contains credential material. Remove secrets before retrying.', 422);
                    }
                }
                $trim = ltrim($node);
                if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[') && strlen($trim) <= 100000) {
                    $json = json_decode($trim, true, 24);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) $walk($json, $depth + 1);
                }
            } elseif (is_array($node)) {
                foreach ($node as $key => $child) {
                    if (is_string($key) && isset($credentialFields[strtolower(str_replace('-', '_', $key))])
                        && $child !== null && $child !== '' && $child !== []) {
                        throw new AiProviderException('AI context contains credential material. Remove secrets before retrying.', 422);
                    }
                    $walk($child, $depth + 1);
                }
            }
        };
        $walk($input);
    }
}
