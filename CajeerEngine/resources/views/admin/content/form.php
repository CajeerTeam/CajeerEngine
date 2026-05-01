<?php $token = $csrf->token(); ?>
<?php ob_start(); ?>
<section class="ce-card">
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="ce-form">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <label>Тип
            <select name="type">
                <?php foreach (['post' => 'Новость', 'page' => 'Страница', 'project' => 'Проект'] as $value => $label): ?>
                    <option value="<?= $value ?>" <?= (($item->type ?? 'post') === $value) ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Статус
            <select name="status">
                <?php foreach (['draft' => 'Черновик', 'scheduled' => 'Запланировано', 'published' => 'Опубликовано', 'archived' => 'Архив'] as $value => $label): ?>
                    <option value="<?= $value ?>" <?= (($item->status ?? 'draft') === $value) ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Заголовок <input name="title" value="<?= htmlspecialchars($item->title ?? '', ENT_QUOTES, 'UTF-8') ?>" required></label>
        <label>Slug <input name="slug" value="<?= htmlspecialchars($item->slug ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Дата публикации <input type="datetime-local" name="published_at" value="<?= isset($item->publishedAt) && $item->publishedAt ? date('Y-m-d\TH:i', strtotime($item->publishedAt)) : '' ?>"></label>
        <label>Видимость <input name="visibility" value="<?= htmlspecialchars($item->visibility ?? 'public', ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Порядок <input type="number" name="sort_order" value="<?= (int) ($item->sortOrder ?? 0) ?>"></label>
        <label>Category ID <input type="number" name="category_id" value="<?= (int) ($item->categoryId ?? 0) ?>"></label>
        <label>Краткое описание <textarea name="excerpt" rows="3"><?= htmlspecialchars($item->excerpt ?? '', ENT_QUOTES, 'UTF-8') ?></textarea></label>
        <label>Текст <textarea name="body" rows="12"><?= htmlspecialchars($item->body ?? '', ENT_QUOTES, 'UTF-8') ?></textarea></label>
        <h2>SEO</h2>
        <label>Meta title <input name="meta_title" value="<?= htmlspecialchars($item->metaTitle ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Meta description <textarea name="meta_description" rows="3"><?= htmlspecialchars($item->metaDescription ?? '', ENT_QUOTES, 'UTF-8') ?></textarea></label>
        <label>Canonical URL <input name="canonical_url" value="<?= htmlspecialchars($item->canonicalUrl ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Cover image <input name="cover_image" value="<?= htmlspecialchars($item->coverImage ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
        <button type="submit">Сохранить</button>
    </form>
</section>
<?php $content = ob_get_clean(); require __DIR__ . '/../../layout.php'; ?>
