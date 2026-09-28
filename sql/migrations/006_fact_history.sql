-- 006: Fact history
--
-- One row every time a fact's value changes, recording what it changed
-- from and to, when it took effect, and what caused the change. This is
-- the audit trail, and also the raw material for "rose from X to Y"
-- content and charts over time.
--
-- Rows are written by PHP in the same transaction as the fact update
-- (one shared function used by the admin app, pipeline and importer),
-- rather than by a MySQL trigger, because triggers are awkward to create
-- on shared hosting.
--
-- The FK to facts is RESTRICT, so a fact with history can't be deleted.
-- Facts should be retired (status = 'retired') instead, which keeps the
-- record intact.

CREATE TABLE fact_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    fact_id INT NOT NULL,
    old_value VARCHAR(255) NULL,             -- NULL for the first recorded value
    old_value_numeric DECIMAL(18,4) NULL,
    new_value VARCHAR(255) NOT NULL,
    new_value_numeric DECIMAL(18,4) NULL,
    effective_from DATE NULL,
    change_source ENUM('manual', 'import', 'pipeline', 'migration') NOT NULL,
    fact_change_id BIGINT NULL,              -- the pipeline change that caused it (FK added in 007)
    note VARCHAR(500) NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fact_changed (fact_id, changed_at),
    CONSTRAINT fk_fact_history_fact FOREIGN KEY (fact_id)
        REFERENCES facts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Record each fact's current value as its baseline, so history starts
-- from a known point.
INSERT INTO fact_history (fact_id, old_value, old_value_numeric, new_value, new_value_numeric,
                          effective_from, change_source, note, changed_at)
SELECT id, NULL, NULL, value, value_numeric, effective_from, 'migration',
       'Baseline recorded by migration 006', last_updated
FROM facts;
