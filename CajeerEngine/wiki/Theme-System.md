# Система тем

Cajeer Engine использует нативные темы в каталоге `themes/{slug}` и публикует только статические assets из `public/themes/{slug}/assets`.

## Активная тема

Активная тема хранится в настройке `active_theme`. По умолчанию используется `cajeer-official`.

## Структура темы

```text
themes/cajeer-official/
├── extension.json
├── theme.php
├── views/
│   ├── layout.php
│   ├── home.php
│   ├── page.php
│   ├── projects.php
│   ├── project.php
│   └── errors/
└── assets/
```

Публичные файлы темы должны лежать отдельно:

```text
public/themes/cajeer-official/assets/
```

## Проверка

```bash
php scripts/check_theme.php cajeer-official
```

## Импорт статического шаблона

```bash
php bin/cajeer theme:import-website /path/to/website-main --name=cajeer/imported-theme
```
