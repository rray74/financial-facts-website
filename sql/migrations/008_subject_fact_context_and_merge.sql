-- 008: Per-page context, and merge the duplicated base rate fact
--
-- context_override lets the same fact carry a different explanatory note
-- on each page it appears on. The base rate means one thing on a fixed
-- rate page and another on the SVR page, and page-specific framing also
-- keeps pages that share facts from reading as duplicates. When it's
-- NULL, the fact's own context is shown.

ALTER TABLE subject_facts
    ADD COLUMN context_override TEXT NULL AFTER sort_order;

-- Merge uk_base_rate_svr into uk_base_rate:
--   1. link uk_base_rate to every page the duplicate appeared on,
--      carrying over the duplicate's context as that page's override
--   2. unlink the duplicate
--   3. retire it (history prevents deletion, and retiring keeps the record)
--
-- Every statement matches on fact_key, so on a database where either
-- key doesn't exist this migration changes nothing.

INSERT IGNORE INTO subject_facts (subject_id, fact_id, sort_order, context_override)
SELECT dup_link.subject_id, keep.id, dup_link.sort_order, dup.context
FROM facts keep
JOIN facts dup ON dup.fact_key = 'uk_base_rate_svr'
JOIN subject_facts dup_link ON dup_link.fact_id = dup.id
WHERE keep.fact_key = 'uk_base_rate';

DELETE dup_link
FROM subject_facts dup_link
JOIN facts dup ON dup.id = dup_link.fact_id
JOIN facts keep ON keep.fact_key = 'uk_base_rate'
WHERE dup.fact_key = 'uk_base_rate_svr';

UPDATE facts dup
JOIN facts keep ON keep.fact_key = 'uk_base_rate'
SET dup.status = 'retired'
WHERE dup.fact_key = 'uk_base_rate_svr';
