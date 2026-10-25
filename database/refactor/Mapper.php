<?php

/**
 * The mapping rules of the terminology refactor, shared by the PHP codemod (codemod.php) and
 * the migration that renames the data (one word -> one word everywhere, from terminology.json).
 */
final class TerminologyMapper
{
    public array $tables;
    public array $tokens;
    public array $classes;
    public array $ns;
    public array $pages;

    public function __construct(string $json)
    {
        $d = json_decode(file_get_contents(getenv('TERMINOLOGY_JSON') ?: $json), true);
        $this->tables = $d['tables'];
        $this->tokens = $d['tokens'];
        $this->classes = $d['classes'];
        $this->ns = $d['namespaces'];
        $this->pages = $d['pages'];
    }

    /** One identifier-like word (column, key, value, relation, variable without "$"). */
    public function word(string $w): string
    {
        return $this->tables[$w] ?? $this->tokens[$w] ?? $w;
    }

    /** A PascalCase class / component name. */
    public function cls(string $w): string
    {
        return $this->classes[$w] ?? $w;
    }

    /** A namespace / folder segment. */
    public function nsSeg(string $w): string
    {
        return $this->ns[$w] ?? $this->classes[$w] ?? $w;
    }

    /** "App\Support\Karyawan\Nrp": namespace segments, then the class. */
    public function qualified(string $name, bool $allNamespace = false): string
    {
        $parts = explode('\\', $name);
        $last = count($parts) - 1;
        foreach ($parts as $i => $p) {
            if ($p === '') {
                continue;
            }
            $parts[$i] = ($allNamespace || $i < $last) ? $this->nsSeg($p) : $this->cls($p);
        }

        return implode('\\', $parts);
    }

    /** The content of a string literal (without quotes). */
    public function string(string $s, bool $routeFile = false): string
    {
        if ($s === '') {
            return $s;
        }
        if (isset($this->pages[$s])) {
            return $this->pages[$s];
        }
        if (preg_match('/\s/', $s)) {
            return $this->isSql($s) ? $this->sql($s) : $s;
        }
        if (isset($this->tokens[$s]) || isset($this->tables[$s])) {
            return $this->word($s);
        }
        $isUrl = str_starts_with($s, '/');
        $hasPathDelim = (bool) preg_match('#[/\\\\]#', $s);
        $out = preg_replace_callback('/[A-Za-z_][A-Za-z0-9_-]*/', function ($m) use ($hasPathDelim, $isUrl) {
            $seg = $m[0];
            if (isset($this->tokens[$seg]) || isset($this->tables[$seg])) {
                $new = $this->word($seg);
            } elseif (str_contains($seg, '-')) {
                $new = implode('-', array_map(fn ($p) => $this->piece($p, $hasPathDelim), explode('-', $seg)));
            } else {
                $new = $this->piece($seg, $hasPathDelim);
            }

            return $new;
        }, $s);

        return $isUrl ? $this->hyphenateUrl($out) : $out;
    }

    private function piece(string $p, bool $hasPathDelim): string
    {
        if (preg_match('/^[A-Z]/', $p)) {
            return $hasPathDelim ? $this->nsOrClass($p) : $p;
        }

        return $this->word($p);
    }

    private function nsOrClass(string $p): string
    {
        return $this->classes[$p] ?? $this->ns[$p] ?? $p;
    }

    /** "/core_hr/employee/{employee}/full_data" -> hyphens in URL segments (not in {params}). */
    public function hyphenateUrl(string $url): string
    {
        return preg_replace_callback('#(?<=/|^)([a-z0-9_]+)(?=/|$|\?|\#)#', fn ($m) => str_replace('_', '-', $m[1]), $url);
    }

    public function isSql(string $s): bool
    {
        return (bool) preg_match('/\b(sum|count|max|min|avg|coalesce|concat|group_concat|ifnull|date|year|month|lower|upper|distinct|cast)\s*\(|\bis\s+(not\s+)?null\b|\bnot\s+in\b|\b(order|group)\s+by\b|\s(as|desc|asc)\s+[a-z_]|[a-z_]+\.[a-z_]+\s*(=|<|>|is\b|,|\))|^\s*[a-z_]+\s*(=|<|>|<=|>=)\s*\?/i', $s)
            && ! preg_match('/[A-Z][a-z]+ [a-z]+ [a-z]+/', $s); // not a sentence
    }

    public function sql(string $s): string
    {
        return preg_replace_callback("/(?<![A-Za-z0-9_])[a-z_][a-z0-9_]*(?![A-Za-z0-9_])/", fn ($m) => $this->word($m[0]), $s);
    }
}
