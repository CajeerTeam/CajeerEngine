<?php ob_start(); ?>
<section class="ce-card">
    <div class="ce-toolbar"><h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1><a class="ce-button" href="/admin/content/create">Создать</a></div>
    <table class="ce-table">
        <thead><tr><th>ID</th><th>Тип</th><th>Статус</th><th>Заголовок</th><th>Slug</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?= (int) $item->id ?></td><td><?= htmlspecialchars($item->type, ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($item->status, ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($item->slug, ENT_QUOTES, 'UTF-8') ?></td>
                <td class="ce-actions-cell"><a href="/admin/content/<?= (int) $item->id ?>/edit">Править</a><form method="post" action="/admin/content/<?= (int) $item->id ?>/delete" onsubmit="return confirm('Удалить материал?')"><?= $csrf->field() ?><button type="submit">Удалить</button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php $content = ob_get_clean(); require dirname(__DIR__, 2) . '/layout.php'; ?>
