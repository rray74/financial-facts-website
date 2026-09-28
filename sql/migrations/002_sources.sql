-- 002: Sources
--
-- One row per source page. Many facts usually come from the same page
-- (one GOV.UK page can back a dozen facts), so fetching and verifying
-- that page once can update last_verified_at on all of them.
--
-- is_allowlisted is the first automated gate: the pipeline can only
-- auto-publish facts whose source is allowlisted. Anything else goes to
-- the exceptions queue.
--
-- content_hash lets the pipeline skip work cheaply. If the relevant part
-- of a page hasn't changed since the last fetch, its facts haven't either.

CREATE TABLE sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    jurisdiction_id INT NULL,
    publisher VARCHAR(255) NOT NULL,         -- e.g. 'GOV.UK', 'Bank of England'
    title VARCHAR(255) NULL,                 -- page title
    url VARCHAR(500) NOT NULL,
    source_type ENUM('primary', 'secondary') NOT NULL DEFAULT 'primary',
    is_allowlisted TINYINT(1) NOT NULL DEFAULT 0,
    licence VARCHAR(100) NULL,               -- e.g. 'Open Government Licence v3.0'
    last_fetched_at DATETIME NULL,
    last_fetch_status VARCHAR(30) NULL,      -- e.g. 'ok', 'http_404', 'timeout'
    content_hash CHAR(64) NULL,              -- SHA-256 of the relevant page section
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_url (url),
    CONSTRAINT fk_sources_jurisdiction FOREIGN KEY (jurisdiction_id)
        REFERENCES jurisdictions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @gb := (SELECT id FROM jurisdictions WHERE code = 'GB');

-- Backfill one source per distinct URL already used by facts.
INSERT INTO sources (jurisdiction_id, publisher, url)
SELECT @gb, MIN(COALESCE(NULLIF(source_name, ''), 'Unknown')), source_url
FROM facts
WHERE source_url IS NOT NULL AND source_url <> ''
GROUP BY source_url;

-- Official UK sources are primary and allowlisted. Everything else
-- (e.g. Moneyfacts) is marked secondary and stays off the allowlist, so
-- its facts will be routed to the exceptions queue until reviewed.
UPDATE sources
SET is_allowlisted = 1,
    licence = 'Open Government Licence v3.0'
WHERE url REGEXP '^https?://([a-z0-9-]+\\.)*gov\\.uk(/|$)';

UPDATE sources
SET is_allowlisted = 1
WHERE url REGEXP '^https?://([a-z0-9-]+\\.)*(bankofengland\\.co\\.uk|fca\\.org\\.uk)(/|$)';

UPDATE sources SET source_type = 'secondary' WHERE is_allowlisted = 0;

-- Link facts to their source row. The old source_name / source_url
-- columns stay for now so the current site keeps working. A later
-- cleanup migration removes them once the PHP reads from sources.
ALTER TABLE facts
    ADD COLUMN source_id INT NULL AFTER context,
    ADD INDEX idx_source (source_id),
    ADD CONSTRAINT fk_facts_source FOREIGN KEY (source_id)
        REFERENCES sources(id) ON DELETE SET NULL;

UPDATE facts f
JOIN sources s ON s.url = f.source_url
SET f.source_id = s.id;
