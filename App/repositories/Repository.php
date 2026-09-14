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
 * Rows come back the way $con->query() returns them: every non-null value is
 * a string. The pages were written against query() results, and a prepared
 * statement would otherwise hand back native ints and floats, so a page
 * comparing $row['score'] === '0', or printing a float, could quietly change
 * while it is being moved over. Callers that want numbers cast, as the pages
 * already do.
 */
abstract class Repository
{
    /** Every row the query returns. */
    protected static function rows(mysqli $con, string $sql, string $types = '', array $args = []): array
    {
        $st = $con->prepare($sql);
        if ($types !== '') {
            $st->bind_param($types, ...$args);
        }
        $st->execute();
        $result = $st->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $st->close();

        foreach ($rows as $i => $row) {
            foreach ($row as $key => $value) {
                if ($value !== null && !is_string($value)) {
                    $rows[$i][$key] = (string)$value;
                }
            }
        }
        return $rows;
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
}
