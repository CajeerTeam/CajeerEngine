CREATE TABLE IF NOT EXISTS ce_extension_migrations (
    extension_name VARCHAR(190) NOT NULL,
    migration_id VARCHAR(190) NOT NULL,
    checksum CHAR(64) NOT NULL,
    applied_at VARCHAR(32) NOT NULL,
    PRIMARY KEY (extension_name, migration_id)
);
