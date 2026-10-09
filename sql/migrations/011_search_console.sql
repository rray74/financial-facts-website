-- Phase 2: Google Search Console data for the admin dashboard and editor
--
-- Filled daily by scripts/fetch-search-console.php, which replaces the
-- contents each run with the latest 28 days, so these tables only ever
-- hold one period (shown as period_start to period_end)
--
-- One row per page: totals for the period
CREATE TABLE search_console_pages (
    page_path VARCHAR(255) NOT NULL PRIMARY KEY,
    clicks INT NOT NULL DEFAULT 0,
    impressions INT NOT NULL DEFAULT 0,
    ctr DECIMAL(7,4) NOT NULL DEFAULT 0,
    position DECIMAL(7,2) NOT NULL DEFAULT 0,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The top searches that showed each page, for the same period
CREATE TABLE search_console_queries (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    page_path VARCHAR(255) NOT NULL,
    query VARCHAR(500) NOT NULL,
    clicks INT NOT NULL DEFAULT 0,
    impressions INT NOT NULL DEFAULT 0,
    position DECIMAL(7,2) NOT NULL DEFAULT 0,
    KEY idx_page (page_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
