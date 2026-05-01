<?php

declare(strict_types=1);

namespace Cajeer\Compatibility\Dle\Migration;

final class DleImportPlan
{
    public function tables(): array
    {
        return [
            'dle_post' => 'cajeer_content',
            'dle_category' => 'cajeer_categories',
            'dle_users' => 'cajeer_users',
            'dle_comments' => 'cajeer_comments',
        ];
    }

    public function phases(): array
    {
        return [
            'scan' => 'Проверка структуры DLE',
            'map' => 'Построение карты полей',
            'import_users' => 'Импорт пользователей',
            'import_categories' => 'Импорт категорий',
            'import_content' => 'Импорт новостей',
            'import_comments' => 'Импорт комментариев',
            'legacy_urls' => 'Генерация legacy URL map',
            'report' => 'Отчёт о совместимости',
        ];
    }
}
