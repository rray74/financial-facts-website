<?php
/**
 * Write-side fact functions.
 *
 * Every change to a fact's value goes through updateFactValue(), and every
 * new fact through createFact(), so the history, verification and
 * page-link rules live in one place. Used by scripts/import-facts.php and
 * public/admin/review.php now, and by the cron pipeline later.
 *
 * The public site never includes this file. It only reads, through
 * includes/functions.php.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Domains whose facts can be trusted without review, with the licence
 * their content is published under (NULL if not stated). Subdomains are
 * included, so 'gov.uk' also covers 'www.gov.uk' and 'ons.gov.uk'.
 *
 * Migration 002 applied the UK-wide entries to existing sources. This
 * list is what decides for every new source from now on, and it's the
 * first of the automated gates the pipeline will use.
 */
const FACT_SOURCE_ALLOWLIST = [
    'gov.uk'              => 'Open Government Licence v3.0',
    'bankofengland.co.uk' => null,
    'fca.org.uk'          => null,
    // Devolved governments and tax authorities, for nation-specific facts
    // such as LBTT (Revenue Scotland) and LTT (Welsh Revenue Authority).
    'gov.scot'            => 'Open Government Licence v3.0',
    'revenue.scot'        => null,
    'gov.wales'           => 'Open Government Licence v3.0',
    // National Savings and Investments, backed by HM Treasury, the
    // official source for Premium Bonds rates and odds.
    'nsandi.com'          => null,
];

/**
 * Run $fn inside a transaction, or inside the caller's transaction if
 * one is already open. This lets a function like updateFactValue() be
 * safe on its own, and also be part of a bigger unit of work such as a
 * whole import row.
 */
function withTransaction(PDO $pdo, callable $fn)
{
    if ($pdo->inTransaction()) {
        return $fn();
    }

    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Work out a value's type and numeric form, using the same rules as
 * migration 003. Plain numbers ('4.25', '125,000') get a numeric value,
 * and anything else is treated as text.
 *
 * Returns [value_type, value_numeric].
 */
function parseFactValue(string $value, ?string $unit): array
{
    $plain = str_replace(',', '', trim($value));

    if (!preg_match('/^-?\d+(\.\d+)?$/', $plain)) {
        // ISO dates are stored as type 'date' so they can be formatted.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return ['date', null];
        }
        return ['text', null];
    }

    if ($unit === '%') {
        $type = 'percent';
    } elseif (in_array($unit, ['£', '$', '€'], true)) {
        $type = 'currency';
    } elseif (strpos($plain, '.') === false) {
        $type = 'integer';
    } else {
        $type = 'decimal';
    }

    return [$type, $plain];
}

/**
 * Remove the fact's own unit if it was typed into the value, so '4.5%',
 * '£125,000' and '5 years' are stored as '4.5', '125,000' and '5'. The
 * unit is added back when the value is displayed.
 */
function stripUnitFromValue(string $value, ?string $unit): string
{
    $value = trim($value);
    $unit = trim((string) $unit);

    if ($unit === '') {
        return $value;
    }
    if (str_starts_with($value, $unit)) {
        $value = substr($value, strlen($unit));
    }
    if (str_ends_with($value, $unit)) {
        $value = substr($value, 0, -strlen($unit));
    }

    return trim($value);
}

/**
 * Values go live the moment they're written, so an effective date in the
 * future would show a figure before it applies. Announced future changes
 * (e.g. Budget measures) will be queued in fact_changes by the pipeline
 * and applied on the day. Until then, enter them once they're in effect.
 */
function assertEffectiveDateNotFuture(?string $effectiveFrom): void
{
    if ($effectiveFrom !== null && $effectiveFrom > date('Y-m-d')) {
        throw new RuntimeException(
            "Effective date $effectiveFrom is in the future. Enter the change once it's in effect"
        );
    }
}

/**
 * Look up a jurisdiction by its ISO code ('GB', 'GB-SCT'). Cached, since
 * an import can hit the same code hundreds of times.
 */
function getJurisdictionByCode(PDO $pdo, string $code): array
{
    static $cache = [];
    $code = strtoupper(trim($code));

    if (!isset($cache[$code])) {
        $stmt = $pdo->prepare('SELECT * FROM jurisdictions WHERE code = :code');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Unknown jurisdiction code '$code'");
        }
        $cache[$code] = $row;
    }

    return $cache[$code];
}

/**
 * The country a jurisdiction belongs to: itself for a country, or its
 * parent for a sub-region such as Scotland.
 */
function getCountryId(array $jurisdiction): int
{
    return (int) ($jurisdiction['parent_id'] ?? $jurisdiction['id']);
}

/**
 * If a URL's domain is on the allowlist, returns ['licence' => ...],
 * otherwise NULL.
 */
