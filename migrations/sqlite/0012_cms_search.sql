ALTER TABLE ce_public_route_state ADD COLUMN search_version INTEGER NOT NULL DEFAULT 0;
CREATE TABLE ce_cms_search (
    document_id VARCHAR(160) PRIMARY KEY,
    entry_id VARCHAR(64) NOT NULL,
    content_type VARCHAR(100) NOT NULL,
    locale VARCHAR(32) NOT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    title_norm TEXT NOT NULL,
    body_norm TEXT NOT NULL,
    payload TEXT NOT NULL,
    digest CHAR(64) NOT NULL
);
CREATE INDEX ce_cms_search_filter_idx ON ce_cms_search (locale, content_type);
