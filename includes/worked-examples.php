<?php
/**
 * Worked examples for subject pages, calculated from live facts.
 *
 * Each example reads its figures from the facts table (by fact_key), so
 * when a rate or threshold changes, every example on every page updates
 * with it. This is content no other site has in this form, and it never
 * goes stale.
 *
 * An example function receives all published numeric facts
 * (fact_key => number) and returns either NULL, if a figure it needs is
 * missing or unpublished, or an array:
 *   title    heading for the example
 *   intro    one sentence explaining what the table shows
 *   columns  column headings
 *   rows     rows of already-formatted cell text
 *   note     assumptions, shown under the table
 *
 * To add examples to another subject, write a function below and add it
 * to the map in getWorkedExamplesForSubject().
 */

require_once __DIR__ . '/functions.php';

/** Sample incomes used across the tax examples. */
const EXAMPLE_INCOMES = [20000, 30000, 45000, 60000, 100000];

/**
 * The worked examples for one subject page, keyed by its slug. Returns
 * an empty array for subjects without examples.
 */
function getWorkedExamplesForSubject(string $subjectSlug): array
{
    $map = [
        'income-tax-rates-and-bands'        => ['exampleIncomeTaxRestOfUk'],
        'scottish-income-tax-rates'         => ['exampleIncomeTaxScotland'],
        'employee-national-insurance'       => ['exampleEmployeeNationalInsurance'],
        'student-loan-repayment-thresholds' => ['exampleStudentLoanRepayments'],
        'child-benefit'                     => ['exampleChildBenefitCharge'],
        'minimum-wage-rates'                => ['exampleMinimumWageYearly'],
    ];

    $facts = getFactNumbers();
    $examples = [];

    foreach ($map[$subjectSlug] ?? [] as $function) {
        $example = $function($facts);
        if ($example !== null) {
            $examples[] = $example;
        }
    }

    return $examples;
}

// ============================================================
// Helpers
// ============================================================

/**
 * The listed facts as short names => numbers, or NULL if any are missing,
 * in which case the example is simply not shown.
 */
function exampleFacts(array $facts, array $keys): ?array
{
    $values = [];
    foreach ($keys as $name => $key) {
        if (!array_key_exists($key, $facts)) {
            return null;
        }
        $values[$name] = $facts[$key];
    }
    return $values;
}

/** Whole pounds, e.g. £3,486. */
function exampleMoney(float $amount): string
{
    return '£' . number_format(round($amount));
}

/**
 * Tax on an amount split into bands. $bands is a list of
 * [upper limit, rate as a percentage], in order, with the last upper
 * limit INF.
 */
function taxAcrossBands(float $amount, array $bands): float
{
    $tax = 0.0;
    $lower = 0.0;

    foreach ($bands as [$upper, $ratePercent]) {
        if ($amount <= $lower) {
            break;
        }
        $tax += (min($amount, $upper) - $lower) * $ratePercent / 100;
        $lower = $upper;
    }

    return $tax;
}

/**
 * The Personal Allowance for a given income, after the taper that
 * removes £1 for every £2 of income above the taper threshold.
 */
function personalAllowanceFor(float $income, float $standardAllowance, float $taperThreshold): float
{
    $reduction = max(0, floor(($income - $taperThreshold) / 2));
    return max(0, $standardAllowance - $reduction);
}

/**
 * Income Tax outside Scotland on employment income. Bands apply to
 * taxable income (after the Personal Allowance). The basic rate band is
 * the higher rate threshold minus the standard allowance, and the
 * additional rate applies to taxable income above the additional rate
 * threshold.
 */
function incomeTaxRestOfUk(float $income, array $f): float
{
    $allowance = personalAllowanceFor($income, $f['allowance'], $f['taper']);
    $taxable = max(0, $income - $allowance);

    return taxAcrossBands($taxable, [
        [$f['higher_threshold'] - $f['allowance'], $f['basic_rate']],
        [$f['additional_threshold'], $f['higher_rate']],
        [INF, $f['additional_rate']],
    ]);
}

/**
 * Scottish Income Tax on employment income. The Scottish thresholds are
 * published as gross income assuming the standard Personal Allowance, so
 * each is converted to a taxable-income band limit first.
 */
function incomeTaxScotland(float $income, array $f): float
{
    $allowance = personalAllowanceFor($income, $f['allowance'], $f['taper']);
    $taxable = max(0, $income - $allowance);
    $toTaxable = fn(float $grossStart) => $grossStart - 1 - $f['allowance'];

    return taxAcrossBands($taxable, [
        [$toTaxable($f['basic_start']), $f['starter_rate']],
        [$toTaxable($f['intermediate_start']), $f['basic_rate']],
        [$toTaxable($f['higher_start']), $f['intermediate_rate']],
        [$toTaxable($f['advanced_start']), $f['higher_rate']],
        [$f['top_threshold'], $f['advanced_rate']],
        [INF, $f['top_rate']],
    ]);
}