function getAllowlistEntry(string $url): ?array
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    foreach (FACT_SOURCE_ALLOWLIST as $domain => $licence) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return ['licence' => $licence];
        }
    }

    return null;
}

/**
 * Find a source by URL, or create it. New sources are marked primary and
 * allowlisted only if their domain is on FACT_SOURCE_ALLOWLIST.
 */
function getOrCreateSource(PDO $pdo, string $url, ?string $publisher, ?int $jurisdictionId): int
{
    $stmt = $pdo->prepare('SELECT id FROM sources WHERE url = :url');
    $stmt->execute(['url' => $url]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $allow = getAllowlistEntry($url);

    $stmt = $pdo->prepare(
        'INSERT INTO sources (jurisdiction_id, publisher, url, source_type, is_allowlisted, licence)
         VALUES (:jid, :publisher, :url, :type, :allow, :licence)'
    );
    $stmt->execute([
        'jid'       => $jurisdictionId,
        'publisher' => $publisher ?: (parse_url($url, PHP_URL_HOST) ?: 'Unknown'),
        'url'       => $url,
        'type'      => $allow ? 'primary' : 'secondary',
        'allow'     => $allow ? 1 : 0,
        'licence'   => $allow['licence'] ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Show a fact on a subject page. New links go to the end of the page
 * (sort_order steps of 10). Returns true if a link was created, false if
 * it already existed, in which case only the context override is updated,
 * and only when one is given.
 */
function linkFactToSubject(PDO $pdo, int $subjectId, int $factId, ?string $contextOverride = null): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM subject_facts WHERE subject_id = :sid AND fact_id = :fid');
    $stmt->execute(['sid' => $subjectId, 'fid' => $factId]);

    if ($stmt->fetchColumn()) {
        if ($contextOverride !== null) {
            $stmt = $pdo->prepare(
                'UPDATE subject_facts SET context_override = :ctx WHERE subject_id = :sid AND fact_id = :fid'
            );
            $stmt->execute(['ctx' => $contextOverride, 'sid' => $subjectId, 'fid' => $factId]);
        }
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO subject_facts (subject_id, fact_id, sort_order, context_override)
         SELECT :sid, :fid, COALESCE(MAX(sort_order), 0) + 10, :ctx
         FROM subject_facts WHERE subject_id = :sid2'
    );
    $stmt->execute(['sid' => $subjectId, 'fid' => $factId, 'ctx' => $contextOverride, 'sid2' => $subjectId]);

    return true;
}

/**
 * Create a fact, link it to its owner page, and record its first value in
 * fact_history, all in one transaction. Returns the new fact's id.
 *
 * Required keys: primary_subject_id, jurisdiction_id, fact_key, label, value.
 * Optional: unit, value_display, context, source_id, last_updated
 * (Y-m-d, defaults to today), effective_from, tax_year,
 * review_frequency_days (default 90), status (default 'draft'),
 * change_source (default 'manual').
 */
function createFact(PDO $pdo, array $f): int
{
    return withTransaction($pdo, function () use ($pdo, $f) {
        assertEffectiveDateNotFuture($f['effective_from'] ?? null);
        $f['value'] = stripUnitFromValue($f['value'], $f['unit'] ?? null);
        [$type, $numeric] = parseFactValue($f['value'], $f['unit'] ?? null);
        $lastUpdated = $f['last_updated'] ?? date('Y-m-d');

        $stmt = $pdo->prepare(
            'INSERT INTO facts
                (primary_subject_id, jurisdiction_id, fact_key, label, value, value_type, value_numeric,
                 value_display, unit, context, source_id, last_updated, effective_from, tax_year,
                 last_verified_at, status, review_frequency_days)
             VALUES
                (:subject_id, :jid, :fact_key, :label, :value, :type, :numeric,
                 :display, :unit, :context, :source_id, :last_updated, :effective_from, :tax_year,
                 :verified, :status, :review)'
        );
        $stmt->execute([
            'subject_id'     => $f['primary_subject_id'],
            'jid'            => $f['jurisdiction_id'],
            'fact_key'       => $f['fact_key'],
            'label'          => $f['label'],
            'value'          => $f['value'],
            'type'           => $type,
            'numeric'        => $numeric,
            'display'        => $f['value_display'] ?? null,
            'unit'           => $f['unit'] ?? null,
            'context'        => $f['context'] ?? null,
            'source_id'      => $f['source_id'] ?? null,
            'last_updated'   => $lastUpdated,
            'effective_from' => $f['effective_from'] ?? null,
            'tax_year'       => $f['tax_year'] ?? null,
            // A new fact has just been checked by whoever added it.
            'verified'       => $lastUpdated,
            'status'         => $f['status'] ?? 'draft',
            'review'         => $f['review_frequency_days'] ?? 90,
        ]);
        $factId = (int) $pdo->lastInsertId();

        linkFactToSubject($pdo, (int) $f['primary_subject_id'], $factId);

        $stmt = $pdo->prepare(
            'INSERT INTO fact_history
                (fact_id, new_value, new_value_numeric, effective_from, change_source, note)
             VALUES (:fid, :value, :numeric, :effective_from, :source, :note)'
        );
        $stmt->execute([
            'fid'            => $factId,
            'value'          => $f['value'],
            'numeric'        => $numeric,
            'effective_from' => $f['effective_from'] ?? null,
            'source'         => $f['change_source'] ?? 'manual',
            'note'           => 'Fact created',
        ]);

        return $factId;
    });
}

/**
 * Set a fact's value. This is the only function that should ever change
 * facts.value.
 *
 * If the value really changed, the fact is updated and a fact_history row
 * records old and new. If it didn't, the fact is just marked verified.
 * A formatting-only difference ('125000' vs '125,000') counts as
 * unchanged: the new text is stored, but no history row is written.
 *
 * Options:
 *   change_source   'manual' | 'import' | 'pipeline' (default 'manual')
 *   effective_from  Y-m-d the new value took effect, if known
 *   tax_year        e.g. '2026/27', if relevant
 *   changed_on      Y-m-d to store as last_updated (default today)
 *   fact_change_id  the pipeline proposal that caused this, if any
 *   note            free text for the history row
 *
 * Returns true if the value changed, false if it was only verified.
 */
function updateFactValue(PDO $pdo, int $factId, string $newValue, array $opts = []): bool
{
    return withTransaction($pdo, function () use ($pdo, $factId, $newValue, $opts) {
        assertEffectiveDateNotFuture($opts['effective_from'] ?? null);

        // Lock the row so two writers can't both record the same change.
        $stmt = $pdo->prepare('SELECT id, value, value_numeric, unit FROM facts WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $factId]);
        $fact = $stmt->fetch();
        if (!$fact) {
            throw new RuntimeException("Fact $factId not found");
        }

        $newValue = stripUnitFromValue($newValue, $fact['unit']);
        [$type, $numeric] = parseFactValue($newValue, $fact['unit']);

        $sameText = $newValue === $fact['value'];
        $sameNumber = $numeric !== null && $fact['value_numeric'] !== null
            && (float) $numeric === (float) $fact['value_numeric'];

        if ($sameText || $sameNumber) {
            if (!$sameText) {
                // Formatting-only change, so store the new text but no history.
                $stmt = $pdo->prepare('UPDATE facts SET value = :value WHERE id = :id');
                $stmt->execute(['value' => $newValue, 'id' => $factId]);
            }
            markFactVerified($pdo, $factId);
            return false;
        }

        // A new value makes the old effective date and tax year wrong, so
        // they are replaced (or cleared, if not given) rather than kept.
        $stmt = $pdo->prepare(
            'UPDATE facts SET
                value = :value, value_type = :type, value_numeric = :numeric,
                last_updated = :changed_on, effective_from = :effective_from, tax_year = :tax_year,
                last_verified_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'value'          => $newValue,
            'type'           => $type,
            'numeric'        => $numeric,
            'changed_on'     => $opts['changed_on'] ?? date('Y-m-d'),
            'effective_from' => $opts['effective_from'] ?? null,
            'tax_year'       => $opts['tax_year'] ?? null,
            'id'             => $factId,
        ]);

        $stmt = $pdo->prepare(
            'INSERT INTO fact_history
                (fact_id, old_value, old_value_numeric, new_value, new_value_numeric,
                 effective_from, change_source, fact_change_id, note)
             VALUES (:fid, :old, :old_num, :new, :new_num, :effective_from, :source, :change_id, :note)'
        );
        $stmt->execute([
            'fid'            => $factId,
            'old'            => $fact['value'],
            'old_num'        => $fact['value_numeric'],
            'new'            => $newValue,
            'new_num'        => $numeric,
            'effective_from' => $opts['effective_from'] ?? null,
            'source'         => $opts['change_source'] ?? 'manual',
            'change_id'      => $opts['fact_change_id'] ?? null,
            'note'           => $opts['note'] ?? null,
        ]);

        return true;
    });
}

/**
 * Record that a fact was checked against its source and is still right.
 * Resets its review window and logs the check in fact_checks.
 */
function markFactVerified(PDO $pdo, int $factId): void
{
    $stmt = $pdo->prepare('UPDATE facts SET last_verified_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $factId]);

    $stmt = $pdo->prepare(
        "INSERT INTO fact_checks (fact_id, source_id, result, observed_value)
         SELECT id, source_id, 'unchanged', value FROM facts WHERE id = :id"
    );
    $stmt->execute(['id' => $factId]);
}
