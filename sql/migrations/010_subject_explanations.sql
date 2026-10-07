-- Phase 1: a longer "explanation" section for each subject page, shown
-- below the figures on the public page and edited in /admin/subject.php
--
-- Stored as plain text in a small Markdown-style format (headings, lists,
-- bold, links), turned into HTML by renderExplanation() in
-- includes/functions.php, so nothing in the database is raw HTML
--
-- NULL means the page has no explanation yet, and the section is hidden
ALTER TABLE subjects ADD COLUMN explanation MEDIUMTEXT NULL AFTER intro;
