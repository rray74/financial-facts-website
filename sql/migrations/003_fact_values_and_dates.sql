-- 003: Typed values, jurisdiction, effective dates, verification, status
--
-- value stays as the text shown on the page. value_numeric holds the same
-- figure as a real number so it can be sorted, compared (for the change
-- threshold gate), charted, and formatted consistently.
-- value_display is an optional override for figures that don't format
-- neatly, e.g. '£0 to £125,000'.
--
-- effective_from is the date the current value took effect, e.g. the
-- start of a tax year. It is left NULL by this backfill rather than
-- guessed, because last_updated records when we changed the row, not
-- when the real-world figure changed.
--
-- last_verified_at is when the fact was last checked against its source,
-- whether or not the value changed. last_updated keeps its meaning: the
-- date the value itself last changed.

ALTER TABLE facts
    ADD COLUMN jurisdiction_id INT NULL AFTER subject_id,
    ADD COLUMN value_type ENUM('currency', 'percent', 'integer', 'decimal', 'date', 'text')
        NOT NULL DEFAULT 'text' AFTER value,
    ADD COLUMN value_numeric DECIMAL(18,4) NULL AFTER value_type,
    ADD COLUMN value_display VARCHAR(255) NULL AFTER value_numeric,
    ADD COLUMN effective_from DATE NULL AFTER last_updated,
    ADD COLUMN tax_year VARCHAR(9) NULL AFTER effective_from,   -- e.g. '2026/27'
    ADD COLUMN last_verified_at DATETIME NULL AFTER tax_year,
    ADD COLUMN status ENUM('draft', 'published', 'retired')
        NOT NULL DEFAULT 'published' AFTER last_verified_at;

SET @gb := (SELECT id FROM jurisdictions WHERE code = 'GB');

-- Every existing fact is UK-wide.
UPDATE facts SET jurisdiction_id = @gb;

-- Parse plain numbers ('4.25', '125,000') into value_numeric. Anything
-- that isn't a plain number is left NULL and typed as text below.
UPDATE facts
SET value_numeric = CAST(REPLACE(value, ',', '') AS DECIMAL(18,4))
WHERE REPLACE(value, ',', '') REGEXP '^-?[0-9]+(\\.[0-9]+)?$';

UPDATE facts
SET value_type = CASE
    WHEN value_numeric IS NULL THEN 'text'
    WHEN unit = '%' THEN 'percent'
    WHEN unit IN ('£', '$', '€') THEN 'currency'
    WHEN value_numeric = FLOOR(value_numeric) THEN 'integer'
    ELSE 'decimal'
END;

-- Existing facts were checked when they were last updated.
UPDATE facts SET last_verified_at = last_updated;

-- jurisdiction_id stays nullable during the transition, so the existing
-- import script keeps working until it's updated to set it. The cleanup
-- migration makes it NOT NULL.
--
-- New facts default to draft from now on. Existing ones stay published.
ALTER TABLE facts
    ALTER COLUMN status SET DEFAULT 'draft',
    ADD INDEX idx_status (status),
    ADD INDEX idx_last_verified (last_verified_at),
    ADD CONSTRAINT fk_facts_jurisdiction FOREIGN KEY (jurisdiction_id)
        REFERENCES jurisdictions(id) ON DELETE RESTRICT;
