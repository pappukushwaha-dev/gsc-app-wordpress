<?php
declare(strict_types=1);

/**
 * includes/liquid_lite.php
 *
 * Renders the Encharge email templates against a stored payload, so the
 * admin preview shows the same email the customer received.
 *
 * WHY THIS EXISTS AND WHAT IT IS NOT
 *
 * view-mail.php previously ran str_replace over seven placeholders. The
 * digest template carries around sixty merge tags and a dozen conditional
 * blocks, so str_replace printed the tags as literal text and, worse,
 * rendered the paid and free variants one after the other, because
 * {% else %} was just more text to it.
 *
 * This is not a Liquid implementation. It handles the four constructs
 * these templates use and nothing else:
 *
 *   {{person.field}}                       value
 *   {{ person.field | date: '%b %-d' }}    value through a date filter
 *   {% if expr %} ... {% endif %}          with else and elsif
 *   nested ifs                             to any depth
 *
 * Supported expression shapes, which is all the templates contain:
 *
 *   person.x                   truthy
 *   person.x != ""             comparison against a literal
 *   person.x == "yes"
 *   person.x > 0               numeric
 *   a and b, a or b            joined
 *
 * Anything else evaluates false rather than throwing, so an unfamiliar
 * construct hides a block instead of taking the page down. If the
 * templates grow past this, replace the file with a real Liquid library
 * (composer require liquid/liquid) rather than extending it: a
 * half-implemented language is where preview and email start disagreeing
 * without anyone noticing.
 */

