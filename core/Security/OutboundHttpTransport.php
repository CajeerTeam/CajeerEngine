<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

interface OutboundHttpTransport
{
    /** @return array{0:int,1:string} */
    public function request(string $url, string $method, array $headers = [], string $body = '', int $maxBytes = 1048576): array;
}
