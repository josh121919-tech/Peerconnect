<?php

/**
 * ResourceAdminRepository — the resource library, as an admin monitors it.
 *
 * Separate from what a member sees: the member's page is scoped to what they
 * may read and carries their bookmarks, and this one answers "what is in the
 * library and how is it being used". Sharing one query would mean one of the
 * two carrying clauses it has no use for.
 */
class ResourceAdminRepository extends Repository
{
    /** Everything a monitoring card needs. $whereSQL already begins with WHERE. */
    public static function page(
        mysqli $con,
        string $whereSQL,
        string $orderSQL,
        string $types,
        array $args,
        int $limit,
        int $offset
    ): array {
        return self::typedRows($con, "
            SELECT r.resource_id, r.club, r.title, r.description, r.original_name,
                   r.file_type, r.file_size, r.download_count, r.created_at,
                   u.firstname, u.lastname, u.role AS uploader_role, u.user_id AS uploader_id
            FROM resources r
            JOIN users u ON u.user_id = r.uploader_id
            $whereSQL
            ORDER BY $orderSQL
            LIMIT ? OFFSET ?
        ", $types . 'ii', array_merge($args, [$limit, $offset]));
    }

    /** How many rows those same filters match. */
    public static function countMatching(mysqli $con, string $whereSQL, string $types, array $args): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*)
            FROM resources r
            JOIN users u ON u.user_id = r.uploader_id
            $whereSQL
        ", $types, $args);
    }

    /**
     * The headline figures, over the whole library rather than the page being
     * shown — "12 resources" that changed when you paged would be a lie.
     *
     * The *_week counts are the last seven days, for the trend line under
     * each tile. There is deliberately no such figure for downloads: the
     * table keeps a running download_count and not one row per download, so
     * "downloads this week" is not a number this schema can answer, and a
     * plausible-looking guess is worse than no line at all.
     */
    public static function figures(mysqli $con): array
    {
        $row = self::typedRow($con, "
            SELECT COUNT(*)                        AS total,
                   COALESCE(SUM(file_size), 0)     AS bytes,
                   COALESCE(SUM(download_count), 0) AS downloads,
                   COUNT(DISTINCT uploader_id)     AS uploaders,
                   COUNT(DISTINCT club)            AS clubs,
                   SUM(created_at >= NOW() - INTERVAL 7 DAY) AS new_week,
                   COUNT(DISTINCT CASE WHEN created_at >= NOW() - INTERVAL 7 DAY
                                       THEN uploader_id END) AS uploaders_week
            FROM resources
            WHERE is_active = 1
        ") ?? [];

        foreach (['total', 'bytes', 'downloads', 'uploaders', 'clubs', 'new_week', 'uploaders_week'] as $k) {
            $row[$k] = (int)($row[$k] ?? 0);
        }
        return $row;
    }

    /**
     * How many live resources each club has, keyed by club name. Clubs with
     * nothing are absent rather than zero — the caller walks PC_CLUBS, so it
     * knows about the empty ones and this only has to answer for the rest.
     */
    public static function clubCounts(mysqli $con): array
    {
        $out = [];
        foreach (self::typedRows($con, "
            SELECT club, COUNT(*) AS n
            FROM resources
            WHERE is_active = 1
            GROUP BY club
        ") as $r) {
            $out[(string)$r['club']] = (int)$r['n'];
        }
        return $out;
    }

    /** One resource, for the admin who is about to remove it. */
    public static function find(mysqli $con, int $id): ?array
    {
        return self::typedRow($con, "
            SELECT r.resource_id, r.title, r.file_path, r.uploader_id
            FROM resources r
            WHERE r.resource_id = ?
        ", 'i', [$id]);
    }

    /** Removes the row. The caller deletes the file. */
    public static function remove(mysqli $con, int $id): int
    {
        return self::execute($con, "DELETE FROM resources WHERE resource_id = ?", 'i', [$id]);
    }
}
