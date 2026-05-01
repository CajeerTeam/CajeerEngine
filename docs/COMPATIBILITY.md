# Совместимость DLE и WordPress

## 1. Общий принцип

Cajeer Engine не является форком DLE или WordPress. Совместимость реализуется через отдельные compatibility layers.

Цель compatibility layers:

- упростить миграцию;
- снизить стоимость перехода;
- дать возможность частичного запуска legacy-шаблонов и расширений;
- явно показывать ограничения через Compatibility Scanner.

## 2. Уровни совместимости

### Level 1 — Import Compatibility

Импорт данных из DLE и WordPress в нативную модель Cajeer.

### Level 2 — Template Compatibility

Поддержка шаблонов:

- WordPress classic PHP themes;
- DLE `.tpl` templates.

### Level 3 — Plugin / Module Compatibility

Частичный запуск:

- WordPress plugins через hooks/actions/filters;
- DLE modules через legacy runtime.

### Level 4 — Native Cajeer Extensions

Нативные расширения Cajeer Engine.

## 3. WordPress Compatibility Layer

Минимальный набор API:

- `add_action`;
- `do_action`;
- `remove_action`;
- `add_filter`;
- `apply_filters`;
- `remove_filter`;
- `get_option`;
- `update_option`;
- `delete_option`;
- `add_shortcode`;
- `do_shortcode`;
- `get_header`;
- `get_footer`;
- `get_sidebar`;
- `get_template_part`;
- `the_title`;
- `the_content`;
- `the_excerpt`;
- `the_permalink`;
- `have_posts`;
- `the_post`;
- `wp_head`;
- `wp_footer`.

### Ограничения WordPress-совместимости

На первом этапе не гарантируется полная поддержка:

- Gutenberg/block themes;
- WooCommerce;
- WP-CLI;
- WP-Cron;
- WordPress REST API;
- прямых SQL-запросов к `wp_*` таблицам;
- плагинов, завязанных на WordPress admin internals.

## 4. DLE Compatibility Layer

Минимальный набор:

- `.tpl` parser/compiler;
- `{title}`;
- `{short-story}`;
- `{full-story}`;
- `{author}`;
- `{date}`;
- `{category}`;
- `{comments}`;
- `{custom}`;
- `[aviable=...]...[/aviable]`;
- `[not-aviable=...]...[/not-aviable]`;
- `[category=...]...[/category]`;
- `[not-category=...]...[/not-category]`;
- `$config`;
- `$db`;
- `$tpl`;
- `$member_id`;
- `$is_logged`.

### Ограничения DLE-совместимости

На первом этапе не гарантируется полная поддержка:

- прямого изменения `engine/data/config.php`;
- зависимости от конкретной версии DLE internals;
- небезопасных `include`/`require`;
- прямого обращения к глобальным файлам движка;
- модулей без изоляции и проверки.

## 5. Compatibility Scanner

Scanner должен анализировать:

- неподдерживаемые функции;
- прямой SQL;
- обращения к legacy-файлам;
- рискованные `include`/`require`;
- зависимость от WordPress admin;
- зависимость от DLE engine internals;
- использование глобальных переменных;
- файловые операции;
- сетевые операции;
- потенциально опасные вызовы.

## 6. Статусы совместимости

Каждый шаблон, плагин или модуль должен получать один из статусов:

- `Compatible` — можно запускать без изменений;
- `Partially compatible` — нужен адаптер или ограничение функциональности;
- `Requires adaptation` — требуется ручная адаптация;
- `Unsupported` — запуск невозможен или небезопасен.

## 7. Безопасность legacy-кода

Legacy-код должен запускаться только после проверки.

Минимальные требования:

- запрет небезопасных путей;
- контроль include/require;
- логирование ошибок совместимости;
- безопасный режим для модулей;
- отключение выполнения неподдерживаемого кода по умолчанию.
