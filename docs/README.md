# Документация CajeerEngine

> Единая точка входа: от знакомства с продуктом до установки, обновления и восстановления. **Актуальная версия в этом наборе материалов — 2.1.0.**

**[← На главную](../README.md)** · **[Релиз 2.1.0](releases/2.1.0.md)** · **[Скачать](https://github.com/CajeerTeam/CajeerEngine/releases/tag/v2.1.0)** · **[История изменений](../CHANGELOG.md)**

---

## Выберите задачу

| Я хочу… | Начать здесь | Продолжить |
| :-- | :-- | :-- |
| **Понять, что это за CMS** | [Обзор продукта](product-overview.md) | [Каталог возможностей](product-features.md) |
| **Посмотреть устройство системы** | [Архитектура](product-architecture.md) | [Extension Platform](extensions-platform.md) |
| **Установить CMS** | [Быстрый старт и системные требования](getting-started-2.1.0.md) | [Подробная установка](install.md) |
| **Обновиться до 2.1.0** | [План перехода](releases/upgrade-2.1.0.md) | [Release notes](releases/2.1.0.md) |
| **Развернуть production** | [Профиль развёртывания](production-deployment.md) | [Production certification](production-certification.md) |
| **Разобраться со сбоем** | [Инциденты](operations-incidents-1.8.8.md) | [Восстановление](operations-recovery-1.8.8.md) |

## Путеводитель

### 01 / Продукт

- [CajeerEngine — обзор](product-overview.md): позиционирование, сценарии использования, ограничения.
- [Возможности](product-features.md): функции по направлениям и условия использования интеграций.
- [Архитектура](product-architecture.md): компоненты и поток запросов, границы ответственности.
- [Начало работы](getting-started-2.1.0.md): source или dist, требования, установка и первичная проверка.

### 02 / Релизы и обновления

- [Релизный центр](releases/README.md): правила публикации и навигация по выпускам.
- [Что нового в 2.1.0](releases/2.1.0.md): изменения, совместимость, границы квалификации.
- [Переход на 2.1.0](releases/upgrade-2.1.0.md): безопасная последовательность и проверочный список.
- [Подробное руководство GitHub Auto-Updater GA](github-auto-updater-ga-2.1.0.md).
- [Secure Release Downloader](secure-release-downloader-2.0.2.md), [Backup & Rollback](backup-rollback-integration-2.0.5.md), [Update History](update-history-notifications-2.0.6.md), [Production Hardening](update-production-hardening-2.0.7.md).

### 03 / Разработка и интеграции

- [REST/OpenAPI](../api/openapi.yaml) и [система расширений](extensions-platform.md).
- [Публичные маршруты](public-routing.md), [шаблоны и рендеринг админки](admin-rendering.md).
- [Media Library](media-library.md), [поиск](search-runtime.md), [SEO](seo-url-migration.md).
- [Queue & Scheduler](queue-scheduler.md), [Import/Export](import-export.md).
- [SkiF4er ID и OIDC](identity-skif4er-id-ga-1.6.0.md).
- [AI Assistant GA](ai-assistant-ga-1.7.0.md), [Knowledge/RAG](ai-knowledge-chat-rag-1.6.7.md), [Analytics Hub](analytics-hub.md).
- [Migration Center](migration-center.md), [DLE](dle-migration.md), [WordPress](wordpress-migration.md).

### 04 / Эксплуатация и безопасность

- [Операционный справочник](operations-handbook-1.8.8.md): поддерживаемый single-node профиль.
- [Развёртывание](operations-deployment-1.8.8.md), [мониторинг](operations-monitoring-1.8.8.md), [инциденты](operations-incidents-1.8.8.md).
- [Восстановление](operations-recovery-1.8.8.md), [безопасность и секреты](operations-security-1.8.8.md), [приёмка](operations-acceptance-1.8.8.md).
- [Политика безопасности](../SECURITY.md) и [production security](security-privacy-production-1.9.5.md).

> [!NOTE]
> Исторические эксплуатационные документы сохраняют версии в именах файлов (например, `1.8.8`), потому что фиксируют конкретный проверенный профиль. Они не означают, что текущая версия CMS — 1.8.8. Требования к 2.1.0 следует сверять с [`release.json`](../release.json), [релизными заметками](releases/2.1.0.md) и документом Auto-Updater GA.

## Доверие к артефактам и состоянию установки

- **Исходники (`source`)** нужны для изучения, разработки и сборки.
- **Дистрибутив (`dist`)** предназначен для развёртывания после проверки подписи и манифеста.
- **Зелёный CI** подтверждает прохождение настроенных автоматических проверок для конкретного коммита.
- **Production-допуск** дополнительно требует проверку конкретного артефакта, инфраструктуры и реального восстановления на staging.

При противоречии краткого обзора и подробной операционной процедуры для выполнения опасной операции используйте подробную процедуру и фактическое состояние установленной версии.
