<?php
declare(strict_types=1);

namespace CajeerEngine\Migration;

/** Bounded XMLReader scan: namespaces are checked, no DTD, no external entities. */
final class WxrDiscovery
{
    public const MAX_BYTES = 32 * 1024 * 1024;

    public function scan(#[\SensitiveParameter] string $xml): array
    {
        if ($xml === '' || strlen($xml) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('WXR must be 1 byte to 32 MiB.');
        }
        if (str_contains($xml, "\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) {
            throw new \InvalidArgumentException('WXR DTD and entities are forbidden. Use UTF-8 WXR.');
        }
        if (!class_exists(\XMLReader::class)) {
            throw new \RuntimeException('WXR discovery requires ext-xmlreader.');
        }

        $old = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new \XMLReader();
        $state = new WxrDiscoveryScanState();
        try {
            if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new \InvalidArgumentException('Invalid WXR XML.');
            }
            $reader->setParserProperty(\XMLReader::LOADDTD, false);
            $reader->setParserProperty(\XMLReader::SUBST_ENTITIES, false);
            $reader->setParserProperty(\XMLReader::VALIDATE, false);
            while ($reader->read()) {
                $state->accept($reader);
            }
            if (libxml_get_errors() !== []) {
                throw new \InvalidArgumentException('Invalid or unsupported WordPress WXR (1.0–1.2 required).');
            }
            return $state->report($xml);
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }
}

