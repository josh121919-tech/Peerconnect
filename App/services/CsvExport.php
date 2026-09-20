<?php

/**
 * CsvExport — writes rows of the admin CSV exports.
 *
 * Excel and Sheets run a cell as a formula when its text starts with =, +,
 * - or @ (a tab or carriage return can smuggle one in too). The exports
 * carry text members typed: names, subjects, titles, what they did. A subject
 * like =HYPERLINK("http://…","Open") would arrive as a live link, and worse
 * is possible, so such a cell is written with a leading apostrophe, which
 * the spreadsheet shows as plain text. Numbers, negative ones included, are
 * written as they are.
 */
class CsvExport
{
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /** Writes one row to $handle, each cell made safe to open in a spreadsheet. */
    public static function row($handle, array $fields): void
    {
        fputcsv($handle, array_map([self::class, 'cell'], $fields));
    }

    /** One cell's value, with a leading apostrophe when a spreadsheet would read it as a formula. */
    public static function cell($value)
    {
        if (!is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }
        return in_array($value[0], self::FORMULA_STARTS, true) ? "'" . $value : $value;
    }
}
