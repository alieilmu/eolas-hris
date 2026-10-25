<?php

/**
 * PHP codemod of the terminology refactor: rewrites identifiers and string literals of the
 * given files in place, token by token (token_get_all), leaving comments and UI text alone.
 *
 *   php database/refactor/codemod.php <file>...       (prints a report of SQL-ish strings changed)
 */
require __DIR__.'/Mapper.php';

$map = new TerminologyMapper(__DIR__.'/terminology.json');
$report = [];
$routeCalls = ['prefix', 'get', 'post', 'put', 'patch', 'delete', 'any', 'options', 'match', 'redirect', 'permanentRedirect'];

foreach (array_slice($argv, 1) as $file) {
    $src = file_get_contents($file);
    $tokens = token_get_all($src);
    // Variables keep their name where the new one is taken in the file (or two map to one).
    preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $src, $m);
    $vars = array_unique($m[1]);
    $skipVars = [];
    $targets = [];
    foreach ($vars as $v) {
        $nv = $map->word($v);
        if ($nv !== $v && (in_array($nv, $vars, true) || isset($targets[$nv]))) {
            $skipVars[$v] = true;
            if (isset($targets[$nv])) {
                $skipVars[$targets[$nv]] = true;
            }
        }
        $targets[$nv] = $v;
    }
    $isRoutes = str_contains($file, 'routes/');
    $out = '';
    $n = count($tokens);
    $prev = []; // last meaningful tokens: [type, text]
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (! is_array($t)) {
            $out .= $t;
            $prev[] = [null, $t];

            continue;
        }
        [$type, $text] = $t;
        $new = $text;
        switch ($type) {
            case T_STRING:
                $before = lastMeaningful($prev);
                if (preg_match('/^[A-Z]/', $text)) {
                    // A class name (or a constant): only classes from the map change.
                    if (! preg_match('/^[A-Z0-9_]+$/', $text)) {
                        $new = $map->cls($text);
                    }
                } else {
                    $new = $map->word($text);
                }
                break;
            case T_NAME_QUALIFIED:
            case T_NAME_FULLY_QUALIFIED:
            case T_NAME_RELATIVE:
                $isNamespaceDecl = lastMeaningful($prev)[0] === T_NAMESPACE;
                $new = $map->qualified($text, $isNamespaceDecl);
                break;
            case T_VARIABLE:
                $name = substr($text, 1);
                if ($name !== 'this' && ! isset($skipVars[$name])) {
                    $new = '$'.$map->word($name);
                }
                break;
            case T_CONSTANT_ENCAPSED_STRING:
                $q = $text[0];
                $inner = substr($text, 1, -1);
                $mapped = $map->string($inner);
                if ($isRoutes && isRouteArg($prev, $routeCalls)) {
                    $mapped = $map->hyphenateUrl(str_starts_with($mapped, '/') ? $mapped : '/'.$mapped);
                    $mapped = str_starts_with($inner, '/') ? $mapped : substr($mapped, 1);
                }
                if ($mapped !== $inner && preg_match('/\s/', $inner)) {
                    $report[] = "$file: $inner  =>  $mapped";
                }
                $new = $q.$mapped.$q;
                break;
            case T_ENCAPSED_AND_WHITESPACE:
                $mapped = $map->string($text);
                if ($mapped !== $text && preg_match('/\s/', $text)) {
                    $report[] = "$file: \"$text\"  =>  \"$mapped\"";
                }
                $new = $mapped;
                break;
        }
        $out .= $new;
        if (! in_array($type, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $prev[] = [$type, $text];
            if (count($prev) > 8) {
                array_shift($prev);
            }
        }
    }
    if ($out !== $src) {
        file_put_contents($file, $out);
    }
}
echo implode("\n", $report), "\n";

function lastMeaningful(array $prev): array
{
    return $prev ? end($prev) : [null, ''];
}

/** The string is the URL argument of a routing call: ->prefix('x'), Route::get('x', …), match([..], 'x'). */
function isRouteArg(array $prev, array $calls): bool
{
    $c = count($prev);
    if ($c >= 2 && $prev[$c - 1][1] === '(' && in_array($prev[$c - 2][1], $calls, true)) {
        return true;
    }
    // Route::match(['get', 'post'], '/x'): the string after "],"
    if ($c >= 3 && $prev[$c - 1][1] === ',' && $prev[$c - 2][1] === ']') {
        for ($k = $c - 3; $k >= 0; $k--) {
            if ($prev[$k][1] === 'match') {
                return true;
            }
        }
    }

    return false;
}
