<?php
declare(strict_types=1);
use CajeerEngine\Extension\Runtime\ExtensionContext;
return static function (ExtensionContext $context, ?PDO $pdo): void {
    if ($pdo !== null) $pdo->exec('CREATE TABLE IF NOT EXISTS ce_newsletter_summary (id INTEGER PRIMARY KEY, subscriber_count INTEGER NOT NULL)');
    else \CajeerEngine\Update\OperationFiles::json($context->paths()['storage'].'/migration-summary.json',['schema'=>1],0640);
};
