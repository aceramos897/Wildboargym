<?php
/**
 * Input validation & output encoding (XSS defense).
 */

/** HTML-escape for safe output in attributes and text nodes. */
function e(?string $s): string
{
    if ($s === null) { return ''; }
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE);
}

/** Trim + length-cap + optional regex allow-list. Returns '' when invalid. */
function clean_str(?string $v, int $max = 200, string $pattern = '', bool $required = false): string
{
    if ($v === null) { $v = ''; }
    $v = trim((string)$v);
    if (strlen($v) > $max) { $v = substr($v, 0, $max); }
    if ($required && $v === '') { return ''; }
    if ($pattern !== '' && preg_match($pattern, $v) !== 1) { return ''; }
    return $v;
}

function valid_email(?string $v): bool
{
    $v = trim($v ?? '');
    return strlen($v) <= 160 && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i', $v) === 1;
}

function clean_email(?string $v, bool $required = false): string
{
    return valid_email($v) ? strtolower(trim((string)$v)) : ($required ? '' : '');
}

/**
 * Lenient name cleaner: accepts letters (incl. Unicode), spaces, dots,
 * COMMAS, apostrophes and hyphens. Unknown characters are stripped instead
 * of rejecting the form; the result must start with a letter.
 */
function clean_name(?string $v, bool $required = false): string
{
    $v = clean_str($v, 120, '', $required);
    if ($v !== '') {
        $v = preg_replace('/\s+/u', ' ', $v);
        $v = preg_replace('/[^\p{L}\p{M} .,\x27\-]/u', '', $v);
        $v = trim($v, " \t\n\r\0\x0B,");
        if (strlen($v) < 2 || preg_match('/^\p{L}/u', $v) !== 1) { $v = ''; }
    }
    return $v;
}

/** Build a valid username candidate from the email local-part (or the name). */
function derive_username(string $email, string $name): string
{
    $base = strtolower(trim(explode('@', $email, 2)[0]));
    $base = preg_replace('/[^a-z0-9_.]/', '', $base);
    if (strlen($base) < 3) {
        $parts = preg_split('/[\s.,]+/', trim($name));
        $base = strtolower((string)($parts[0] ?? ''));
        $base = preg_replace('/[^a-z0-9_.]/', '', $base);
    }
    if (strlen($base) < 3) { $base = 'user'; }
    return substr($base, 0, 50);
}

function clean_username(?string $v, bool $required = false): string
{
    $v = clean_str($v, 60, '', $required);
    if ($v !== '' && preg_match('/^[a-zA-Z0-9_.\-]{3,60}$/', $v) !== 1) { $v = ''; }
    return $v;
}

/** Member codes look like WB-0001 / ABC12. Stored upper-cased. */
function clean_member_id(?string $v, bool $required = false): string
{
    $v = clean_str($v, 20, '', $required);
    if ($v !== '' && preg_match('/^[A-Z]{2,6}\-?[0-9]{1,6}$/i', $v) !== 1) { $v = ''; }
    return $v === '' ? '' : mb_strtoupper($v);
}

function clean_phone(?string $v): string
{
    $v = clean_str($v, 20, '', false);
    if ($v !== '' && preg_match('/^[0-9+\-(). ]{7,20}$/', $v) !== 1) { $v = ''; }
    return $v;
}

/** ISO date (YYYY-MM-DD) — safe for lexical comparisons. */
function clean_date(?string $v, bool $required = false): string
{
    $v = clean_str($v, 10, '', $required);
    if ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) { $v = ''; }
    return $v;
}

/**
 * PII masking for public kiosk screens: shows only the first name and the
 * first letter of the surname, e.g. "Justine A." — never the full name.
 */
function masked_name(string $full): string
{
    $parts = explode(' ', trim($full));
    if (count($parts) === 1) { return $parts[0]; }
    return $parts[0] . ' ' . substr($parts[1], 0, 1) . '.';
}

/**
 * Excel formula-injection-safe CSV cell: cells starting with = + - @ get a
 * leading apostrophe so spreadsheet apps treat them as text, never as formulas.
 */
function csv_cell(string $v): string
{
    if ($v !== '' && preg_match('/^[=+\-@]/', $v) === 1) { $v = "'" . $v; }
    return $v;
}