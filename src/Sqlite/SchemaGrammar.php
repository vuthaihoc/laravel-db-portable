<?php

namespace DbPortable\Sqlite;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Fluent;

/**
 * Laravel's SQLite schema grammar, with $table->fullText() as an FTS5 table (see
 * FullText), dropFullText(), and drops of a table that also drop its FTS5 tables.
 */
class SchemaGrammar extends SQLiteGrammar
{
    /**
     * An FTS5 table named as the index, reading the table's rows (content=), filled with
     * the existing rows and kept up to date by triggers. The values are written with the
     * letters of FullText::FOLD folded.
     *
     * @param  Fluent<string, mixed>  $command
     * @return list<string>
     */
    public function compileFulltext(Blueprint $blueprint, Fluent $command)
    {
        $fts = (string) $command->get('index');
        $name = $this->wrapValue($fts);
        $table = $this->wrapTable($blueprint);
        $columns = array_map('strval', (array) $command->get('columns'));
        $list = implode(', ', array_map(fn (string $column) => $this->wrapValue($column), $columns));
        $new = implode(', ', array_map(fn (string $column) => FullText::foldSql('new.'.$this->wrapValue($column)), $columns));
        $old = implode(', ', array_map(fn (string $column) => FullText::foldSql('old.'.$this->wrapValue($column)), $columns));
        $rows = implode(', ', array_map(fn (string $column) => FullText::foldSql($this->wrapValue($column)), $columns));
        $language = $command->get('language');
        $content = $this->connection->getTablePrefix().$blueprint->getTable();
        $insert = "insert into {$name}(rowid, {$list}) values (new.rowid, {$new});";
        $delete = "insert into {$name}({$name}, rowid, {$list}) values ('delete', old.rowid, {$old});";

        return [
            "create virtual table {$name} using fts5({$list}, content=".$this->literal($content)
                .', tokenize='.$this->literal(FullText::tokenizer(is_string($language) ? $language : null)).')',
            "create trigger {$this->wrapValue($fts.'_insert')} after insert on {$table} begin {$insert} end",
            "create trigger {$this->wrapValue($fts.'_delete')} after delete on {$table} begin {$delete} end",
            "create trigger {$this->wrapValue($fts.'_update')} after update of {$list} on {$table} begin {$delete} {$insert} end",
            "insert into {$name}(rowid, {$list}) select rowid, {$rows} from {$table}",
        ];
    }

    /**
     * @param  Fluent<string, mixed>  $command
     * @return list<string>
     */
    public function compileDropFullText(Blueprint $blueprint, Fluent $command)
    {
        $fts = (string) $command->get('index');

        return [
            ...array_map(fn (string $event) => 'drop trigger if exists '.$this->wrapValue("{$fts}_{$event}"), ['insert', 'delete', 'update']),
            'drop table if exists '.$this->wrapValue($fts),
        ];
    }

    /**
     * @param  Fluent<string, mixed>  $command
     * @return list<string>
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command)
    {
        return [...$this->dropFullTextTables($blueprint), parent::compileDrop($blueprint, $command)];
    }

    /**
     * @param  Fluent<string, mixed>  $command
     * @return list<string>
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command)
    {
        return [...$this->dropFullTextTables($blueprint), parent::compileDropIfExists($blueprint, $command)];
    }

    /**
     * The FTS5 tables of a dropped table: left behind, they would break the next
     * $table->fullText() of a table of that name. Its triggers go with the table.
     *
     * @return list<string>
     */
    protected function dropFullTextTables(Blueprint $blueprint): array
    {
        return array_map(
            fn (string $name) => 'drop table if exists '.$this->wrapValue($name),
            array_keys(FullText::tables($this->connection, $blueprint->getTable())),
        );
    }

    protected function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
