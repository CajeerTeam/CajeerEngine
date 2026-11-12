# Extension Platform 1.1.6

Модули, плагины и темы — доверенный PHP-код. Устанавливайте только проверенный код издателей, которым доверяете доступ к приложению и данным. SDK permissions ограничивают регистрацию возможностей через SDK, но не изолируют PHP-процесс, файловую систему или БД. Непроверенный код запускайте вне CMS.

## Настройка доверия и backup

Оператор хранит ключи вне document root и дерева релиза. На машине издателя создайте ключ (не передавайте приватный ключ серверу):

```sh
php bin/cajeer release:keygen --secret-key=/secure/cajeer.secret --public-key=/secure/cajeer.pub
```

Скопируйте публичный ключ в защищённый каталог доверия сервера под именем издателя: для `cajeer/newsletter` это `cajeer.pub`. Например, задайте в `.env`:

```dotenv
EXTENSIONS_TRUST_DIR=/etc/cajeer/trusted-extensions
EXTENSIONS_ALLOW_UNSIGNED_IN_DEV=false
BACKUP_KEY_FILE=/secure/cajeer-backup.key
```

Каталог доверия и код расширений должны быть доступны PHP-FPM только для чтения, ключ backup — только оператору. Пользователи PHP-FPM и CLI должны иметь согласованные права на рабочие данные (общая выделенная группа, runtime-директории, без world-writable). Приватные ключи не включаются в пакеты. Подготовьте backup согласно [руководству](update-backup.md); PostgreSQL recovery требует владельца выделенной БД с CREATEDB, MySQL — проверенной процедуры восстановления. Поддерживается базовый single-node профиль с local storage и file/PostgreSQL queue; внешние хранилища требуют отдельной стратегии backup. Core migrations должны быть применены до установки расширений.

## Пример рабочего модуля

Из source-репозитория скопируйте `examples/extensions/newsletter` во временную директорию вне CMS. Подпишите её на машине издателя, затем передайте подписанную директорию или ZIP (файлы в корне ZIP, без внешней папки) оператору:

```sh
php bin/cajeer extension:sign /tmp/newsletter --secret-key=/secure/cajeer.secret
php bin/cajeer extension:package:install /tmp/newsletter --enable
php bin/cajeer extension:runtime --json
```

Подпись защищает весь файловый состав: manifest, providers, migrations, templates, assets. При изменении любого файла проверка не пройдёт. Пакет ограничен 128 MiB распакованных файлов и 4096 файлов; symlinks, traversal и исполняемые public assets отклоняются. Отсутствие подписи не допускается при package install/upgrade. Старый dev-runtime разрешает unsigned только при явном opt-in вне production.

В разделе Extensions админки появится страница Newsletter. Форма сохраняет email, ставит задачу snapshot в очередь, scheduler обновляет сводку. Это рабочий пример управления подписчиками, а не рассылка писем. Для запуска фоновых задач используйте штатные `queue:work` и `scheduler:run`. Действия доступны через `GET/POST /api/v1/extensions/actions/cajeer--newsletter/{action}`; чтение требует extensions:read, изменение — административных прав и CSRF для cookie-сессий. UI использует безопасный ограниченный формат form/data, а не исполняет произвольный HTML из API.

В провайдере `ExtensionContext::registrations()` предоставляет `service`, `action`, `page`, `job`, `task`. Имена сервисов и задач принадлежат namespace расширения. Для регистрации нужны соответствующие `services.register`, `routes.register`, `admin.register`, `queue.register`, `scheduler.register`; дополнительные операции SDK проверяются `requirePermission()`. Регистрируемые задачи scheduler имеют минутный интервал и блокировку повторного запуска на одном узле. Обработчики должны быть идемпотентными. Существующие event listeners вызываются через runtime. Произвольные public routes не регистрируются: используется фиксированный защищённый API gateway.

## Зависимости, обновления и удаление

`requires` и `conflicts` — карты имени расширения на ограничение версии; поддерживаются точная версия, `^`, `~`, сравнения и `*`. Зависимости должны быть установлены и включены; циклы и несовместимые версии блокируют активацию. Одновременно активна одна тема.

```sh
php bin/cajeer extension:package:enable cajeer/newsletter
php bin/cajeer extension:package:upgrade /tmp/newsletter-v2.zip
php bin/cajeer extension:disable cajeer/newsletter
php bin/cajeer extension:package:uninstall cajeer/newsletter
```

Upgrade требует строго более новой версии того же типа, не разрешает незаметно расширять permissions. Для новых разрешений требуется явное решение оператора: отключение, резервная копия, переустановка с сохранением данных и проверка миграций. Удаление по умолчанию сохраняет данные; вызов потенциально destructive uninstall hook требует `--purge-lifecycle --yes`. Зависимые активные расширения блокируют отключение/удаление зависимости. История миграций сохраняется в `ce_extension_migrations`; изменённые уже применённые файлы отклоняются. Миграции DDL MySQL могут делать implicit commit, поэтому восстановление обеспечивается полным backup, а не только SQL rollback.

Для темы используйте `examples/extensions/clean-theme`, подпишите аналогично и установите с `--enable`. Тема поставляет `templates/page.cjr`; public renderer использует её шаблон, а без активной темы — встроенные шаблоны. Тема не должна содержать пользовательские данные.

## Восстановление и переход с 1.1.5

Перед обновлением ядра отключите старые расширения, проверьте их источники, сформируйте новые подписанные пакеты. Старые подписи только manifest больше не доверенные. Не переносите прежние JSON migration records в БД вслепую: сопоставьте фактически применённые SQL/PHP миграции с их checksum и новой историей, проверив на резервной копии. Ledger блокирует автоматическое повторное выполнение при наличии legacy history. Для несовместимого старого пакета необходима подготовленная новая версия и проверенный переход; не удаляйте историю для обхода проверки.

Установка/upgrade/activation/uninstall через package CLI используют общий exclusive operation lock, backup, maintenance, durable deployment journal. Если процедура прервалась, не снимайте maintenance вручную:

```sh
php bin/cajeer extension:package:recover
```

Recovery проверяет backup и ждёт освобождения блокировки дочерней DB-операции. Он восстанавливает весь снимок установки; это не изолированная транзакция произвольного внешнего сервиса. Отдельно проверьте эффекты provider-кода, выходящие за границы CMS (например, отправку email). Установка и обновление выполняются оператором CLI, административный интерфейс не получает права менять PHP-код или читать backup key. Встроенного удалённого marketplace и установки зависимостей из интернета нет.

## Проверки

`php tools/extension-regression.php` проверяет подпись ZIP, реальные lifecycle, queue worker, scheduler, тему, зависимости и backup/recovery. В release CI тест запускается с PDO SQLite; PostgreSQL и MySQL миграции требуют отдельных интеграционных прогонов перед эксплуатацией. `php bin/cajeer api:lint --strict --json` проверяет runtime API и OpenAPI.
