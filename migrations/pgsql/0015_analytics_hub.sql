CREATE TABLE IF NOT EXISTS analytics_connections (
    id VARCHAR(32) PRIMARY KEY,
    provider VARCHAR(16) NOT NULL,
    name VARCHAR(200) NOT NULL,
    resource_id VARCHAR(20) NOT NULL,
    goal_id VARCHAR(20) NULL,
    enabled INTEGER NOT NULL,
    revision INTEGER NOT NULL,
    credential TEXT NOT NULL,
    lease_token VARCHAR(32) NULL,
    lease_until BIGINT NOT NULL DEFAULT 0,
    last_attempt VARCHAR(40) NULL,
    last_success VARCHAR(40) NULL,
    last_error VARCHAR(200) NULL,
    last_job_key VARCHAR(256) NULL
);
CREATE TABLE IF NOT EXISTS analytics_reports (
    connection_id VARCHAR(32) NOT NULL,
    date_from VARCHAR(10) NOT NULL,
    date_to VARCHAR(10) NOT NULL,
    fetched_at VARCHAR(40) NOT NULL,
    report_json TEXT NOT NULL,
    PRIMARY KEY (connection_id,date_from,date_to)
);
