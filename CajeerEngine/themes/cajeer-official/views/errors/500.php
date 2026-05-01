<?php ob_start(); ?>
<section class="section section-first"><div class="container narrow center"><p class="eyebrow">Ошибка <?= (int) ($status ?? 500) ?></p><h1>Внутренняя ошибка</h1><p class="lead"><?= e($message ?? 'Сервер временно не может обработать запрос.') ?></p><p><a class="button" href="/">Вернуться на главную</a></p></div></section>
<?php $content = ob_get_clean(); require dirname(__DIR__) . '/layout.php'; ?>
