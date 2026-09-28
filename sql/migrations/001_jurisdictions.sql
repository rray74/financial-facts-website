-- 001: Jurisdictions (countries and their sub-regions)
--
-- A self-referencing hierarchy: UK nations sit under GB, and later US
-- states would sit under US. Currency, locale and tax-year start are set
-- on countries. Sub-regions leave them NULL and inherit from the parent.
--
-- url_prefix is only set on countries and drives the /uk/ style URL
-- folders. is_active controls which countries appear on the public site,
-- so a country can be built out in the DB before it goes live.

CREATE TABLE jurisdictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NULL,
    code VARCHAR(10) NOT NULL,               -- ISO 3166 code, e.g. GB, GB-SCT
    name VARCHAR(100) NOT NULL,
    url_prefix VARCHAR(20) NULL,             -- e.g. 'uk' (countries only)
    currency_code CHAR(3) NULL,              -- e.g. 'GBP' (NULL = inherit)
    locale VARCHAR(10) NULL,                 -- e.g. 'en-GB' (NULL = inherit)
    tax_year_start_month TINYINT UNSIGNED NULL,
    tax_year_start_day TINYINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_code (code),
    UNIQUE KEY uniq_url_prefix (url_prefix),
    CONSTRAINT fk_jurisdictions_parent FOREIGN KEY (parent_id)
        REFERENCES jurisdictions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- UK tax year starts 6 April.
INSERT INTO jurisdictions (code, name, url_prefix, currency_code, locale, tax_year_start_month, tax_year_start_day, is_active)
VALUES ('GB', 'United Kingdom', 'uk', 'GBP', 'en-GB', 4, 6, 1);

SET @gb := (SELECT id FROM jurisdictions WHERE code = 'GB');

-- The four nations, for facts that differ by nation (SDLT vs LBTT vs LTT,
-- Scottish income tax bands, and so on).
INSERT INTO jurisdictions (parent_id, code, name, is_active) VALUES
    (@gb, 'GB-ENG', 'England', 1),
    (@gb, 'GB-SCT', 'Scotland', 1),
    (@gb, 'GB-WLS', 'Wales', 1),
    (@gb, 'GB-NIR', 'Northern Ireland', 1);

-- Categories belong to a country. Subcategories and subjects inherit the
-- country through their category, so they don't need their own column.
ALTER TABLE categories ADD COLUMN jurisdiction_id INT NULL AFTER id;

UPDATE categories SET jurisdiction_id = @gb;

-- Category slugs only need to be unique within a country, so /uk/tax/
-- and a future /us/tax/ can coexist.
ALTER TABLE categories
    MODIFY jurisdiction_id INT NOT NULL,
    DROP INDEX slug,
    ADD UNIQUE KEY uniq_jurisdiction_slug (jurisdiction_id, slug),
    ADD CONSTRAINT fk_categories_jurisdiction FOREIGN KEY (jurisdiction_id)
        REFERENCES jurisdictions(id) ON DELETE RESTRICT;
