<?php

declare(strict_types=1);

http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo 'Web upgrade disabled until CajeerEngine 1.1.4. Use update:check / update:prepare for read-only planning.';
