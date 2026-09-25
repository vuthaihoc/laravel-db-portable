<?php

namespace DbPortable\Scan;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds database-specific SQL in the string literals of PHP files.
 */
class Scanner
{
    /** A literal "looks like SQL" when it contains one of these. */
    protected const SQL_HINT = '/\b(select|update|insert|delete|where|order\s+by|group\s+by|having|join|from|coalesce|case\s+when|values|set)\b/i';

    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(protected array $rules = [])
    {
        $this->rules = $rules ?: Rule::defaults();
    }

    /**
     * @param  list<string>  $paths  files or directories
     * @param  list<string>  $targets  families to check against (mysql, matrixone, pgsql, sqlite); all when empty
     * @return list<Finding>
     */
    public function scan(array $paths, array $targets = [], string $basePath = ''): array
    {
        $findings = [];

        foreach ($this->phpFiles($paths) as $file) {
            $source = (string) file_get_contents($file);
            $relative = $basePath !== '' && str_starts_with($file, $basePath) ? ltrim(substr($file, strlen($basePath)), '/') : $file;

            foreach ($this->scanSource($source, $targets) as [$line, $rule, $match, $snippet]) {
                $findings[] = new Finding($relative, $line, $rule, $match, $snippet);
            }
        }

        return $findings;
    }

    /**
     * @param  list<string>  $targets
     * @return list<array{int, Rule, string, string}>
     */
    public function scanSource(string $source, array $targets = []): array
    {
        $rules = array_values(array_filter(
            $this->rules,
            fn (Rule $rule) => $targets === [] || array_intersect($rule->breaksOn, $targets) !== [],
        ));

        $results = [];

        foreach ($this->stringLiterals($source) as [$line, $text, $sqlArgument]) {
            $looksLikeSql = $sqlArgument || preg_match(self::SQL_HINT, $text);

            foreach ($rules as $rule) {
                if ($rule->sqlOnly && ! $looksLikeSql) {
                    continue;
                }

                if (preg_match_all($rule->pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as [$match, $offset]) {
                        $results[] = [
                            $line + substr_count(substr($text, 0, $offset), "\n"),
                            $rule,
                            $match,
                            $this->snippet($text, $offset),
                        ];
                    }
                }
            }
        }

        return $results;
    }

    /**
     * String literals with their starting line, including the constant parts
     * of interpolated strings and heredocs, and whether the literal starts
     * the arguments of a raw SQL method (whereRaw(), DB::raw(), select()...).
     *
     * @return list<array{int, string, bool}>
     */
    protected function stringLiterals(string $source): array
    {
        $literals = [];
        $significant = [];
        $callOpen = false;

        foreach (token_get_all($source) as $token) {
            $id = is_array($token) ? $token[0] : null;

            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($id === T_START_HEREDOC) {
                // A heredoc argument keeps the call context of its opening token.
                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                /** @var array{int, string, int} $token */
                $text = $id === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : $token[1];
                $literals[] = [$token[2], $text, $callOpen || $this->opensSqlCall($significant)];
                $callOpen = $callOpen || $this->opensSqlCall($significant);

                continue;
            }

            if ($token === '"' || $id === T_END_HEREDOC || $id === T_VARIABLE || $id === T_CURLY_OPEN || $token === '}') {
                // Parts of one interpolated string share its context.
                continue;
            }

            $callOpen = false;
            $significant[] = $token;

            if (count($significant) > 3) {
                array_shift($significant);
            }
        }

        return $literals;
    }

    /**
     * Whether the last significant tokens are "sqlMethod(".
     *
     * @param  list<array{int, string, int}|string>  $tokens
     */
    protected function opensSqlCall(array $tokens): bool
    {
        $count = count($tokens);

        if ($count < 2 || $tokens[$count - 1] !== '(' || ! is_array($tokens[$count - 2]) || $tokens[$count - 2][0] !== T_STRING) {
            return false;
        }

        return (bool) preg_match('/Raw$|^(raw|select|selectOne|scalar|statement|unprepared|insert|update|delete|cursor|affectingStatement)$/i', $tokens[$count - 2][1]);
    }

    protected function snippet(string $text, int $offset): string
    {
        $start = max(0, $offset - 40);
        $snippet = substr($text, $start, 100);

        return trim((string) preg_replace('/\s+/', ' ', ($start > 0 ? '…' : '').$snippet.(strlen($text) > $start + 100 ? '…' : '')));
    }

    /**
     * @param  list<string>  $paths
     * @return iterable<string>
     */
    protected function phpFiles(array $paths): iterable
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                yield $path;

                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                    yield $file->getPathname();
                }
            }
        }
    }
}
