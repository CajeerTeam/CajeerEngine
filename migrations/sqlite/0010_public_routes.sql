CREATE TABLE ce_public_route_state (
    id INTEGER PRIMARY KEY,
    generation INTEGER NOT NULL
);
INSERT INTO ce_public_route_state (id, generation) VALUES (1, 0);
CREATE TABLE ce_public_routes (
    route_key CHAR(64) PRIMARY KEY,
    path VARCHAR(1024) NOT NULL,
    entry_id VARCHAR(64) NOT NULL,
    kind VARCHAR(8) NOT NULL,
    payload TEXT NOT NULL
);
CREATE INDEX ce_public_routes_entry_idx ON ce_public_routes (entry_id, kind);
CREATE INDEX ce_public_routes_path_idx ON ce_public_routes (path);
