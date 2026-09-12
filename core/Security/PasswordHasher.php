<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

use CajeerEngine\Runtime\ConfigRepository;

final readonly class PasswordHasher
{
    public function __construct(private ConfigRepository $config)
    {
    }

    public function hash(string $plain): string
    {
        $this->assertStrong($plain);
        $algorithm = $this->algorithm();
        $options = $this->options();
        $hash = password_hash($plain, $algorithm, $options);
        if ($hash === false) {
            throw new \RuntimeException('Не удалось создать хэш пароля.');
        }
        return $hash;
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm(), $this->options());
    }

    public function assertStrong(string $plain): void
    {
        if (mb_strlen($plain) < $this->config->int('security.password.min_length', 12)) {
            throw new \InvalidArgumentException('Пароль должен быть не короче ' . $this->config->int('security.password.min_length', 12) . ' символов.');
        }
        if ($this->config->bool('security.password.require_mixed', true)) {
            if (!preg_match('/[a-zа-яё]/iu', $plain) || !preg_match('/[A-ZА-ЯЁ]/u', $plain) || !preg_match('/\d/u', $plain)) {
                throw new \InvalidArgumentException('Пароль должен содержать строчные буквы, заглавные буквы и цифры.');
            }
        }
    }

    private function algorithm(): string|int|null
    {
        $configured = $this->config->get('security.password.algorithm', PASSWORD_ARGON2ID);
        if (is_string($configured) && defined($configured)) {
            return constant($configured);
        }
        if ($configured === 'argon2id') {
            return PASSWORD_ARGON2ID;
        }
        if ($configured === 'bcrypt') {
            return PASSWORD_BCRYPT;
        }
        return $configured;
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        $options = $this->config->get('security.password.options', []);
        return is_array($options) ? $options : [];
    }
}
