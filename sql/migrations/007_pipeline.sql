-- 007: Pipeline tables (admin app and cron jobs)
--
-- jobs         the work queue. Each cron run claims a small batch,
--              processes it, and exits, which keeps runs inside shared
--              hosting limits.
-- cron_runs    one row per cron invocation. A run left as 'running'
--              means it crashed, which the dashboard can flag.
-- fact_checks  every verification of a fact against its source,
--              including "unchanged" results.
-- fact_changes proposed new facts and value changes, with their
--              evidence and the result of each automated gate.
--
-- None of these are read by the public site.

CREATE TABLE jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_type VARCHAR(50) NOT NULL,           -- e.g. 'verify_source', 'discover_facts', 'apply_changes'
    payload TEXT NULL,                       -- JSON parameters for the job
    status ENUM('queued', 'running', 'done', 'failed') NOT NULL DEFAULT 'queued',
    priority TINYINT NOT NULL DEFAULT 5,     -- lower runs first
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    run_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- used for drip-feed spacing and retry back-off
    locked_at DATETIME NULL,
    locked_by VARCHAR(64) NULL,              -- the cron run that claimed it
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    KEY idx_pick (status, run_after, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cron_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    script VARCHAR(100) NOT NULL,            -- e.g. 'run-jobs.php'
    status ENUM('running', 'ok', 'error') NOT NULL DEFAULT 'running',
    jobs_processed INT UNSIGNED NOT NULL DEFAULT 0,
    jobs_failed INT UNSIGNED NOT NULL DEFAULT 0,
    error TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    KEY idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Status flow:
--   pending  -> gates not yet run
--   approved -> passed all gates (or approved by hand). Waits here until
--               effective_from arrives, which is how announced future
--               changes (e.g. Budget measures) are preloaded.
--   held     -> failed a gate, sits in the exceptions queue for review
--   rejected -> discarded, kept for the record
--   applied  -> written to facts (and fact_history)
--   superseded -> replaced by a newer proposal for the same fact
--
-- Gate columns: NULL = not run, 0 = failed, 1 = passed.
CREATE TABLE fact_changes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    change_type ENUM('update', 'new_fact') NOT NULL,
    fact_id INT NULL,                        -- the fact being updated, or the new fact once applied
    subject_id INT NULL,                     -- new_fact only: the subject that will own it
    jurisdiction_id INT NULL,
    proposed_fact_key VARCHAR(100) NULL,     -- new_fact only
    proposed_label VARCHAR(255) NULL,        -- new_fact only
    proposed_unit VARCHAR(50) NULL,          -- new_fact only
    proposed_value_type ENUM('currency', 'percent', 'integer', 'decimal', 'date', 'text') NULL,
    proposed_value VARCHAR(255) NOT NULL,
    proposed_value_numeric DECIMAL(18,4) NULL,
    effective_from DATE NULL,
    tax_year VARCHAR(9) NULL,
    source_id INT NULL,
    evidence_snippet TEXT NULL,              -- the page text the value was extracted from
    extraction_method ENUM('parser', 'ai', 'manual') NOT NULL,
    gate_allowlist TINYINT(1) NULL,
    gate_verbatim TINYINT(1) NULL,
    gate_cross_source TINYINT(1) NULL,
    gate_threshold TINYINT(1) NULL,
    status ENUM('pending', 'approved', 'held', 'rejected', 'applied', 'superseded')
        NOT NULL DEFAULT 'pending',
    status_reason VARCHAR(500) NULL,         -- e.g. which gate failed and why
    job_id BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    applied_at DATETIME NULL,
    KEY idx_status_effective (status, effective_from),
    KEY idx_fact (fact_id),
    CONSTRAINT fk_fact_changes_fact FOREIGN KEY (fact_id)
        REFERENCES facts(id) ON DELETE SET NULL,
    CONSTRAINT fk_fact_changes_subject FOREIGN KEY (subject_id)
        REFERENCES subjects(id) ON DELETE SET NULL,
    CONSTRAINT fk_fact_changes_jurisdiction FOREIGN KEY (jurisdiction_id)
        REFERENCES jurisdictions(id) ON DELETE SET NULL,
    CONSTRAINT fk_fact_changes_source FOREIGN KEY (source_id)
        REFERENCES sources(id) ON DELETE SET NULL,
    CONSTRAINT fk_fact_changes_job FOREIGN KEY (job_id)
        REFERENCES jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fact_checks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    fact_id INT NOT NULL,
    source_id INT NULL,
    job_id BIGINT NULL,
    result ENUM('unchanged', 'changed', 'not_found', 'source_error') NOT NULL,
    observed_value VARCHAR(255) NULL,        -- what the source showed
    fact_change_id BIGINT NULL,              -- set when result = 'changed'
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fact_checked (fact_id, checked_at),
    CONSTRAINT fk_fact_checks_fact FOREIGN KEY (fact_id)
        REFERENCES facts(id) ON DELETE CASCADE,
    CONSTRAINT fk_fact_checks_source FOREIGN KEY (source_id)
        REFERENCES sources(id) ON DELETE SET NULL,
    CONSTRAINT fk_fact_checks_job FOREIGN KEY (job_id)
        REFERENCES jobs(id) ON DELETE SET NULL,
    CONSTRAINT fk_fact_checks_change FOREIGN KEY (fact_change_id)
        REFERENCES fact_changes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Now that fact_changes exists, link history rows to the change that
-- caused them.
ALTER TABLE fact_history
    ADD CONSTRAINT fk_fact_history_change FOREIGN KEY (fact_change_id)
        REFERENCES fact_changes(id) ON DELETE SET NULL;
