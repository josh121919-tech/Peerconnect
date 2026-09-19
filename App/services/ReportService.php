<?php

/**
 * ReportService — reports members file against each other: the issue types
 * the form offers, and the proof image a reporter may attach.
 *
 * Proof images are evidence (screenshots of chats, say), so they are kept in
 * storage/reports, which the web server refuses to serve, and reach a browser
 * only through the report-proof route, which lets admins through and nobody
 * else. They used to be saved in public/uploads/reports under the extension
 * the uploader's own file name carried, where no admin screen ever showed
 * them and anyone with the address could open them.
 */
class ReportService
{
    /** The issue types the report form offers, by the code it sends. */
    public const ISSUE_TYPES = [
        'no_show'                 => 'No show',
        'communication'           => 'Communication',
        'session_quality'         => 'Session quality',
        'unprofessional_behavior' => 'Unprofessional behavior',
        'scheduling_issue'        => 'Scheduling issue',
        'mismatch'                => 'Mismatch',
        'technical_issue'         => 'Technical issue',
        'policy_violation'        => 'Policy violation',
    ];

    public const DESCRIPTION_MAX = 2000;
    public const PROOF_MAX_BYTES = 5 * 1024 * 1024;

    /** At most this many reports per member in REPORT_WINDOW seconds. */
    public const REPORT_LIMIT  = 5;
    public const REPORT_WINDOW = 600;

    /** The image types a proof may be, and the extension each is stored under. */
    private const PROOF_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /**
     * An issue type as people read it. Reports filed before the form sent
     * codes, or by some other path, hold free text, which is shown as it is.
     */
    public static function label(?string $issueType): string
    {
        $issueType = trim((string)$issueType);
        if ($issueType === '') {
            return 'Report';
        }
        return self::ISSUE_TYPES[$issueType] ?? ucfirst(str_replace('_', ' ', $issueType));
    }

    // ── Notices about a report (the admin Notifications page) ───────────────

    /**
     * The ready-made notices, by key: who each goes to ('to' is 'reported' or
     * 'reporter'), its label in the admin's picker, and its notification type.
     * The wording is fixed here, not typed by the admin, so the notice to the
     * reported member can only ever name the category: never the reporter,
     * never what they wrote. Neither notice to the reporter says what was
     * done about the other person.
     */
    public const NOTICES = [
        'report_received'  => ['to' => 'reported', 'label' => 'Report received (to the reported member)',        'type' => 'report_notice'],
        'report_reviewing' => ['to' => 'reporter', 'label' => 'We are reviewing your report (to the reporter)',  'type' => 'report_update'],
        'report_closed'    => ['to' => 'reporter', 'label' => 'Your report was reviewed (to the reporter)',      'type' => 'report_update'],
    ];

    /** A notice's title and message for a report ('issue_type' and 'created_at'). */
    public static function noticeText(string $notice, array $report): array
    {
        $issue = self::label($report['issue_type'] ?? '');
        $sent  = date('F j', strtotime((string)($report['created_at'] ?? 'now')));
        return match ($notice) {
            'report_received' => [
                'Report received',
                'We received a report about your account regarding ' . $issue
                    . '. We will review it before taking any action. You don\'t need to do anything right now.',
            ],
            'report_reviewing' => [
                'We received your report',
                'Thank you for your report (' . $issue . ', sent ' . $sent . '). We are reviewing it and will act on it if needed.',
            ],
            'report_closed' => [
                'Your report was reviewed',
                'We have reviewed your report (' . $issue . ', sent ' . $sent . ') and closed it. Thank you for helping keep PeerConnect safe.',
            ],
        };
    }

    /** Where proof images live now. */
    public static function proofDir(): string
    {
        return BASE_PATH . '/storage/reports';
    }

    /** Where they used to live, publicly. Read only, for older reports. */
    public static function legacyProofDir(): string
    {
        return PUBLIC_PATH . '/uploads/reports';
    }

    /**
     * Looks at an uploaded proof without saving it. Returns
     * ['present' => false] when none was chosen, otherwise 'present' => true
     * with either 'error' or 'ext': the extension that matches what the file
     * really is, whatever its name says.
     */
    public static function inspectProof($file): array
    {
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || $file['tmp_name'] === ''
            || !is_int($file['error'] ?? null) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['present' => false];
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            || (int)($file['size'] ?? 0) > self::PROOF_MAX_BYTES) {
            return ['present' => true, 'error' => 'Image must be under 5 MB.'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return ['present' => true, 'error' => 'Failed to save image.'];
        }
        $ext = self::PROOF_TYPES[mime_content_type($file['tmp_name'])] ?? null;
        if ($ext === null) {
            return ['present' => true, 'error' => 'Only JPEG, PNG, WEBP, or GIF images are allowed.'];
        }
        return ['present' => true, 'ext' => $ext];
    }

    /** Saves a proof inspectProof() passed, under a name nobody can guess. Returns the name, or null. */
    public static function storeProof(array $file, string $ext): ?string
    {
        $dir = self::proofDir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        $name = 'proof_' . bin2hex(random_bytes(16)) . '.' . $ext;
        return move_uploaded_file($file['tmp_name'], $dir . '/' . $name) ? $name : null;
    }

    /**
     * The bare file name a report's proof column refers to. Older rows hold
     * a path ('uploads/reports/proof_….jpg'); newer ones the name alone.
     */
    public static function proofName(?string $stored): ?string
    {
        $name = basename(str_replace('\\', '/', trim((string)$stored)));
        return preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,199}$/', $name) === 1 ? $name : null;
    }

    /** The file on disk for a proof name, or null when there is none. */
    public static function proofPath(?string $name): ?string
    {
        $name = self::proofName($name);
        if ($name === null) {
            return null;
        }
        foreach ([self::proofDir(), self::legacyProofDir()] as $dir) {
            if (is_file($dir . '/' . $name)) {
                return $dir . '/' . $name;
            }
        }
        return null;
    }

    /** The content type a stored proof is sent with, or null when it is not an accepted image. */
    public static function proofContentType(string $path): ?string
    {
        $mime = mime_content_type($path);
        return isset(self::PROOF_TYPES[$mime]) ? $mime : null;
    }

    /** The address an admin opens a report's proof at, or '' when the report has none. */
    public static function proofUrl(?string $stored): string
    {
        $name = self::proofName($stored);
        return $name === null ? '' : url('report-proof') . '?f=' . rawurlencode($name);
    }
}
