<?php

/**
 * WorksheetTemplate — the .docx a mentor can start from.
 *
 * Reading questions out of an arbitrary document is guesswork, and every
 * worksheet invents its own conventions: numbered "1." or "1:" or not at all,
 * options one to a line or five across one, answers beside each question or
 * kept together at the back. QuestionImportService is tolerant of a good deal
 * of that, but tolerance has a ceiling and each new layout is another guess.
 *
 * This removes the guessing for anyone who wants it removed. A mentor
 * downloads the template, types over the examples, and uploads it; the layout
 * is then exactly the one the importer reads best. The tolerant parser stays
 * where it is for everyone who does not.
 *
 * WHY THE EXAMPLES ARE REAL QUESTIONS
 * Uploading this file unmodified imports four questions — one of each kind —
 * rather than failing or producing nonsense. That makes the template its own
 * demonstration, and it makes the promise testable: the suite downloads this
 * file, runs it through the importer, and checks the four come back. Template
 * and parser cannot drift apart without that failing.
 *
 * WHY THE PROSE DOES NOT BECOME QUESTIONS
 * Every note sits after a blank paragraph. A blank line ends whatever the
 * parser was collecting, and an unlabelled line that follows one belongs to
 * nothing, so it is dropped. The notes are written to stay clear of the labels
 * as well: nothing here begins a line with Answer, Given, Formula or a number.
 */
class WorksheetTemplate
{
    /** What the browser saves it as. */
    public const FILENAME = 'PeerConnect worksheet template.docx';

    public const MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /**
     * The document, one paragraph per entry. An empty string is a blank
     * paragraph, and those are load-bearing — see the note above.
     */
    public static function paragraphs(): array
    {
        return [
            'PeerConnect — worksheet template',
            '',
            'Type your questions over the examples below, then delete these notes. Save as .docx '
                . 'and upload it with Import from a file. Nothing is saved until you have read the '
                . 'preview and pressed Save, so there is no harm in trying it.',
            '',
            'MULTIPLE CHOICE',
            '',
            '1. What is 7 x 8?',
            'A) 54',
            'B) 56 *',
            'C) 48',
            'D) 64',
            '',
            'The star marks the right one. A line reading Answer: B does the same, and so does '
                . 'Answer: 56. Options may also run along a single line as A) 54 B) 56 C) 48.',
            '',
            'TRUE OR FALSE',
            '',
            '2. Twelve squared is 144.',
            'Answer: True',
            '',
            'Write the word True or the word False. There is no need to list the two options.',
            '',
            'SHORT ANSWER',
            '',
            '3. Solve 2x + 5 = 17',
            'Answer: x = 6',
            '',
            'A question with no options becomes a short answer, and the mentee has to type '
                . 'something matching that line.',
            '',
            'WITH THE WORKING SHOWN',
            '',
            '4. An arithmetic sequence starts at 7 and rises by 3. Find the 25th term.',
            'Given: a_1 = 7, d = 3, n = 25',
            'Formula: a_n = a_1 + (n-1)d',
            'Solution: a_25 = 7 + (25-1)(3) = 79',
            'Answer: 79',
            'Points: 2',
            '',
            'Given and Formula are shown to the mentee while they are still answering. Solution is '
                . 'held back until they have submitted. Points is what the question is worth — leave '
                . 'it out and it is worth 1. A line beginning Hint: adds a hint of your own wording.',
            '',
            'NOTES',
            '',
            'Start every question on its own line, numbered 1. or 1) or 1:',
            'Leave out anything you do not need. A blank line between questions is welcome but not required.',
            'Equations written with the equation editor in Word are read, so algebra is safe here.',
            'Photographs and scans cannot be read — there is no text in a picture to import.',
            'To keep the answers together at the back instead, give them a heading that reads '
                . 'Answer Key and write the entries as 1. B and 2. True, one or several to a line. '
                . 'Do not restart the numbering in each section if you do that, or they cannot be '
                . 'matched back.',
        ];
    }

    /**
     * The template as .docx bytes.
     *
     * Assembled here rather than kept as a file in the repository so it cannot
     * fall out of step with paragraphs() above, and written by hand rather
     * than with a library because a WordprocessingML package that holds only
     * text is three small XML parts and needs no dependency.
     */
    public static function docx(): string
    {
        $body = '';
        foreach (self::paragraphs() as $text) {
            $body .= $text === ''
                ? '<w:p/>'
                : '<w:p><w:r><w:t xml:space="preserve">'
                    . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                    . '</w:t></w:r></w:p>';
        }

        $parts = [
            '[Content_Types].xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                // Word refuses the package without this: the Default above says
                // only that .xml parts are XML, not which one is the document.
                . '<Override PartName="/word/document.xml"'
                . ' ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '</Types>',

            '_rels/.rels' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
                . ' Target="word/document.xml"/>'
                . '</Relationships>',

            'word/document.xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:body>' . $body
                . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
                . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr>'
                . '</w:body></w:document>',
        ];

        // ZipArchive writes to a path, so the package is built in the system
        // temp directory and read back. Nothing servable is created.
        $tmp = tempnam(sys_get_temp_dir(), 'pcwt');
        if ($tmp === false) {
            throw new \RuntimeException('no temporary file for the template');
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('could not open the template package');
            }
            foreach ($parts as $name => $xml) {
                $zip->addFromString($name, $xml);
            }
            $zip->close();

            $bytes = file_get_contents($tmp);
            if ($bytes === false) {
                throw new \RuntimeException('could not read the template back');
            }
            return $bytes;
        } finally {
            @unlink($tmp);
        }
    }
}
