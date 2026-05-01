<?php ob_start(); ?>
<section class="section section-first"><div class="container narrow center"><p class="eyebrow">Ошибка 404</p><h1>Страница не найдена</h1><p class="lead">Запрошенный раздел отсутствует, перемещён или недоступен по указанному адресу.</p><p><a class="button" href="/">Вернуться на главную</a></p></div></section>
<?php $content = ob_get_clean(); require dirname(__DIR__) . '/layout.php'; ?>
