<?php
/**
 * Read-side queries used only by the admin pages.
 *
 * Kept apart from includes/functions.php because these deliberately see
 * everything, including draft and retired subjects and unpublished
 * facts, which the public pages must never show. Nothing here changes
 * data. Writes go through includes/fact-writer.php.
 */

require_once __DIR__ . '/functions.php';

/**
 * Every subject, whatever its status, with where it sits in the site and
 * a few numbers for the subjects list. Ordered the way the site's
 * navigation is: category, then subcategory, then subject.
 */
function getAdminSubjectList(): array
{
    $pdo = getDbConnection();
    return $pdo->query(
        "SELECT s.id, s.name, s.slug, s.status, s.intro, s.meta_title, s.meta_description,
                sc.name AS subcategory_name, c.name AS category_name, j.code AS country_code,
                -- Figures the public page actually shows (published facts only).
                (SELECT COUNT(*) FROM subject_facts sf
                 JOIN facts f ON f.id = sf.fact_id AND f.status = 'published'
                 WHERE sf.subject_id = s.id) AS published_facts,
                -- Facts this page owns, whatever their status.
                (SELECT COUNT(*) FROM facts f2 WHERE f2.primary_subject_id = s.id) AS owned_facts
         FROM subjects s
         JOIN subcategories sc ON sc.id = s.subcategory_id
         JOIN categories c ON c.id = sc.category_id
         JOIN jurisdictions j ON j.id = c.jurisdiction_id
         ORDER BY j.id, c.name, sc.name, s.name"
    )->fetchAll();
}

/**
 * One subject by id, whatever its status, with its category,
 * subcategory and country for the editor's heading and links.
 */
function getAdminSubject(int $subjectId): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT s.*, sc.name AS subcategory_name, sc.slug AS subcategory_slug,
                c.name AS category_name, c.slug AS category_slug,
                j.url_prefix AS country_prefix, j.name AS country_name
         FROM subjects s
         JOIN subcategories sc ON sc.id = s.subcategory_id
         JOIN categories c ON c.id = sc.category_id
         JOIN jurisdictions j ON j.id = c.jurisdiction_id
         WHERE s.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $subjectId]);
    return $stmt->fetch() ?: null;
}

/**
 * Every fact linked to a subject page, in the page's order, whatever the
 * fact's status, so the editor can see drafts and retired facts too.
 *
 * Unlike getFactsForSubject() (the public version), `context` here is
 * always the fact's own context, and the page-specific override is
 * returned separately as context_override, because the editor needs to
 * know which one it's looking at.
 *
 *   is_primary   1 if this page owns the fact (only then is it editable
 *                here; shared facts are edited on their owner's page)
 *   owner_id / owner_name   the owning page
 *   linked_pages            how many pages show this fact in total
 */
function getAdminFactsForSubject(int $subjectId): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT f.*, sf.context_override,
                (f.primary_subject_id = sf.subject_id) AS is_primary,
                owner.id AS owner_id, owner.name AS owner_name,
                src.publisher AS source_name, src.url AS source_url,
                j.code AS jurisdiction_code, j.name AS jurisdiction_name,
                j.parent_id AS jurisdiction_parent_id,
                (SELECT COUNT(*) FROM subject_facts sf2 WHERE sf2.fact_id = f.id) AS linked_pages
         FROM subject_facts sf
         JOIN facts f ON f.id = sf.fact_id
         JOIN subjects owner ON owner.id = f.primary_subject_id
         LEFT JOIN sources src ON src.id = f.source_id
         JOIN jurisdictions j ON j.id = f.jurisdiction_id
         WHERE sf.subject_id = :sid
         ORDER BY sf.sort_order ASC, f.label ASC'
    );
    $stmt->execute(['sid' => $subjectId]);
    return $stmt->fetchAll();
}
