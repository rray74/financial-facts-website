-- 009: Cleanup after the PHP moved to the new structure
--
-- Run this together with the updated PHP files. The old PHP reads
-- facts.subject_id, source_name and source_url, all of which change here.
--
--   - facts.subject_id is renamed primary_subject_id, to say what it now
--     means: the one subject page that owns the fact.
--   - source_name / source_url are dropped. Sources live in the sources
--     table and facts point to them through source_id.
--   - facts.jurisdiction_id becomes required.
--
-- Each step first catches anything added between migrations 002 and
-- this one, so nothing is lost.

SET @gb := (SELECT id FROM jurisdictions WHERE code = 'GB');

-- Facts added since 003 without a jurisdiction are UK-wide.
UPDATE facts SET jurisdiction_id = @gb WHERE jurisdiction_id IS NULL;

-- Facts added since 004 with no page link at all get linked to their
-- owner. Retired facts are skipped, so the merged duplicate from 008
-- isn't put back on a page.
INSERT INTO subject_facts (subject_id, fact_id, sort_order)
SELECT f.subject_id, f.id, f.id * 10
FROM facts f
WHERE f.status <> 'retired'
  AND NOT EXISTS (SELECT 1 FROM subject_facts sf WHERE sf.fact_id = f.id);

-- Source URLs added since 002 get a sources row and a source_id.
INSERT IGNORE INTO sources (jurisdiction_id, publisher, url, source_type)
SELECT @gb, MIN(COALESCE(NULLIF(source_name, ''), 'Unknown')), source_url, 'secondary'
FROM facts
WHERE source_url IS NOT NULL AND source_url <> '' AND source_id IS NULL
GROUP BY source_url;

UPDATE facts f
JOIN sources s ON s.url = f.source_url
SET f.source_id = s.id
WHERE f.source_id IS NULL;

-- The rename keeps the existing foreign key to subjects in place.
ALTER TABLE facts
    CHANGE subject_id primary_subject_id INT NOT NULL,
    MODIFY jurisdiction_id INT NOT NULL,
    DROP COLUMN source_name,
    DROP COLUMN source_url;