/** Fact keys shared by both Income Tax examples. */
const INCOME_TAX_FACTS = [
    'allowance'            => 'personal_allowance',
    'taper'                => 'pa_taper_threshold',
    'higher_threshold'     => 'higher_rate_threshold',
    'additional_threshold' => 'additional_rate_threshold',
    'basic_rate'           => 'income_tax_basic_rate',
    'higher_rate'          => 'income_tax_higher_rate',
    'additional_rate'      => 'income_tax_additional_rate',
];

const SCOTTISH_TAX_FACTS = [
    'allowance'          => 'personal_allowance',
    'taper'              => 'pa_taper_threshold',
    'basic_start'        => 'sct_basic_rate_threshold',
    'intermediate_start' => 'sct_intermediate_rate_threshold',
    'higher_start'       => 'sct_higher_rate_threshold',
    'advanced_start'     => 'sct_advanced_rate_threshold',
    'top_threshold'      => 'sct_top_rate_threshold',
    'starter_rate'       => 'sct_starter_rate',
    'basic_rate'         => 'sct_basic_rate',
    'intermediate_rate'  => 'sct_intermediate_rate',
    'higher_rate'        => 'sct_higher_rate',
    'advanced_rate'      => 'sct_advanced_rate',
    'top_rate'           => 'sct_top_rate',
];

// ============================================================
// Examples
// ============================================================

function exampleIncomeTaxRestOfUk(array $facts): ?array
{
    $f = exampleFacts($facts, INCOME_TAX_FACTS);
    if (!$f) {
        return null;
    }

    $rows = [];
    foreach (EXAMPLE_INCOMES as $income) {
        $tax = incomeTaxRestOfUk($income, $f);
        $rows[] = [exampleMoney($income), exampleMoney($tax), round($tax / $income * 100, 1) . '%'];
    }

    return [
        'title'   => 'Income Tax at different salaries',
        'intro'   => 'The yearly Income Tax on a salary at this year\'s rates, and the share of the salary it takes.',
        'columns' => ['Salary', 'Income Tax', 'Share of salary'],
        'rows'    => $rows,
        'note'    => 'Assumes the standard Personal Allowance (reduced above £100,000) and no other income or reliefs. National Insurance is not included.',
    ];
}

function exampleIncomeTaxScotland(array $facts): ?array
{
    $scot = exampleFacts($facts, SCOTTISH_TAX_FACTS);
    $ruk = exampleFacts($facts, INCOME_TAX_FACTS);
    if (!$scot || !$ruk) {
        return null;
    }

    $rows = [];
    foreach (EXAMPLE_INCOMES as $income) {
        $scottish = incomeTaxScotland($income, $scot);
        $elsewhere = incomeTaxRestOfUk($income, $ruk);
        $difference = $scottish - $elsewhere;
        $rows[] = [
            exampleMoney($income),
            exampleMoney($scottish),
            exampleMoney($elsewhere),
            ($difference >= 0 ? '+' : '−') . exampleMoney(abs($difference)),
        ];
    }

    return [
        'title'   => 'Scottish Income Tax compared with the rest of the UK',
        'intro'   => 'The yearly Income Tax on the same salary in Scotland and in England, Wales or Northern Ireland.',
        'columns' => ['Salary', 'Scotland', 'Rest of UK', 'Difference'],
        'rows'    => $rows,
        'note'    => 'Assumes the standard Personal Allowance (reduced above £100,000) and no other income or reliefs. A plus sign means more tax in Scotland.',
    ];
}

function exampleEmployeeNationalInsurance(array $facts): ?array
{
    $f = exampleFacts($facts, [
        'primary'    => 'ni_primary_threshold',
        'upper'      => 'ni_upper_earnings_limit',
        'main_rate'  => 'ni_employee_main_rate',
        'upper_rate' => 'ni_employee_upper_rate',
    ]);
    if (!$f) {
        return null;
    }

    $rows = [];
    foreach (EXAMPLE_INCOMES as $income) {
        $ni = max(0, min($income, $f['upper']) - $f['primary']) * $f['main_rate'] / 100
            + max(0, $income - $f['upper']) * $f['upper_rate'] / 100;
        $rows[] = [exampleMoney($income), exampleMoney($ni)];
    }

    return [
        'title'   => 'Employee National Insurance at different salaries',
        'intro'   => 'The yearly Class 1 National Insurance deducted from a salary at this year\'s rates.',
        'columns' => ['Salary', 'National Insurance'],
        'rows'    => $rows,
        'note'    => 'Category A employee, paid evenly through the year. Worked out on yearly figures, so payslip amounts can differ slightly.',
    ];
}

