<?php

/**
 * Repository — shared plumbing for everything in App/repositories.
 *
 * A repository is where a page gets its data instead of writing SQL inline.
 * Each subclass covers one area (users, sessions, messages ...) and names its
 * queries, so a rule such as "an upcoming session" is written in one place and
 * every page that needs it asks for it by name.
 *
 * Every query here is a prepared statement.
 *
 * By default rows come back the way $con->query() returns them: every non-null
 * value is a string. Most pages were written against query() results, and a
 * prepared statement would otherwise hand back native ints and floats, so a
 * page comparing $row['score'] === '0', or printing a float, could quietly
 * change while it is being moved over.
 *
 * typedRows() returns what a prepared statement gives — ints as ints — for the
 * pages that already used prepared statements and rely on it, for example by
 * passing a row to json_encode(), where 60 and "60" print differently.
 */
abstract class Repository
{
    /** Every row the query returns, values as strings (like $con->query()). */
    protected static function rows(mysqli $con, string $sql, string $types = '', array $args = []): array
    {
        $rows = self::typedRows($con, $sql, $types, $args);
        foreach ($rows as $i => $row) {
            foreach ($row as $key => $value) {
                if ($value !== null && !is_string($value)) {
                    $rows[$i][$key] = (string)$value;
                }
            }
        }
        return $rows;
    }

    /** Every row the query returns, with the native types a prepared statement gives. */
    protected static function typedRows(mysqli $con, string $sql, string $types = '', array $args = []): array
    {
        $st = $con->prepare($sql);
        if ($types !== '') {
            $st->bind_param($types, ...$args);
        }
        $st->execute();
        $result = $st->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $st->close();
        return $rows;
    }

    /** Runs an INSERT, UPDATE or DELETE and returns how many rows it changed. */
    protected static function execute(mysqli $con, string $sql, string $types = '', array $args = []): int
    {
        $st = $con->prepare($sql);
        if ($types !== '') {
            $st->bind_param($types, ...$args);
        }
        $st->execute();
        $changed = $st->affected_rows;
        $st->close();
        return $changed;
    }

    /** The first row, or null when there is none. */
    protected static function row(mysqli $con, string $sql, string $types = '', array $args = []): ?array
    {
        return self::rows($con, $sql, $types, $args)[0] ?? null;
    }

    /** The first column of the first row, or null when there is no row. */
    protected static function value(mysqli $con, string $sql, string $types = '', array $args = []): ?string
    {
        $row = self::row($con, $sql, $types, $args);
        return $row === null ? null : reset($row);
    }

    /** One "?" per value, comma-separated, for an IN (...) list. Never call it with an empty list. */
    protected static function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
