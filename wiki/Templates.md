# Cajeer Template Engine

Cajeer Template Engine использует расширение `.cjr` и HTML-first синтаксис с Blade-like и Vue-like элементами.

## Базовые конструкции

```cjr
<h1>{{ $title }}</h1>
{!! $content !!}

@if ($published)
  <span>Опубликовано</span>
@endif
```

## Ограничения

Шаблоны выполняются через sandbox-слой и должны находиться внутри разрешённой директории шаблонов.