function exampleStudentLoanRepayments(array $facts): ?array
{
    $f = exampleFacts($facts, [
        'plan1'   => 'sl_plan1_threshold',
        'plan2'   => 'sl_plan2_threshold',
        'plan4'   => 'sl_plan4_threshold',
        'plan5'   => 'sl_plan5_threshold',
        'pg'      => 'sl_postgraduate_threshold',
        'rate'    => 'sl_undergraduate_rate',
        'pg_rate' => 'sl_postgraduate_rate',
    ]);
    if (!$f) {
        return null;
    }

    $repay = fn(float $income, float $threshold, float $rate) =>
        exampleMoney(max(0, $income - $threshold) * $rate / 100);

    $rows = [];
    foreach ([25000, 30000, 40000, 50000] as $income) {
        $rows[] = [
            exampleMoney($income),
            $repay($income, $f['plan1'], $f['rate']),
            $repay($income, $f['plan2'], $f['rate']),
            $repay($income, $f['plan4'], $f['rate']),
            $repay($income, $f['plan5'], $f['rate']),
            $repay($income, $f['pg'], $f['pg_rate']),
        ];
    }

    return [
        'title'   => 'Yearly student loan repayments by plan',
        'intro'   => 'How much is repaid in a year on each plan at different salaries, at this year\'s thresholds.',
        'columns' => ['Salary', 'Plan 1', 'Plan 2', 'Plan 4 (Scotland)', 'Plan 5', 'Postgraduate'],
        'rows'    => $rows,
        'note'    => 'Assumes steady pay through the year. Postgraduate repayments are made on top of any undergraduate plan.',
    ];
}

function exampleChildBenefitCharge(array $facts): ?array
{
    $f = exampleFacts($facts, [
        'eldest'     => 'child_benefit_eldest',
        'additional' => 'child_benefit_additional',
        'start'      => 'hicbc_start',
        'full'       => 'hicbc_full',
    ]);
    if (!$f) {
        return null;
    }

    $oneChild = $f['eldest'] * 52;
    $twoChildren = ($f['eldest'] + $f['additional']) * 52;
    // The charge is 1% for each full step across the band, reaching 100%
    // at the top (a £20,000 band gives £200 steps).
    $step = ($f['full'] - $f['start']) / 100;

    $rows = [];
    foreach ([$f['start'], $f['start'] + 5000, $f['start'] + 10000, $f['start'] + 15000, $f['full']] as $income) {
        $percent = min(100, max(0, floor(($income - $f['start']) / $step)));
        $rows[] = [
            exampleMoney($income),
            $percent . '%',
            exampleMoney($oneChild * (1 - $percent / 100)),
            exampleMoney($twoChildren * (1 - $percent / 100)),
        ];
    }

    return [
        'title'   => 'How much Child Benefit you keep',
        'intro'   => 'The High Income Child Benefit Charge takes back part of the benefit as the higher earner\'s income rises.',
        'columns' => ['Higher earner\'s income', 'Charge', 'Kept: 1 child', 'Kept: 2 children'],
        'rows'    => $rows,
        'note'    => 'Yearly figures (weekly rate × 52), based on adjusted net income.',
    ];
}

function exampleMinimumWageYearly(array $facts): ?array
{
    $f = exampleFacts($facts, [
        'nlw'        => 'nlw_21_and_over',
        'age18_20'   => 'nmw_18_to_20',
        'under18'    => 'nmw_under_18',
        'apprentice' => 'nmw_apprentice',
    ]);
    if (!$f) {
        return null;
    }

    $hoursPerWeek = 37.5;
    $labels = [
        'nlw'        => '21 and over',
        'age18_20'   => '18 to 20',
        'under18'    => 'Under 18',
        'apprentice' => 'Apprentice',
    ];

    $rows = [];
    foreach ($labels as $name => $label) {
        $rows[] = [
            $label,
            '£' . number_format($f[$name], 2),
            exampleMoney($f[$name] * $hoursPerWeek),
            exampleMoney($f[$name] * $hoursPerWeek * 52),
        ];
    }

    return [
        'title'   => 'Minimum wage as weekly and yearly pay',
        'intro'   => 'What each minimum wage rate works out at for a full-time week and a full year.',
        'columns' => ['Age band', 'Hourly', 'Weekly', 'Yearly'],
        'rows'    => $rows,
        'note'    => 'Based on 37.5 hours a week for 52 weeks, before tax and National Insurance.',
    ];
}
