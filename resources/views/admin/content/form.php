<?php ob_start(); $isEdit = $item !== null; ?>
<section class="ce-card">
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="ce-form">
        <?= $csrf->field() ?>
        <div class="ce-grid two">
            <label>Тип <select name="type"><option value="post" <?= $isEdit && $item->type === 'post' ? 'selected' : '' ?>>Новость</option><option value="page" <?= $isEdit && $item->type === 'page' ? 'selected' : '' ?>>Страница</option></select></label>
            <label>Статус <select name="status"><option value="draft" <?= $isEdit && $item->status === 'draft' ? 'selected' : '' ?>>Черновик</option><option value="published" <?= $isEdit && $item->status === 'published' ? 'selected' : '' ?>>Опубликовано</option></select></label>
        </div>
        <label>Заголовок <input name="title" value="<?= htmlspecialchars($isEdit ? $item->title : '', ENT_QUOTES, 'UTF-8') ?>" required></label>
        <label>Slug <input name="slug" value="<?= htmlspecialchars($isEdit ? $item->slug : '', ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Краткое описание <textarea name="excerpt" rows="3"><?= htmlspecialchars($isEdit ? (string) $item->excerpt : '', ENT_QUOTES, 'UTF-8') ?></textarea></label>
        <label>Контент <textarea name="body" rows="14"><?= htmlspecialchars($isEdit ? (string) $item->body : '', ENT_QUOTES, 'UTF-8') ?></textarea></label>
        <button class="ce-button" type="submit">Сохранить</button>
    </form>
</section>
<?php $content = ob_get_clean(); require dirname(__DIR__, 2) . '/layout.php'; ?>
