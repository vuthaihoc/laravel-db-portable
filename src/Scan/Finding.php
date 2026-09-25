<?php

namespace DbPortable\Scan;

final class Finding
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly Rule $rule,
        public readonly string $match,
        public readonly string $snippet,
    ) {}

    /**
     * @return array{file: string, line: int, rule: string, breaks_on: list<string>, match: string, snippet: string, suggestion: string}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'rule' => $this->rule->id,
            'breaks_on' => $this->rule->breaksOn,
            'match' => $this->match,
            'snippet' => $this->snippet,
            'suggestion' => $this->rule->suggestion,
        ];
    }
}
