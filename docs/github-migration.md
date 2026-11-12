# Перенос CajeerEngine на GitHub

Канонический репозиторий: `https://github.com/cajeerteam/CajeerEngine`. Миграция выполнена в 1.2.5; текущая версия ядра указана в `VERSION`. Если выбран другой владелец или репозиторий, перед публикацией измените `GitHubEndpoints::REPOSITORY`, проверку репозитория в workflow и скрипте публикации, а также ссылки в документации, `composer.json`, `release.json` и bundled metadata.

## Перенос истории

Создайте пустой репозиторий на GitHub (без README, LICENSE и начального коммита). Работайте в полном локальном Git-репозитории с историей, а не в ZIP исходников. Примените patch-архив к рабочей ветке, проверьте `git diff` и закоммитьте изменения. Если origin указывает на старый сервер:

```bash
git remote rename origin legacy
git remote add origin https://github.com/cajeerteam/CajeerEngine.git
git push -u origin main
git push origin --all
git push origin --tags
```

Сначала проверьте ветки/теги и наличие приватных секретов в истории; команды сохраняют историю локальных веток, но не переносят issues, wiki, пользователей, CI secrets или бинарные release assets. Не переписывайте существующие теги. Если тег `v1.2.5` уже существует на прежнем коммите, используйте новый согласованный релиз после миграции, либо перенесите старые подписанные файлы без пересборки под тем же тегом. Перенос исторических релизов требует загрузки соответствующих оригинальных файлов и проверки подписей. GitHub автоматически создаёт source snapshots — они не являются CajeerEngine dist.

## Настройка GitHub Actions

1. Создайте environment `release`, ограничьте его доверенными release-тегами и назначьте обязательных reviewers. Защитите основную ветку и теги от несанкционированного изменения.
2. В environment добавьте secret `RELEASE_SIGNING_KEY`: полное base64-содержимое действующего файла Ed25519 secret key, полученного `php bin/cajeer release:keygen`. Публичный ключ уже установленных CMS не меняется. Не генерируйте другой ключ без отдельной доверенной процедуры ротации. Не добавляйте secret в Git.
3. Закоммитьте проверенный `composer.lock`. Он отсутствует в исходном архиве; без него release pipeline преднамеренно завершается ошибкой. Разрешите GitHub Actions доступ к environment и создание release через `GITHUB_TOKEN` с `contents: write` только у publish job.
4. После проверки кода создайте и отправьте новый тег, строго совпадающий с `v` + `VERSION`. Например, для проверенного релиза 1.3.0:

```bash
git tag -a v1.3.0 -m 'CajeerEngine 1.3.0'
git push origin v1.3.0
```

`.github/workflows/release.yml` запускает прежние regression и smoke tests (включая PostgreSQL, SQLite и deployment). После успеха отдельный job проверяет tag/version/repository, ZIP и sidecar, подписывает ZIP закрытым ключом и создаёт **draft release**. Перед публикацией проверяет наличие, размер и (если предоставлен API) digest всех загруженных assets. При сбое draft остаётся скрытым. Workflow не перезаписывает существующий release; оператор должен проверить неудачный draft и удалить его вручную перед повтором. `workflow_dispatch` на ветке выполняет проверки, но не публикует релиз.

## Обновления CMS

`php bin/cajeer update:check --json` получает публичный JSON из `https://api.github.com/repos/cajeerteam/CajeerEngine/releases?per_page=100`. Используется ограниченный по размеру HTTPS-запрос с User-Agent, проверкой TLS и запретом redirect; авторизация не требуется для публичного репозитория. Обрабатываются до 100 последних релизов. Черновики, не-semver теги и релизы без загруженных dist ZIP, `.sha256` и `.sig` игнорируются. Стабильные релизы отделены от beta/rc/dev. При недоступности API, лимите запросов или ошибке JSON используется локальная/bundled metadata; успешный пустой список означает отсутствие опубликованных подходящих релизов.

Bundled catalog хранит историю версий и ожидаемые страницы тегов, но не подтверждает доступность исторических assets. Ссылки для загрузки появляются в live catalog только для действительно загруженных комплектов. Если на старой установке имеется вручную созданный `storage/app/updates/releases.json`, проверьте его ссылки и обновите или удалите этот override. Для mirror с собственным JSON сохраняется прежний формат `{"releases": [...]}` через конфигурацию `core.releases_url`.

Загрузка и применение пакета остаются отдельной операцией: скачайте `cajeerengine-VERSION-dist.zip`, `.sha256`, `.sig` с GitHub Releases (или `gh release download TAG --pattern 'cajeerengine-VERSION-dist.zip*'`). Передайте утверждённый SHA-256 и доверенный публичный ключ по действующей процедуре [Production updater & backup](update-backup.md). Метаданные GitHub не заменяют Ed25519-проверку; сервер никогда автоматически не исполняет скачанный ZIP.
