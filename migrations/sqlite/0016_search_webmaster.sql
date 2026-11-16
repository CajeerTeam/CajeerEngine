-- SQLite does not enforce VARCHAR lengths. Index supports the shared search/traffic provider registry.
CREATE INDEX IF NOT EXISTS idx_analytics_connections_provider ON analytics_connections(provider);