if (!function_exists('liquid_render')) {

/**
 * @param string $template Liquid markup
 * @param array  $person   flat field map, keys without the person. prefix
 */
function liquid_render(string $template, array $person): string
{
    $parts = preg_split(
        '/(\{%.*?%\}|\{\{.*?\}\})/s',
        $template,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );

    if ($parts === false) {
        return $template;
    }

    $out      = '';
    $stack    = [];     // each entry: [a branch has been taken, parent was emitting]
    $emitting = true;

    foreach ($parts as $part) {

        /* ---- tag ---- */
        if (strncmp($part, '{%', 2) === 0) {
            $body = trim(substr($part, 2, -2));

            if (strncmp($body, 'if ', 3) === 0) {
                $parent = $emitting;
                /* Only evaluate when the parent is emitting. A condition
                   inside a hidden block must not run: its fields are not
                   meant to be read there, and evaluating costs nothing but
                   can only mislead. */
                $taken  = $parent && liquid_eval(substr($body, 3), $person);

                $stack[]  = [$taken, $parent];
                $emitting = $taken;

            } elseif ($body === 'else') {
                if ($stack) {
                    [$taken, $parent] = $stack[count($stack) - 1];
                    $emitting = $parent && !$taken;
                    $stack[count($stack) - 1][0] = true;
                }

            } elseif (strncmp($body, 'elsif ', 6) === 0 || strncmp($body, 'elseif ', 7) === 0) {
                if ($stack) {
                    [$taken, $parent] = $stack[count($stack) - 1];
                    $expr  = substr($body, strpos($body, ' ') + 1);
                    $value = $parent && !$taken && liquid_eval($expr, $person);

                    $emitting = $value;
                    if ($value) {
                        $stack[count($stack) - 1][0] = true;
                    }
                }

            } elseif ($body === 'endif') {
                if ($stack) {
                    $frame    = array_pop($stack);
                    $emitting = $frame[1];
                }
            }
            /* Unknown tags are dropped rather than printed: a stray
               {% assign %} should not appear in the rendered email. */

            continue;
        }

        /* ---- output ---- */
        if (strncmp($part, '{{', 2) === 0) {
            if ($emitting) {
                $out .= liquid_output(trim(substr($part, 2, -2)), $person);
            }
            continue;
        }

        /* ---- literal ---- */
        if ($emitting) {
            $out .= $part;
        }
    }

    return $out;
}

/** One operand: a quoted string, a number, or a person field. */
function liquid_lookup(string $expr, array $person)
{
    $expr = trim($expr);

    if ($expr === '') {
        return '';
    }

    $first = $expr[0];
    if (($first === '"' || $first === "'") && strlen($expr) > 1 && substr($expr, -1) === $first) {
        return substr($expr, 1, -1);
    }

    if (preg_match('/^-?\d+(\.\d+)?$/', $expr)) {
        return $expr + 0;
    }

    $key = strncmp($expr, 'person.', 7) === 0 ? substr($expr, 7) : $expr;

    return $person[$key] ?? '';
}

/**
 * Liquid truthiness, plus two of our own.
 *
 * The string "0" is false here. Every count in these payloads is stored as
 * a string, and a section headed "Keywords moving up (0)" with no rows
 * under it is worse than no section.
 *
 * The literal "no" is false because the payload uses yes/no flags, and a
 * bare {% if person.digest_is_steady %} would otherwise be true for both.
 */
function liquid_truthy($v): bool
{
    if ($v === null || $v === false) {
        return false;
    }
    if (is_numeric($v)) {
        return (float)$v != 0.0;
    }
    $s = strtolower(trim((string)$v));

    return !($s === '' || $s === 'false' || $s === 'no' || $s === '0');
}

/** Evaluate one condition. */
function liquid_eval(string $expr, array $person): bool
{
    $expr = trim($expr);

    /* "and" binds tighter than "or" in Liquid, but these templates never
       mix the two in one expression, so splitting on "or" first and "and"
       second is enough and stays readable. */
    foreach ([['/\s+or\s+/i', false], ['/\s+and\s+/i', true]] as [$pattern, $isAnd]) {
        $parts = preg_split($pattern, $expr);

        if ($parts !== false && count($parts) > 1) {
            foreach ($parts as $p) {
                $v = liquid_eval($p, $person);
                if ($isAnd && !$v) {
                    return false;
                }
                if (!$isAnd && $v) {
                    return true;
                }
            }
            return $isAnd;
        }
    }

    if (preg_match('/^(.+?)\s*(==|!=|>=|<=|>|<)\s*(.+)$/', $expr, $m)) {
        $a  = liquid_lookup($m[1], $person);
        $op = $m[2];
        $b  = liquid_lookup($m[3], $person);

        if ($op === '>' || $op === '<' || $op === '>=' || $op === '<=') {
            /* Counts arrive as display strings, so "1,240" has to lose its
               separators before it is a number. Without this every count
               over 999 compares as 1. */
            $an = (float)str_replace(',', '', (string)$a);
            $bn = (float)str_replace(',', '', (string)$b);

            switch ($op) {
                case '>':  return $an >  $bn;
                case '<':  return $an <  $bn;
                case '>=': return $an >= $bn;
                case '<=': return $an <= $bn;
            }
        }

        $same = ((string)$a === (string)$b);

        return $op === '==' ? $same : !$same;
    }

    return liquid_truthy(liquid_lookup($expr, $person));
}

/** Render one {{ ... }}, applying any filters. */
function liquid_output(string $expr, array $person): string
{
    $parts = array_map('trim', explode('|', $expr));
    $value = liquid_lookup(array_shift($parts), $person);

    foreach ($parts as $filter) {

        if (strncmp($filter, 'date:', 5) === 0) {
            $fmt = trim(substr($filter, 5));
            $fmt = trim($fmt, "'\"");

            $ts = strtotime((string)$value);
            if ($ts !== false) {
                /* %-d is a GNU extension and is not portable, so it is
                   resolved here rather than handed to strftime. */
                $fmt   = str_replace('%-d', (string)(int)date('j', $ts), $fmt);
                $value = liquid_strftime($fmt, $ts);
            }

        } elseif (strncmp($filter, 'default:', 8) === 0) {
            if (!liquid_truthy($value)) {
                $value = trim(trim(substr($filter, 8)), "'\"");
            }

        } elseif ($filter === 'upcase') {
            $value = mb_strtoupper((string)$value);

        } elseif ($filter === 'downcase') {
            $value = mb_strtolower((string)$value);
        }
    }

    return (string)$value;
}

/**
 * The handful of strftime codes these templates use.
 *
 * strftime() itself is deprecated in PHP 8.1 and removed in 9, and this
 * needs to keep working past that.
 */
function liquid_strftime(string $fmt, int $ts): string
{
    $map = [
        '%b' => date('M', $ts),
        '%B' => date('F', $ts),
        '%d' => date('d', $ts),
        '%e' => date('j', $ts),
        '%m' => date('m', $ts),
        '%Y' => date('Y', $ts),
        '%y' => date('y', $ts),
        '%H' => date('H', $ts),
        '%M' => date('i', $ts),
        '%A' => date('l', $ts),
        '%a' => date('D', $ts),
    ];

    return strtr($fmt, $map);
}

}
