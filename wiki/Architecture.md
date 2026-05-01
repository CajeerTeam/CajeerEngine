# Architecture

Архитектура: modular monolith + plugin-first core + compatibility adapters.

```text
Nginx → PHP-FPM → public/index.php → Kernel → Middleware → Router → Module/Controller → Repository → DBAL → PostgreSQL/MySQL
```

DLE и WordPress не являются ядром. Они подключаются через `compatibility/dle` и `compatibility/wordpress`.
