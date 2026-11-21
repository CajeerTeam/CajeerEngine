# CajeerEngine 2.1.0 — Security Operations & Secret Rotation

## Границы доверия

Секреты приложения (`APP_KEY`, `JWT_SECRET`, `API_TOKEN_SECRET`, `INTEGRATIONS_KEY`, `SKIF4ER_ID_CLIENT_SECRET`), ключи backup и release signing key выполняют **разные роли**. Ни один из них не должен попадать в браузер или `storage/app/reports`, а подписывающий приватный ключ не должен находиться на production-сервере. Публичный ключ в `UPDATE_TRUSTED_PUBLIC_KEY_FILE` не является секретом, но его подмена позволит принять чужой релиз: обновляйте trust root только по независимому утверждённому каналу. Доступ к `.env` и encrypted backup ключу должен быть ограничен специальной сервисной группой/оператором.

Production настройки:

```dotenv
APP_ENV=production
APP_DEBUG=false
SECURITY_REQUIRE_AUTH=true
SECURITY_PROTECT_CONTENT_WRITES=true
SECURITY_PROTECT_SYSTEM_ROUTES=true
UPDATE_SIGNATURE_REQUIRED=true
UPDATE_WEB_UPGRADE_ENABLED=false
EXTENSIONS_REQUIRE_SIGNATURE=true
SKIF4ER_ID_AUTO_PROVISION=false
```

Подробнее об атомарном расходовании recovery-кодов, приватных audit-событиях и защите runtime-логов: [Security & Privacy Production](security-privacy-production-1.9.5.md).

Подробности: [Security Hardening](security-hardening-1.8.2.md), [Identity & RBAC](identity-rbac-authentication-assurance.md), [OIDC Resource Server](oidc-api-authentication.md), [Security Resilience](security-resilience-1.5.8.md).

## Ежедневная проверка

```bash
php bin/cajeer security:doctor
php bin/cajeer doctor --json --no-report
php bin/cajeer ops:check
php bin/cajeer update:status
```

Проверьте HTTPS и срок сертификата, недоступность `/storage` и `.env` через HTTP, безопасность cookie/CSRF, актуальные зависимости и результаты security CI. Высокорисковые изменения (права, токены, MFA, привязка SSO, secret rotation) требуют отдельного аудита и подтверждения. API-вызовы не должны получать права шире локального RBAC, даже при наличии действительного внешнего access token. Не раскрывайте личные данные в тикетах и скриншотах диагностики.

## Отзыв и ротация секретов

1. При подозрении на утечку **сначала** изолируйте affected integrations и неавторизованных клиентов, ограничьте выдачу новых токенов, сохраните безопасные forensic evidence.
2. Разделите типы: украденный API token можно отозвать отдельно; compromised OIDC client secret требует ротации в IdP и CMS; потеря `INTEGRATIONS_KEY` требует утверждённой процедуры повторного шифрования; изменение `BACKUP_KEY_FILE` не делает старые backup расшифровываемыми новым ключом; компрометация release trust root требует release-security response и проверки артефактов.
3. Для ключей прикладного шифрования предварительно выполните исправную резервную копию и изучите [security-resilience](security-resilience-1.5.8.md). Команда `keys:rekey` **изменяет данные**; не выполняйте её без проверенного старого ключа и rollback-плана.

```bash
php bin/cajeer keys:status
php bin/cajeer backup:list
```

4. После ротации отзовите старые сессии/токены в соответствующих реестрах, проверьте доступность сценариев логина и интеграций. Для OIDC сохраните контроль issuer, audience, `sub`, PKCE и списки разрешённых клиентов. Не объединяйте аккаунты автоматически по совпадающему email.

## Исходящие вызовы (webhooks, AI, integration connectors)

Webhook должен использовать HTTPS, публичный разрешённый endpoint, отдельный signing secret и защиту от SSRF. Смена адреса назначения требует ротации signing secret. При ответе получателя нельзя сохранять тела ответов и чувствительные transport diagnostics; receiver обязан дедуплицировать `event_id`/`Idempotency-Key`. Не используйте HTTP через редиректы или собственные небезопасные host exceptions.

AI Automation исполняется только при действующих локальных правах владельца, учёте budget и разрешённых сценариях; хранение prompts регулируется retention. В кризисе отключите новые задания через `AI_AUTOMATION_ENABLED=false`, перезапустите соответствующие процессы и оцените уже выполняющиеся операции отдельно. Отключение не возвращает биллинговую транзакцию, уже принятую API-провайдером.

## Инцидент безопасности

Классифицируйте severity, зафиксируйте время обнаружения UTC, host/version/SHA, affected credentials, доказательства доступа и независимый журнал действий. Не изменяйте или не удаляйте логи до сохранения форензик-копии. Оцените disclosure по требованиям вашей организации; не делайте выводы на основании только `security:doctor`. Порядок изоляции/восстановления: [incident runbook](operations-incidents-1.8.8.md) и [DR runbook](operations-recovery-1.8.8.md).
