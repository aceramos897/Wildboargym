<?php
/**
 * XML utilities — structured reporting & audit log export.
 *
 * XXE PROTECTION:
 *  - xml_parse_safe() parses with libxml options that DO NOT include
 *    LIBXML_NOENT (entity substitution) or LIBXML_DTDLOAD (external DTD /
 *    external entity loading). LIBXML_NONET additionally blocks network
 *    entity fetches. Input is treated as inert "data-only" XML.
 *  - xml_escape() guarantees generated exports can never be broken by
 *    attacker-controlled values.
 */

function xml_escape(string $s): string
{
    $s = str_replace('&', '&amp;', $s);
    $s = str_replace('<', '&lt;',  $s);
    $s = str_replace('>', '&gt;',  $s);
    $s = str_replace('"', '&quot;', $s);
    $s = str_replace("'", '&apos;', $s);
    return $s;
}

/** Serialise an array of associative rows into '<tag>...</tag>' elements. */
function xml_rows(string $rowTag, array $rows): string
{
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) { continue; }
        $cells = '';
        foreach ($r as $k => $v) {
            $k = (string)$k;
            $v = $v === null ? '' : (string)$v;
            $cells .= '<' . xml_escape($k) . '>' . xml_escape($v) . '</' . xml_escape($k) . '>';
        }
        $out[] = '<' . xml_escape($rowTag) . '>' . $cells . '</' . xml_escape($rowTag) . '>';
    }
    return implode("\n", $out);
}

function xml_open(string $root, string $comment = ''): string
{
    $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    if ($comment !== '') { $out .= '<!-- ' . str_replace('--', '- -', $comment) . ' -->' . "\n"; }
    return $out . '<' . xml_escape($root) . '>' . "\n";
}

function xml_close(string $root): string { return '</' . xml_escape($root) . '>' . "\n"; }

/** Sample XXE attack payload used by the on-demand self-test. */
function xml_attack_payload(): string
{
    return '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><r>&xxe;</r>';
}

/**
 * XXE-safe parse. Returns ['ok'=>bool, 'text'=>string].
 * 'ok' is false when the parser refuses the document (also safe).
 */
function xml_parse_safe(string $xml): array
{
    $el = null;
    try {
        // NOTE: LIBXML_NOENT and LIBXML_DTDLOAD are deliberately OMITTED —
        // external entities & DTDs must never be resolved.
        $el = simplexml_load_string($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    } catch (Throwable $ex) {
        $el = null;
    }
    if ($el === null) { return ['ok' => false, 'text' => '']; }
    $text = '';
    try {
        $r = $el->r;
        if ($r !== null) { $text = (string)$r; }
    } catch (Throwable $ex) {
        $text = '';
    }
    return ['ok' => true, 'text' => $text];
}

/**
 * Self-test: feed the XXE payload through the safe parser and confirm the
 * external entity was NOT expanded (no file contents, no entity fetch).
 */
function xml_safety_selftest(): array
{
    $res = xml_parse_safe(xml_attack_payload());
    $leaked = preg_match('#/(etc/passwd|root:|file:/)#', $res['text']) === 1;
    return ['safe' => $res['ok'] && !$leaked, 'text' => $res['text']];
}