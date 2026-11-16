<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics\Reporting;

interface ReportProvider
{
    public function id(): string;
    public function validateCredential(array $credential): void;
    public function report(string $resource, #[\SensitiveParameter] array $credential, string $from, string $to, ?string $goal): array;
}
