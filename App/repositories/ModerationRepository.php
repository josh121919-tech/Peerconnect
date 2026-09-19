<?php

/**
 * ModerationRepository — blocks, restrictions and reports against accounts.
 *
 * A block or restriction row is history: unblocking an account leaves its
 * block rows in place, and a newer restriction does not delete the one it
 * replaces. What is in force is decided from the newest row (see
 * ModerationService), not from whether any row exists.
 */
class ModerationRepository extends Repository
{
    // ── Blocks ──────────────────────────────────────────────────────────────

    /** Records that the account was blocked, and why. */
    public static function recordBlock(mysqli $con, int $userId, string $reason): void
    {
        self::execute($con, "INSERT INTO blocks (user_id, reason, blocked_at) VALUES (?, ?, NOW())", 'is', [$userId, $reason]);
    }

    /** The account's newest $limit block rows ('reason', 'blocked_at'), newest first. */
    public static function blocksFor(mysqli $con, int $userId, int $limit): array
    {
        return self::typedRows($con, "SELECT reason, blocked_at FROM blocks WHERE user_id = ? ORDER BY block_id DESC LIMIT ?",
            'ii', [$userId, $limit]);
    }

    // ── Restrictions ────────────────────────────────────────────────────────

    /**
     * Records a restriction that starts today and whose last restricted day is
     * $lastDay ('Y-m-d'). $by is the admin who applied it.
     */
    public static function recordRestriction(mysqli $con, int $userId, string $reason, int $by, string $lastDay): void
    {
        self::execute($con, "
            INSERT INTO restrictions (user_id, reason, restricted_by, restricted_at, start_date, end_date)
            VALUES (?, ?, ?, NOW(), CURDATE(), ?)
        ", 'isis', [$userId, $reason, $by, $lastDay]);
    }

    /** The account's newest $limit restrictions, newest first, with who applied each ('by_name'). */
    public static function restrictionsFor(mysqli $con, int $userId, int $limit): array
    {
        return self::typedRows($con, "
            SELECT reason, restricted_at, start_date, end_date,
                   CONCAT_WS(' ', a.firstname, a.lastname) AS by_name
            FROM restrictions x LEFT JOIN users a ON a.user_id = x.restricted_by
            WHERE x.user_id = ? ORDER BY x.restriction_id DESC LIMIT ?
        ", 'ii', [$userId, $limit]);
    }

    /**
     * The last restricted day of the account's newest restriction, when that
     * day is today or later; null when it has none or the newest has run out.
     */
    public static function runningRestrictionEnd(mysqli $con, int $userId): ?string
    {
        return self::value($con, "
            SELECT IF(end_date >= CURDATE(), end_date, NULL)
            FROM restrictions WHERE user_id = ?
            ORDER BY restriction_id DESC LIMIT 1
        ", 'i', [$userId]);
    }

    /**
     * Accounts still marked restricted although their newest restriction has
     * run out (or they have none): the ones due to be lifted.
     */
    public static function restrictedAccountsToLift(mysqli $con): array
    {
        $rows = self::rows($con, "
            SELECT u.user_id FROM users u
            WHERE u.status = 'restricted'
              AND COALESCE((SELECT x.end_date >= CURDATE() FROM restrictions x
                             WHERE x.user_id = u.user_id
                             ORDER BY x.restriction_id DESC LIMIT 1), 0) = 0
            ORDER BY u.user_id
        ");
        return array_map('intval', array_column($rows, 'user_id'));
    }

    // ── Reports ─────────────────────────────────────────────────────────────

    /** Files a report. $proof is the stored proof image's name, or null. Returns the report's id. */
    public static function fileReport(mysqli $con, int $reportedUserId, int $reportedBy, string $issueType, string $description, ?string $proof): int
    {
        return self::insert($con, "
            INSERT INTO reports (reported_user_id, reported_by, issue_type, description, proof, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ", 'iisss', [$reportedUserId, $reportedBy, $issueType, $description, $proof]);
    }

    /**
     * Whether some report's proof is the file $name. Older rows store a path
     * ('uploads/reports/<name>'), newer ones the name alone.
     */
    public static function proofIsKnown(mysqli $con, string $name): bool
    {
        return self::value($con, "SELECT 1 FROM reports WHERE proof = ? OR proof = CONCAT('uploads/reports/', ?) LIMIT 1",
            'ss', [$name, $name]) !== null;
    }

    /** 'report_id', 'reported_user_id' and 'status' of one report, or null when there is no such report. */
    public static function report(mysqli $con, int $reportId): ?array
    {
        return self::typedRow($con, "SELECT report_id, reported_user_id, status FROM reports WHERE report_id = ?", 'i', [$reportId]);
    }

    /** Marks the report resolved. Returns 1 when it changed, 0 when it was already resolved. */
    public static function resolveReport(mysqli $con, int $reportId): int
    {
        return self::execute($con, "
            UPDATE reports SET status = 'resolved', updated_at = NOW()
            WHERE report_id = ? AND (status IS NULL OR status <> 'resolved')
        ", 'i', [$reportId]);
    }

    /**
     * The open reports (pending or urgent), newest first, for the Reported
     * queue — with the reported account's name, role, status and photo, and
     * 'reported_deleted' set when its owner deleted it.
     */
    public static function openReports(mysqli $con): array
    {
        return self::rows($con, "
            SELECT r.*,
                   CONCAT(ru.firstname,' ',ru.lastname) AS reported_name, ru.role AS reported_role,
                   ru.status AS reported_status, rp.profile_image,
                   (ru.status = 'blocked' AND ru.email IS NULL) AS reported_deleted,
                   CONCAT(bu.firstname,' ',bu.lastname) AS reporter_name
            FROM reports r
            JOIN users ru        ON ru.user_id = r.reported_user_id
            LEFT JOIN users bu   ON bu.user_id = r.reported_by
            LEFT JOIN profile rp ON rp.user_id = r.reported_user_id
            WHERE r.status IN ('pending','urgent')
            ORDER BY r.created_at DESC
        ");
    }

    /** The newest $limit reports filed against the account, with who filed each ('reporter'). */
    public static function reportsAgainst(mysqli $con, int $userId, int $limit): array
    {
        return self::typedRows($con, "
            SELECT r.report_id, r.issue_type, r.description, r.proof, r.status, r.created_at,
                   CONCAT_WS(' ', w.firstname, w.lastname) AS reporter
            FROM reports r LEFT JOIN users w ON w.user_id = r.reported_by
            WHERE r.reported_user_id = ? ORDER BY r.created_at DESC LIMIT ?
        ", 'ii', [$userId, $limit]);
    }

    /** How many reports are open against the account ('open'), filed against it ('against') and filed by it ('filed'). */
    public static function reportFigures(mysqli $con, int $userId): array
    {
        $row = self::row($con, "
            SELECT (SELECT COUNT(*) FROM reports WHERE reported_user_id = ? AND status IN ('pending','urgent')) AS open,
                   (SELECT COUNT(*) FROM reports WHERE reported_user_id = ?) AS against,
                   (SELECT COUNT(*) FROM reports WHERE reported_by = ?) AS filed
        ", 'iii', [$userId, $userId, $userId]);
        return array_map('intval', $row);
    }

    // ── The admin Notifications page ────────────────────────────────────────

    /**
     * Reports for the admin's list, open ones (urgent, then pending) before
     * resolved, newest first within each, at most $limit: the report with
     * both people's names, roles and photos.
     */
    public static function reportsForInbox(mysqli $con, int $limit): array
    {
        return self::typedRows($con, "
            SELECT r.report_id, r.issue_type, r.description, r.status, r.created_at, r.updated_at,
                   r.reported_user_id, r.reported_by,
                   TRIM(CONCAT_WS(' ', ru.firstname, ru.lastname)) AS reported_name, ru.role AS reported_role,
                   TRIM(CONCAT_WS(' ', bu.firstname, bu.lastname)) AS reporter_name, bu.role AS reporter_role
            FROM reports r
            JOIN users ru      ON ru.user_id = r.reported_user_id
            LEFT JOIN users bu ON bu.user_id = r.reported_by
            ORDER BY FIELD(r.status, 'urgent', 'pending') = 0, FIELD(r.status, 'urgent', 'pending'), r.created_at DESC
            LIMIT ?
        ", 'i', [$limit]);
    }

    /**
     * One report with everything the admin's detail panel shows: both
     * people's names, roles, statuses, photos and whether each account was
     * deleted by its owner ('reported_deleted', 'reporter_deleted').
     */
    public static function reportDetail(mysqli $con, int $reportId): ?array
    {
        return self::typedRow($con, "
            SELECT r.report_id, r.issue_type, r.description, r.proof, r.status, r.created_at, r.updated_at,
                   r.reported_user_id, r.reported_by,
                   TRIM(CONCAT_WS(' ', ru.firstname, ru.lastname)) AS reported_name, ru.role AS reported_role,
                   ru.status AS reported_status, rp.profile_image AS reported_photo,
                   (ru.status = 'blocked' AND ru.email IS NULL) AS reported_deleted,
                   TRIM(CONCAT_WS(' ', bu.firstname, bu.lastname)) AS reporter_name, bu.role AS reporter_role,
                   bu.status AS reporter_status, bp.profile_image AS reporter_photo,
                   (bu.status = 'blocked' AND bu.email IS NULL) AS reporter_deleted
            FROM reports r
            JOIN users ru         ON ru.user_id = r.reported_user_id
            LEFT JOIN profile rp  ON rp.user_id = r.reported_user_id
            LEFT JOIN users bu    ON bu.user_id = r.reported_by
            LEFT JOIN profile bp  ON bp.user_id = r.reported_by
            WHERE r.report_id = ?
        ", 'i', [$reportId]);
    }

    /** How many reports are open (pending or urgent). */
    public static function countOpenReports(mysqli $con): int
    {
        return (int)self::value($con, "SELECT COUNT(*) FROM reports WHERE status IN ('pending','urgent')");
    }
}
