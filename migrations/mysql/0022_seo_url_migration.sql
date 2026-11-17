-- SEO migration receipts and owned path/query mappings.
CREATE TABLE IF NOT EXISTS ce_migration_seo_runs (
    project_id VARCHAR(32) PRIMARY KEY,
    configuration_sha256 VARCHAR(64) NOT NULL,
    completed_at VARCHAR(40) NOT NULL,
    result LONGTEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS ce_migration_seo_meta (
    target_id VARCHAR(36) PRIMARY KEY,
    project_id VARCHAR(32) NOT NULL,
    title VARCHAR(300) NOT NULL,
    description TEXT NOT NULL,
    canonical_url VARCHAR(1500) NOT NULL,
    noindex SMALLINT NOT NULL DEFAULT 0,
    created_at VARCHAR(40) NOT NULL
);
CREATE TABLE IF NOT EXISTS ce_migration_seo_query_urls (
    query_key VARCHAR(40) PRIMARY KEY,
    project_id VARCHAR(32) NOT NULL,
    target_path VARCHAR(1024) NOT NULL,
    created_at VARCHAR(40) NOT NULL
);
CREATE INDEX idx_migration_seo_meta_project ON ce_migration_seo_meta(project_id);
