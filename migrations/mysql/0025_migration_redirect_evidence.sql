-- Durable project-owned evidence of legacy redirects created or verified by SEO import.
CREATE TABLE ce_migration_seo_path_evidence (
    project_id VARCHAR(32) NOT NULL,
    source_path VARCHAR(700) NOT NULL,
    target_path VARCHAR(1024) NOT NULL,
    PRIMARY KEY (project_id,source_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
