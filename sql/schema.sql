-- Financial Facts — database schema
-- Import via phpMyAdmin in hPanel, or: mysql -u USER -p DBNAME < schema.sql

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Atomic data points. Each one is a single fact that can be reused
-- across multiple article templates via its `fact_key`.
CREATE TABLE IF NOT EXISTS facts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    fact_key VARCHAR(100) NOT NULL UNIQUE,   -- e.g. "uk_base_rate"
    label VARCHAR(255) NOT NULL,             -- e.g. "UK Base Interest Rate"
    value VARCHAR(255) NOT NULL,             -- e.g. "4.25"
    unit VARCHAR(50),                        -- e.g. "%", "£", "years"
    context TEXT,                            -- short note on what this figure means
    source_name VARCHAR(255),                -- e.g. "Bank of England"
    source_url VARCHAR(500),
    last_updated DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    INDEX idx_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Article bodies contain placeholders like {{fact:uk_base_rate}} or
-- {{fact:uk_base_rate:value}} which are resolved live at render time
-- by looking up the matching row in `facts`.
CREATE TABLE IF NOT EXISTS article_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    summary VARCHAR(500),
    body MEDIUMTEXT NOT NULL,
    status ENUM('draft','published') DEFAULT 'draft',
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    INDEX idx_slug (slug),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed data to get started
INSERT INTO categories (name, slug, description) VALUES
    ('Mortgages', 'mortgages', 'Rates, affordability and borrowing facts'),
    ('Savings', 'savings', 'Interest rates and savings benchmarks'),
    ('Tax', 'tax', 'Allowances, thresholds and rates');

INSERT INTO facts (category_id, fact_key, label, value, unit, context, source_name, source_url, last_updated) VALUES
    (1, 'uk_base_rate', 'Bank of England Base Rate', '4.25', '%', 'Sets the baseline for most UK mortgage and savings products.', 'Bank of England', 'https://www.bankofengland.co.uk/monetary-policy/the-interest-rate-bank-rate', '2026-09-01'),
    (1, 'avg_2yr_fixed', 'Average 2-Year Fixed Mortgage Rate', '5.1', '%', 'UK-wide average across residential 2-year fixed products.', 'Moneyfacts', 'https://moneyfacts.co.uk', '2026-09-01'),
    (2, 'isa_allowance', 'Annual ISA Allowance', '20000', '£', 'Maximum amount that can be saved tax-free into ISAs per tax year.', 'HMRC', 'https://www.gov.uk/individual-savings-accounts', '2026-04-06'),
    (3, 'personal_allowance', 'Income Tax Personal Allowance', '12570', '£', 'Amount you can earn before paying income tax.', 'HMRC', 'https://www.gov.uk/income-tax-rates', '2026-04-06');

INSERT INTO article_templates (category_id, title, slug, summary, body, status, published_at) VALUES
    (1, 'What Is the Current Mortgage Rate in the UK?',
     'current-uk-mortgage-rate',
     'A quick look at where mortgage rates stand right now, and what sets them.',
     '<p>The Bank of England Base Rate currently sits at {{fact:uk_base_rate:value}}{{fact:uk_base_rate:unit}}, last confirmed on {{fact:uk_base_rate:updated}}. {{fact:uk_base_rate:context}}</p>
      <p>Against that backdrop, the average 2-year fixed mortgage rate across the UK is {{fact:avg_2yr_fixed:value}}{{fact:avg_2yr_fixed:unit}} ({{fact:avg_2yr_fixed:updated}}), according to {{fact:avg_2yr_fixed:source}}.</p>',
     'published', NOW());
