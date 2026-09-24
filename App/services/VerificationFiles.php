<?php

/**
 * VerificationFiles — the student ID and registration documents members
 * upload when they apply to be verified.
 *
 * They are identity documents, so they are kept in storage/verification,
 * which the web server refuses to serve (see the root .htaccess and
 * storage/.htaccess), and reach a browser only through the
 * verification-file route, which lets through the document's owner and
 * admins. They used to sit in public/uploads/verification, where anyone with
 * the address could download them, under names built from the upload time.
 * scripts/move_verification_files.php moves files left in the old folder.
 *
 * A file is checked, then stored only once the whole form is valid, and a
 * file an application no longer points at is deleted.
 */
class VerificationFiles
{
    /**
     * What an application document may be. The name's extension and the
     * file's actual content are each checked against their list, but not
     * against each other, as the forms always did: a PNG saved with a .jpg
     * name is still accepted, and is sent back as the PNG it is.
     */
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    private const MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

    public const MAX_BYTES = 3 * 1024 * 1024;

    /** Where the documents live now. */
    public static function dir(): string
    {
        return BASE_PATH . '/storage/verification';
    }

    /** Where they used to live, publicly. Read only, for files not yet moved. */
    public static function legacyDir(): string
    {
        return PUBLIC_PATH . '/uploads/verification';
    }

    /**
     * Looks at one uploaded document without saving it.
     *
     * Returns ['present' => false] when nothing was chosen; otherwise
     * 'present' => true with either 'error' (a message naming $label) or
     * 'ext' (the lower-cased extension to store it under). An upload the
     * browser sent as something other than a single file counts as nothing
     * chosen.
     */
    /**
     * $imagesOnly turns a PDF away.
     *
     * Members may send either: a registrar hands out a Certificate of
     * Registration as a PDF and refusing it would send them to find a
     * scanner. The administrator form asks for photographs, which show as
     * thumbnails in the review — a PDF there is a document the owner has to
     * open in another tab to decide on.
     */
    public static function inspect($file, string $label, bool $imagesOnly = false): array
    {
        if (!is_array($file) || !is_string($file['name'] ?? null) || $file['name'] === ''
            || !is_int($file['error'] ?? null) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['present' => false];
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return ['present' => true, 'error' => "$label must be less than 3MB."];
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            return ['present' => true, 'error' => "$label could not be uploaded. Please choose the file again."];
        }

        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = mime_content_type($file['tmp_name']);
        if ((int)($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['present' => true, 'error' => "$label must be less than 3MB."];
        }
        if (!in_array($ext, self::EXTENSIONS, true) || !in_array($mime, self::MIME_TYPES, true)) {
            return ['present' => true, 'error' => "$label must be a JPG, PNG, GIF, WEBP, or PDF file."];
        }
        if ($imagesOnly && ($ext === 'pdf' || $mime === 'application/pdf')) {
            return ['present' => true, 'error' => "$label must be a photo — JPG, PNG, GIF or WEBP, not a PDF."];
        }
        return ['present' => true, 'ext' => $ext];
    }

    /**
     * Saves an uploaded document that inspect() passed, under a name nobody
     * can guess. Returns the name, or null when it could not be saved.
     */
    public static function store(array $file, string $prefix, string $ext): ?string
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        $name = $prefix . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
        return move_uploaded_file($file['tmp_name'], $dir . '/' . $name) ? $name : null;
    }

    /** Deletes a stored document. Anything that is not a plain document name is ignored. */
    public static function remove(?string $name): void
    {
        foreach ([self::dir(), self::legacyDir()] as $dir) {
            $path = self::safeName($name) ? $dir . '/' . $name : null;
            if ($path && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** The file on disk for a document name, or null when there is none. */
    public static function path(?string $name): ?string
    {
        if (!self::safeName($name)) {
            return null;
        }
        foreach ([self::dir(), self::legacyDir()] as $dir) {
            if (is_file($dir . '/' . $name)) {
                return $dir . '/' . $name;
            }
        }
        return null;
    }

    /**
     * The content type to send a stored document with, read from the file
     * itself, or null when it is not one of the types an application accepts.
     */
    public static function contentType(string $path): ?string
    {
        $mime = mime_content_type($path);
        return in_array($mime, self::MIME_TYPES, true) ? $mime : null;
    }

    /** The address a signed-in owner or admin opens a document at. */
    public static function url(string $name): string
    {
        return url('verification-file') . '?f=' . rawurlencode($name);
    }

    /** A bare file name: letters, digits, dot, dash and underscore, not starting with a dot. */
    private static function safeName(?string $name): bool
    {
        return is_string($name) && preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,199}$/', $name) === 1;
    }
}
