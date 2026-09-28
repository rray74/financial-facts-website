-- 005: Page-level fields on subjects
--
-- meta_title and meta_description let each page's SEO be set in the DB
-- without touching templates. Aim for about 60 and 155 characters, the
-- columns allow some headroom.
--
-- status lets subjects be built in the DB before going live. Retired
-- subjects are kept rather than deleted, so their URLs can be redirected.

ALTER TABLE subjects
    ADD COLUMN meta_title VARCHAR(100) NULL AFTER intro,
    ADD COLUMN meta_description VARCHAR(200) NULL AFTER meta_title,
    ADD COLUMN status ENUM('draft', 'published', 'retired')
        NOT NULL DEFAULT 'published' AFTER meta_description,
    ADD COLUMN published_at DATETIME NULL AFTER status,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD INDEX idx_status (status);

-- Existing subjects are live, so treat their creation date as publish date.
UPDATE subjects SET published_at = created_at WHERE published_at IS NULL;

-- New subjects default to draft from now on.
ALTER TABLE subjects ALTER COLUMN status SET DEFAULT 'draft';
