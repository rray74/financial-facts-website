-- 004: Subject to fact links (many-to-many)
--
-- A fact can now appear on any number of subject pages, and is updated
-- once. facts.subject_id is kept and now means the fact's PRIMARY subject:
-- the one page that "owns" it. Other pages show it as supporting context
-- and link back to the owner.
--
-- Keeping ownership on facts (rather than an is_primary flag here) means
-- the database guarantees exactly one owner per fact. The cleanup
-- migration renames the column to primary_subject_id.

CREATE TABLE subject_facts (
    subject_id INT NOT NULL,
    fact_id INT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,       -- display order on that subject page
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (subject_id, fact_id),
    KEY idx_fact (fact_id),
    CONSTRAINT fk_subject_facts_subject FOREIGN KEY (subject_id)
        REFERENCES subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_subject_facts_fact FOREIGN KEY (fact_id)
        REFERENCES facts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Each existing fact appears on its current subject. Sort order uses
-- steps of 10 so facts can be slotted in between later without
-- renumbering everything.
INSERT INTO subject_facts (subject_id, fact_id, sort_order)
SELECT subject_id, id, id * 10
FROM facts;
