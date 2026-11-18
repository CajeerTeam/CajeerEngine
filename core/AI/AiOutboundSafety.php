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
        $walk = static function (mixed $node) use (&$walk, $patterns): void {
            if (is_string($node)) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $node) === 1) {
                        throw new AiProviderException('AI context contains credential material. Remove secrets before retrying.', 422);
                    }
                }
            } elseif (is_array($node)) {
                foreach ($node as $child) $walk($child);
            }
        };
        $walk($input);
    }
}
