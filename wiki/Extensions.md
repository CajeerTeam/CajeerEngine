# Расширения

CajeerEngine поддерживает три типа расширений:

- modules;
- plugins;
- themes.

Каждое расширение должно иметь `cajeer.extension.json` с типом, версией, engine API constraint, permissions и событиями.

## Runtime API 1.1

CajeerEngine 1.1.0 выполняет PHP-код расширений, а не только хранит manifest в registry.

### Manifest

```json
{
  "name": "vendor/news",
  "type": "module",
  "version": "0.1.0",
  "engine": "^1.1",
  "permissions": ["extension.runtime"],
  "events": ["kernel.booted", "content.saved"],
  "hooks": { "content.saved": "onContentSaved" },
  "providers": ["CajeerExtensions\\Vendor\\News\\NewsExtension"],
  "assets": { "source": "assets", "public": true },
  "migrations": { "path": "migrations" },
  "config": { "enabled_by_default": false }
}
```

### Provider

```php
use CajeerEngine\Extension\Contracts\AbstractExtensionProvider;
use CajeerEngine\Extension\Runtime\ExtensionContext;
use CajeerEngine\Runtime\RuntimeEvent;
use CajeerEngine\Runtime\ServiceContainer;

final class NewsExtension extends AbstractExtensionProvider
{
    public function register(ServiceContainer $container, ExtensionContext $context): void
    {
        $container->instance('extension.' . $context->slug() . '.config', $context->extensionConfig());
    }

    public function boot(ExtensionContext $context): void
    {
        $context->log('news.booted');
    }

    public function onContentSaved(RuntimeEvent $event, ExtensionContext $context): void
    {
        $context->log('news.content_saved', $event->payload);
    }
}
```

### CLI

```bash
php bin/cajeer extension:runtime --boot
php bin/cajeer extension:assets vendor/news
php bin/cajeer extension:migrate vendor/news
php bin/cajeer extension:event:dispatch content.saved --payload='{}'
```
