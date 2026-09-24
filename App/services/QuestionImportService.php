<?php

/**
 * QuestionImportService — turns an uploaded worksheet into draft questions.
 *
 * Two jobs, kept apart because they fail for different reasons: pulling the
 * text out of a file, and working out which parts of that text are questions.
 *
 * WHAT IT CANNOT DO
 * Images. There is no OCR on this stack and none available on shared hosting,
 * so a photographed or scanned worksheet has no text to read — and a PDF that
 * is a picture of a page is exactly that case. read() says so plainly rather
 * than returning nothing and letting the caller imagine the file was empty.
 *
 * WHY THE PARSE IS A DRAFT AND NOT AN ANSWER
 * Deciding which line is a question, which are options and which is the answer
 * cannot be done reliably for arbitrary worksheets. This recognises a layout
 * that is written down and ordinary, reports what it could not make sense of,
 * and hands everything to the mentor to correct before anything is saved.
 * Publishing a misread question to students is far worse than asking someone
 * to glance at it.
 *
 * THE LAYOUT IT READS
 *
 *     1. What is 7 x 8?
 *     A) 54
 *     B) 56 *
 *     C) 48
 *     Answer: B
 *     Solution: 7 x 8 is 56, because...
 *     Points: 2
 *
 * A question opens with "1.", "1)" or "1:". Options open with "A)" or "A.".
 * The correct one is marked either by a trailing asterisk or by an Answer line
 * naming its letter or repeating its text. Answer, Solution, Hint, Given,
 * Formula and Points are optional and may appear in any order. A question with
 * no options is a short answer, and its Answer line is what the mentee has to
 * match.
 *
 * Given and Formula join the hint rather than the solution. They are what a
 * mentee needs while they are still working, and the worked solution is held
 * back until they have submitted; putting the formula in with the working
 * would mean handing over both or neither.
 *
 * Options may also be written along one line — "A. 2 B. 3 C. 4 D. 5" — which
 * is split back apart, but only when the letters run A, B, C... in order from
 * A with no gaps, so an ordinary sentence is not torn up by the odd initial in
 * it. Two markers are enough, which is deliberate: a two-option line is
 * usually True and False, and leaving one unsplit buries "True B. False" in
 * the middle of an option list where it is easy to miss, while a wrong split
 * shows up as an obvious extra option the mentor can delete before saving.
 *
 * A worksheet may instead keep its answers together at the back, under an
 * "Answer Key" heading, as "1. C. 4" or "6. True", several to a line. Nothing
 * past that heading is a question: the entries are read as a key and matched
 * back by the worksheet's own numbering, filling in only the answers a
 * question did not already carry. A question whose answer turns out to be the
 * word True or the word False becomes a True/False question, since a paper
 * that says "True or False" at the top of a section rarely says it again
 * under every question.
 *
 * Questions whose text repeats exactly are imported once — some PDF exports
 * draw the whole document twice, and that is not ten questions turning into
 * twenty.
 */
class QuestionImportService
{
    /** Anything larger is not a worksheet, and parsing it would hold a request open. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Past this, the file is being used for something other than an assessment. */
    public const MAX_QUESTIONS = 100;

    /** Extensions this can read at all. Images are absent on purpose — see above. */
    public const ACCEPTS = ['docx', 'pdf'];

    /** Ordinary Word text. */
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** Word equations, which are a separate vocabulary in a separate namespace. */
    private const NS_M = 'http://schemas.openxmlformats.org/officeDocument/2006/math';

    /**
     * The plain text of an uploaded file.
     *
     * @return array{ok:bool, text:string, error:string}
     */
    public static function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ACCEPTS, true)) {
            return self::fail('Only Word (.docx) and PDF files can be read. A photograph or a scan has no text in it to import.');
        }
        // Two different failures, and they had one message between them: a
        // file that was not there reported itself as too large, which sends
        // whoever reads it looking for a size problem that does not exist.
        if (!is_file($path)) {
            return self::fail('That file could not be found.');
        }
        if (filesize($path) > self::MAX_BYTES) {
            return self::fail('That file is too large to read. The limit is ' . (self::MAX_BYTES / 1048576) . ' MB.');
        }

        try {
            $text = self::utf8($ext === 'docx' ? self::readDocx($path) : self::readPdf($path));
        } catch (\Throwable $e) {
            error_log('QuestionImportService: ' . $e->getMessage());
            return self::fail('That file could not be opened. It may be damaged, or password protected.');
        }

        if (trim($text) === '') {
            return self::fail($ext === 'pdf'
                ? 'No text could be found in that PDF. If it is a scan or a photograph of a page, it is an image as far as the computer is concerned — retype it, or save it from Word as a .docx.'
                : 'That document appears to be empty.');
        }
        return ['ok' => true, 'text' => $text, 'error' => ''];
    }

    /**
     * A .docx is a zip of XML, and the text lives in word/document.xml. Read
     * with the zip and dom extensions rather than a library: both are already
     * loaded, and this is the whole of what the format requires for text.
     */
    private static function readDocx(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('not a readable zip');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new \RuntimeException('no word/document.xml — not a Word document');
        }

        $doc = new \DOMDocument();
        // Word writes namespaces and the odd malformed run; warnings here are
        // noise, and a genuinely broken file is caught by the empty check.
        $prev = libxml_use_internal_errors(true);
        $doc->loadXML($xml, LIBXML_NOENT | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('w', self::NS_W);
        $xp->registerNamespace('m', self::NS_M);

        $lines = [];
        foreach ($xp->query('//w:p') as $p) {
            $line = '';
            /*
             * An equation is not w:t and never was. Word writes it as OMML in
             * its own namespace, so a reader that asks only for w:t gets the
             * words around the maths and nothing of the maths itself: "What is
             * the value of ?", "Given: Principal ()", a Formula line with
             * nothing after it, a Solution that is entirely blank. On a maths
             * worksheet that is most of the paper. m:oMath is taken whole and
             * turned back into something readable by omml().
             */
            foreach ($xp->query('.//w:t | .//w:tab | .//w:br | .//m:oMath', $p) as $node) {
                if ($node->namespaceURI === self::NS_M) {
                    $line .= self::omml($node);
                } elseif ($node->localName === 't') {
                    $line .= $node->textContent;
                } else {
                    $line .= ' ';
                }
            }
            // A paragraph is a line, including the empty ones: they are what
            // separates one question from the next in most worksheets.
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * An OMML equation as plain text.
     *
     * Not a renderer and not trying to be. The job is a line a mentor can read
     * and edit in a plain textarea, so the structure that changes the meaning
     * is kept — a subscript becomes "_n", a fraction becomes "a/b", brackets
     * stay brackets — and the rest is dropped. Anything unrecognised is
     * recursed into rather than skipped, so a construct not handled here still
     * gives up its text instead of vanishing the way the whole equation used
     * to.
     */
    private static function omml(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            switch ($child->localName) {
                case 't':
                    $out .= $child->textContent;
                    break;
                case 'sSub':
                    $out .= self::ommlPart($child, 'e') . '_' . self::group(self::ommlPart($child, 'sub'));
                    break;
                case 'sSup':
                    $out .= self::ommlPart($child, 'e') . '^' . self::group(self::ommlPart($child, 'sup'));
                    break;
                case 'sSubSup':
                    $out .= self::ommlPart($child, 'e')
                        . '_' . self::group(self::ommlPart($child, 'sub'))
                        . '^' . self::group(self::ommlPart($child, 'sup'));
                    break;
                case 'f':
                    $out .= self::group(self::ommlPart($child, 'num'))
                        . '/' . self::group(self::ommlPart($child, 'den'));
                    break;
                case 'rad':
                    $out .= 'sqrt(' . self::ommlPart($child, 'e') . ')';
                    break;
                case 'd':
                    // A delimiter says which brackets it wants, or means round ones.
                    $open  = self::ommlChar($child, 'begChr', '(');
                    $close = self::ommlChar($child, 'endChr', ')');
                    $out  .= $open . self::ommlPart($child, 'e') . $close;
                    break;
                // Formatting only: no text of its own, and recursing into it
                // would pull in the font names.
                case 'ctrlPr':
                case 'rPr':
                case 'dPr':
                case 'fPr':
                case 'sSubPr':
                case 'sSupPr':
                case 'sSubSupPr':
                case 'radPr':
                case 'naryPr':
                case 'funcPr':
                case 'argPr':
                    break;
                default:
                    $out .= self::omml($child);
            }
        }
        return $out;
    }

    /** The named parts of an OMML element, in order. */
    private static function ommlPart(\DOMElement $node, string $part): string
    {
        $found = [];
        foreach ($node->childNodes as $c) {
            if ($c instanceof \DOMElement && $c->localName === $part && $c->namespaceURI === self::NS_M) {
                $found[] = self::omml($c);
            }
        }
        return implode(', ', $found);
    }

    /** A delimiter's own bracket character, where it names one. */
    private static function ommlChar(\DOMElement $node, string $which, string $fallback): string
    {
        foreach ($node->getElementsByTagNameNS(self::NS_M, $which) as $el) {
            $v = $el->getAttributeNS(self::NS_M, 'val');
            return $v === '' ? '' : $v;
        }
        return $fallback;
    }

    /** Brackets, but only where leaving them off would change the reading. */
    private static function group(string $s): string
    {
        return preg_match('/[+\-×÷*\/\s,]/u', $s) ? '(' . $s . ')' : $s;
    }

    /** Text-bearing PDFs only. A scan has no text layer and returns ''. */
    private static function readPdf(string $path): string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new \RuntimeException('smalot/pdfparser is not installed');
        }
        $parser = new \Smalot\PdfParser\Parser();
        return $parser->parseFile($path)->getText();
    }

    /**
     * Text that PCRE will accept, with equation glyphs folded to plain letters.
     *
     * Two things go wrong with the text that comes out of a PDF.
     *
     * The first is fatal and silent. Word writes an equation in Mathematical
     * Alphanumeric Symbols, which live above the BMP, and some PDF producers
     * store those as a UTF-16 surrogate pair written out byte for byte — six
     * bytes that are not valid UTF-8. Every pattern in this class carries /u,
     * and PCRE refuses a malformed subject outright: preg_split returns false,
     * the file reads as having no lines in it at all, and the mentor is told
     * their numbering is wrong when it never was. So surrogate pairs are put
     * back together, and anything still malformed is dropped.
     *
     * The second is merely unreadable. Those symbols are styled duplicates of
     * ASCII — U+1D44E is an italic "a" and nothing more — and in a plain
     * textarea they are at best italic and at worst empty boxes, in any font
     * without a glyph for them. Folding them back to the letters they stand
     * for costs the italics and keeps the algebra legible and editable. Greek
     * and the operators are left alone: those carry meaning of their own.
     */
    private static function utf8(string $text): string
    {
        // CESU-8: a surrogate pair as two three-byte sequences. Matched as
        // bytes, so no /u here — this subject is exactly what /u rejects.
        $text = preg_replace_callback(
            '/\xED[\xA0-\xAF][\x80-\xBF]\xED[\xB0-\xBF][\x80-\xBF]/',
            static function (array $m): string {
                $hi = ((ord($m[0][0]) & 0x0F) << 12) | ((ord($m[0][1]) & 0x3F) << 6) | (ord($m[0][2]) & 0x3F);
                $lo = ((ord($m[0][3]) & 0x0F) << 12) | ((ord($m[0][4]) & 0x3F) << 6) | (ord($m[0][5]) & 0x3F);
                return mb_chr(0x10000 + (($hi - 0xD800) << 10) + ($lo - 0xDC00), 'UTF-8');
            },
            $text
        ) ?? $text;

        // Whatever is still malformed goes, rather than standing as question
        // marks through the middle of somebody's algebra.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $was = mb_substitute_character();
            mb_substitute_character('none');
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            mb_substitute_character($was);
        }

        return preg_replace_callback(
            '/[\x{1D400}-\x{1D7FF}\x{2102}\x{210A}-\x{2112}\x{211B}-\x{211D}\x{2124}\x{2128}\x{212C}\x{212D}\x{212F}-\x{2131}\x{2133}\x{2134}]/u',
            static fn(array $m): string => self::plainLetter($m[0]),
            $text
        ) ?? $text;
    }

    /** The ASCII letter or digit a mathematical symbol is a styled copy of. */
    private static function plainLetter(string $ch): string
    {
        // Each alphabet is 52 code points, A-Z then a-z, and each digit run is
        // ten. A handful of letters were encoded years earlier among the
        // Letterlike Symbols and are holes in those runs, so they are listed.
        static $holes = [
            0x2102 => 'C', 0x210A => 'g', 0x210B => 'H', 0x210C => 'H', 0x210D => 'H',
            0x210E => 'h', 0x210F => 'h', 0x2110 => 'I', 0x2111 => 'I', 0x2112 => 'L',
            0x211B => 'R', 0x211C => 'R', 0x211D => 'R', 0x2124 => 'Z', 0x2128 => 'Z',
            0x212C => 'B', 0x212D => 'C', 0x212F => 'e', 0x2130 => 'E', 0x2131 => 'F',
            0x2133 => 'M', 0x2134 => 'o',
        ];
        static $letters = [
            0x1D400, 0x1D434, 0x1D468, 0x1D49C, 0x1D4D0, 0x1D504, 0x1D538,
            0x1D56C, 0x1D5A0, 0x1D5D4, 0x1D608, 0x1D63C, 0x1D670,
        ];
        static $digits = [0x1D7CE, 0x1D7D8, 0x1D7E2, 0x1D7EC, 0x1D7F6];

        $cp = mb_ord($ch, 'UTF-8');
        if ($cp === false) {
            return $ch;
        }
        if (isset($holes[$cp])) {
            return $holes[$cp];
        }
        foreach ($letters as $base) {
            if ($cp >= $base && $cp <= $base + 51) {
                $n = $cp - $base;
                return chr($n < 26 ? 65 + $n : 97 + $n - 26);
            }
        }
        foreach ($digits as $base) {
            if ($cp >= $base && $cp <= $base + 9) {
                return chr(48 + $cp - $base);
            }
        }
        return $ch;   // dotless i and j, Greek, the operators: their own meaning
    }

    private static function fail(string $why): array
    {
        return ['ok' => false, 'text' => '', 'error' => $why];
    }

    /**
     * Reads questions out of plain text.
     *
     * @return array{questions:array, warnings:array}
     */
    public static function parse(string $text): array
    {
        // read() has done this already. Repeated because this is public and
        // the failure it prevents is silent: one malformed byte anywhere and
        // the split below returns false, leaving a full worksheet looking
        // empty. See utf8().
        $text = self::utf8($text);

        $lines = self::separateOptions(preg_split('/\R/u', $text) ?: []);

        $questions = [];
        $warnings  = [];
        $key       = [];     // question number => the answer given at the back
        $inKey     = false;
        $q         = null;
        $mode      = null;   // which label we are still collecting lines for

        $flush = function () use (&$q, &$questions) {
            if ($q !== null) {
                $questions[] = $q;
                $q = null;
            }
        };

        foreach ($lines as $raw) {
            $line = trim(preg_replace('/\s+/u', ' ', (string)$raw));
            if ($line === '') {
                $mode = null;
                continue;
            }
            if (count($questions) >= self::MAX_QUESTIONS) {
                $warnings[] = 'Stopped after ' . self::MAX_QUESTIONS . ' questions — the rest of the file was not read.';
                break;
            }

            // ── The end of the questions ──────────────────────────────────
            // A worksheet that keeps its answers together puts them at the
            // back under a bare heading. Everything past it is a key: it is
            // not a continuation of the answer above it, and its numbered
            // entries are answers, not fifteen more questions.
            if (preg_match('/^(answer\s*key|answers|key)$/iu', $line)) {
                $flush();
                $inKey = true;
                $mode  = null;
                continue;
            }
            if ($inKey) {
                self::readKey($line, $key);
                continue;
            }

            // ── A new question ────────────────────────────────────────────
            // "1." and "1)" as before, and "1:" — but a colon straight onto a
            // digit is a clock or a ratio ("10:30", "3:2"), not a question, so
            // that form needs a space or a non-digit after it to count.
            if (preg_match('/^(\d{1,3})\s*(?:[.)]\s*|:(?:\s+|(?!\d)))(.*)$/u', $line, $m)) {
                $flush();
                $q = [
                    'type' => 'short_answer', 'text' => trim($m[2]), 'options' => [],
                    'correct_text' => '', 'solution' => '', 'hint' => '', 'points' => 1,
                    // Kept only so a key at the back can find its way home.
                    'number' => (int)$m[1],
                ];
                $mode = 'text';
                continue;
            }
            if ($q === null) {
                continue;   // preamble: a title, a name line, a date
            }

            /*
             * A worksheet that titles its questions — "1. Finding the 25th
             * term", then "Problem: An arithmetic sequence starts with 7..."
             * — means the second line as the question and the first as its
             * heading. Read as continuations they ran together into "Finding
             * the 25th term Problem: An arithmetic sequence...", so the
             * labelled line replaces the heading rather than joining it.
             */
            if (preg_match('/^(problem|question)\s*[:.\-]\s*(\S.*)$/iu', $line, $m)) {
                $q['text'] = trim($m[2]);
                $mode = 'text';
                continue;
            }

            // ── A labelled line ───────────────────────────────────────────
            if (preg_match('/^(answer|ans|correct)\s*[:.\-]\s*(.*)$/iu', $line, $m)) {
                $q['correct_text'] = trim($m[2]);
                $mode = 'answer';
                continue;
            }
            if (preg_match('/^(solution|working|explanation)\s*[:.\-]\s*(.*)$/iu', $line, $m)) {
                $q['solution'] = trim($m[2]);
                $mode = 'solution';
                continue;
            }
            if (preg_match('/^hint\s*[:.\-]\s*(.*)$/iu', $line, $m)) {
                $q['hint'] = self::add($q['hint'], trim($m[1]));
                $mode = 'hint';
                continue;
            }
            /*
             * "Given:" and "Formula:" — where they sit decides what they are.
             *
             * Before the working, they are the leg-up a mentee gets while they
             * are still trying, so they join the hint; without this they were
             * neither label nor option and ran on into the question text. Under
             * "Solution:" they are the first steps of the working itself, and
             * pulling them out would leave the solution starting mid-thought
             * and hand the formula over during the attempt.
             *
             * Either way the label is kept: "Given:" ahead of the numbers is
             * half of what makes them worth reading.
             */
            if (preg_match('/^(given|formula)\s*[:.\-]\s*(.*)$/iu', $line, $m)) {
                $label = ucfirst(mb_strtolower($m[1])) . ':';
                $body  = trim($m[2]);
                $piece = $body === '' ? $label : $label . ' ' . $body;
                if ($mode === 'solution') {
                    $q['solution'] = self::add($q['solution'], $piece);
                } else {
                    $q['hint'] = self::add($q['hint'], $piece);
                    $mode = 'hint';
                }
                continue;
            }
            if (preg_match('/^points?\s*[:.\-]\s*(\d{1,3})\s*$/iu', $line, $m)) {
                $q['points'] = max(1, (int)$m[1]);
                $mode = null;
                continue;
            }

            // ── An option ─────────────────────────────────────────────────
            if (preg_match('/^([a-hA-H])\s*[.)]\s*(.*)$/u', $line, $m)) {
                $body    = trim($m[2]);
                $starred = (bool)preg_match('/\s*\*\s*$/u', $body);
                if ($starred) {
                    $body = trim(preg_replace('/\s*\*\s*$/u', '', $body));
                }
                if ($body !== '') {
                    $q['options'][] = [
                        'letter'  => strtoupper($m[1]),
                        'text'    => $body,
                        'correct' => $starred,
                    ];
                    $mode = 'option';
                    continue;
                }
            }

            // ── A continuation of whatever came before ────────────────────
            if ($mode === 'solution')      { $q['solution'] = trim($q['solution'] . ' ' . $line); }
            elseif ($mode === 'hint')      { $q['hint']     = trim($q['hint'] . ' ' . $line); }
            elseif ($mode === 'answer')    { $q['correct_text'] = trim($q['correct_text'] . ' ' . $line); }
            elseif ($mode === 'option' && $q['options']) {
                $q['options'][count($q['options']) - 1]['text'] .= ' ' . $line;
            } elseif ($mode === 'text')    { $q['text'] = trim($q['text'] . ' ' . $line); }
        }
        $flush();
        self::applyKey($questions, $key, $warnings);
        $questions = self::dedupe($questions, $warnings);

        foreach ($questions as $i => &$question) {
            $question = self::settle($question, $i + 1, $warnings);
        }
        unset($question);

        if (!$questions) {
            $warnings[] = 'No questions were recognised. Each one has to start on its own line, numbered "1." or "1)".';
        }
        return ['questions' => $questions, 'warnings' => $warnings];
    }

    /**
     * Options written along one line, given a line each.
     *
     * "A. 2 B. 3 C. 4 D. 5" is one paragraph in Word and one line here, but it
     * is four options, and read as written it becomes a single option reading
     * "2 B. 3 C. 4 D. 5".
     *
     * The split is only made when the letters run A, B, C... in order from A
     * with no gaps — "A. the value of C. Smith" keeps its C, because C is not
     * what follows A. Two markers are enough to split on, so a sentence with
     * a "B." in it can still be divided; that is the accepted cost, because
     * the alternative loses real two-option questions, and a wrong split is
     * one visible extra option in a preview nothing is saved from until the
     * mentor has read it. Everything else is handed back untouched.
     */
    private static function separateOptions(array $lines): array
    {
        $out = [];
        foreach ($lines as $raw) {
            $line  = trim(preg_replace('/\s+/u', ' ', (string)$raw));
            $parts = self::splitOptions($line);
            if ($parts === null) {
                $out[] = $raw;
                continue;
            }
            foreach ($parts as $p) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** The options on one line, or null if that is not what this line is. */
    private static function splitOptions(string $line): ?array
    {
        if (!preg_match('/^[aA]\s*[.)]\s/u', $line)) {
            return null;   // a run of options starts at A, or it is not one
        }
        if (!preg_match_all('/(?:^|\s)([a-hA-H])\s*[.)]\s/u', $line, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $marks = [];
        foreach ($m[1] as $i => $g) {
            $letter   = strtoupper($g[0]);
            $expected = $marks ? chr(ord($marks[count($marks) - 1]['letter']) + 1) : 'A';
            if ($letter !== $expected) {
                continue;   // out of order: not part of the run
            }
            $marks[] = [
                'letter' => $letter,
                'start'  => $m[0][$i][1],
                'end'    => $m[0][$i][1] + strlen($m[0][$i][0]),
            ];
        }
        if (count($marks) < 2) {
            return null;   // one marker is just an ordinary option line
        }

        $parts = [];
        foreach ($marks as $i => $mark) {
            $stop = isset($marks[$i + 1]) ? $marks[$i + 1]['start'] : strlen($line);
            $parts[] = $mark['letter'] . '. ' . trim(substr($line, $mark['end'], $stop - $mark['end']));
        }
        return $parts;
    }

    /**
     * One line of an answer key, into $key by question number.
     *
     * Entries share a line — "1. C. 4 2. A. 38 3. B. ..." — and an answer may
     * itself hold numbers and full stops, so splitting on every "N." would cut
     * into the answers. A split is only made where the numbers run
     * consecutively, which is what a key does and what prose does not.
     */
    private static function readKey(string $line, array &$key): void
    {
        if (!preg_match_all('/(?:^|\s)(\d{1,3})\s*[.)]\s*/u', $line, $m, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $marks = [];
        foreach ($m[1] as $i => $g) {
            $n = (int)$g[0];
            if ($marks && $n !== $marks[count($marks) - 1]['n'] + 1) {
                continue;
            }
            $marks[] = [
                'n'     => $n,
                'start' => $m[0][$i][1],
                'end'   => $m[0][$i][1] + strlen($m[0][$i][0]),
            ];
        }

        foreach ($marks as $i => $mark) {
            $stop   = isset($marks[$i + 1]) ? $marks[$i + 1]['start'] : strlen($line);
            $answer = trim(substr($line, $mark['end'], $stop - $mark['end']));
            // First mention wins, so a key printed twice does not fight itself.
            if ($answer !== '' && !isset($key[$mark['n']])) {
                $key[$mark['n']] = $answer;
            }
        }
    }

    /**
     * The answers from the back of the paper, onto the questions they belong to.
     *
     * Only where a question has none of its own: a worksheet that marked its
     * answer beside the question has already said what it meant, and the key
     * is the second-hand copy. Entries matching no question are reported
     * rather than dropped, because a key numbered differently from the
     * questions is a mistake worth seeing.
     */
    private static function applyKey(array &$questions, array $key, array &$warnings): void
    {
        if (!$key) {
            return;
        }

        /*
         * A paper in parts often restarts its numbering at each one, so there
         * are three question 1s. A key matched by number would then be applied
         * to whichever came first, marking answers on the wrong questions —
         * silently, and wrongly, which is the one outcome worth refusing. The
         * key is left unapplied and the mentor told why.
         */
        $seen = [];
        foreach ($questions as $q) {
            $n = $q['number'] ?? null;
            if ($n !== null) {
                $seen[$n] = ($seen[$n] ?? 0) + 1;
            }
        }
        if ($seen && max($seen) > 1) {
            $warnings[] = 'The questions are numbered more than once over (a paper in parts), '
                . 'so the answer key at the back could not be matched to them. '
                . 'Fill those answers in below.';
            return;
        }

        $byNumber = [];
        foreach ($questions as $i => $q) {
            $n = $q['number'] ?? null;
            if ($n !== null && !isset($byNumber[$n])) {
                $byNumber[$n] = $i;
            }
        }

        $stray = [];
        foreach ($key as $n => $answer) {
            if (!isset($byNumber[$n])) {
                $stray[] = $n;
                continue;
            }
            $at = $byNumber[$n];
            $alreadyMarked = (bool)array_filter($questions[$at]['options'], fn($o) => $o['correct']);
            if (trim($questions[$at]['correct_text']) !== '' || $alreadyMarked) {
                continue;
            }
            $questions[$at]['correct_text'] = $answer;
        }

        if ($stray) {
            sort($stray);
            $warnings[] = 'The answer key has ' . (count($stray) === 1 ? 'an entry' : 'entries')
                . ' numbered ' . implode(', ', $stray) . ' with no question to match. Check the numbering.';
        }
    }

    /**
     * One copy of each question.
     *
     * Some PDF producers draw the page's text twice — a second pass for a
     * shadow, an overlay, a flattened layer — and the extracted text then has
     * the whole worksheet in it end to end, twice. Nothing in the file marks
     * the second pass as a repeat, so identical question text is what there is
     * to go on: two questions worded exactly alike are one question.
     *
     * The copies are rarely equal. A page break can cut one of them short, so
     * the fuller copy is the one kept — and the mentor is told, because on the
     * small chance a worksheet really does ask the same thing twice, that is
     * their call to make and not this code's.
     */
    private static function dedupe(array $questions, array &$warnings): array
    {
        $keep    = [];
        $at      = [];
        $dropped = 0;

        foreach ($questions as $q) {
            $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $q['text'])));
            if ($key === '' || !isset($at[$key])) {
                if ($key !== '') {
                    $at[$key] = count($keep);
                }
                $keep[] = $q;
                continue;
            }
            $dropped++;
            if (self::weight($q) > self::weight($keep[$at[$key]])) {
                $keep[$at[$key]] = $q;
            }
        }

        if ($dropped > 0) {
            $warnings[] = $dropped . ' question' . ($dropped === 1 ? '' : 's')
                . ' appeared more than once in the file, so the repeats were left out.';
        }
        return $keep;
    }

    /** Adds a piece to a field that may already have one in it. */
    private static function add(string $have, string $piece): string
    {
        $piece = trim($piece);
        if ($piece === '') {
            return $have;
        }
        return $have === '' ? $piece : $have . ' ' . $piece;
    }

    /** How much of a question came through, for choosing between two copies. */
    private static function weight(array $q): int
    {
        return count($q['options']) * 100
            + mb_strlen($q['correct_text']) + mb_strlen($q['solution']) + mb_strlen($q['hint']);
    }

    /**
     * Works out the question's type and which option is correct, and says so
     * when it cannot. A question whose answer is unknown is still imported —
     * the mentor is about to look at all of them anyway, and dropping it
     * silently would be worse than handing it over unmarked.
     */
    private static function settle(array $q, int $n, array &$warnings): array
    {
        $answer = trim($q['correct_text']);

        if ($q['options']) {
            /*
             * An answer may name a letter ("B"), repeat the option's words, or
             * do both at once the way a key does ("B. a_n = a_1 + (n-1)d").
             * When it does both and the two disagree, the words are believed
             * and the mentor is told: a letter is a label, and mistyping one
             * is the easiest mistake to make in a key — but it is their paper,
             * so the disagreement is reported rather than quietly resolved.
             */
            if ($answer !== '') {
                $byLetter = null;
                $byText   = $answer;
                if (preg_match('/^([a-hA-H])$/u', $answer)) {
                    $byLetter = strtoupper($answer);
                    $byText   = '';
                } elseif (preg_match('/^([a-hA-H])\s*[.)]\s*(\S.*)$/u', $answer, $mm)) {
                    $byLetter = strtoupper($mm[1]);
                    $byText   = trim($mm[2]);
                }

                $letterAt = null;
                $textAt   = null;
                foreach ($q['options'] as $i => $opt) {
                    if ($byLetter !== null && $opt['letter'] === $byLetter) {
                        $letterAt = $i;
                    }
                    if ($byText !== '' && mb_strtolower(trim($opt['text'])) === mb_strtolower($byText)) {
                        $textAt ??= $i;
                    }
                }

                $pick = $textAt ?? $letterAt;
                if ($pick !== null) {
                    $q['options'][$pick]['correct'] = true;
                }
                if ($letterAt !== null && $textAt !== null && $letterAt !== $textAt) {
                    $warnings[] = "Question $n: the answer says "
                        . $q['options'][$letterAt]['letter'] . ' but the wording matches '
                        . $q['options'][$textAt]['letter'] . '. The wording was used — check which is right.';
                }
            }

            $marked = array_values(array_filter($q['options'], fn($o) => $o['correct']));
            if (!$marked) {
                $warnings[] = "Question $n: no answer was marked. Choose the correct option before publishing.";
            } elseif (count($marked) > 1) {
                $warnings[] = "Question $n: more than one option is marked correct. Only one can be.";
            }

            $texts = array_map(fn($o) => mb_strtolower(trim($o['text'])), $q['options']);
            sort($texts);
            $q['type'] = ($texts === ['false', 'true']) ? 'true_false' : 'multiple_choice';
            $q['correct_text'] = '';
        } elseif (in_array(mb_strtolower($answer), ['true', 'false'], true)) {
            /*
             * A True or False section rarely writes its two options out — the
             * paper says "True or False" once at the top and the answer only
             * turns up in the key. An answer that is the word True or the word
             * False is that, so the options it implies are supplied, and the
             * mentee gets two buttons rather than a box to spell "True" into.
             */
            $isTrue = mb_strtolower($answer) === 'true';
            $q['options'] = [
                ['letter' => 'A', 'text' => 'True',  'correct' => $isTrue],
                ['letter' => 'B', 'text' => 'False', 'correct' => !$isTrue],
            ];
            $q['type']         = 'true_false';
            $q['correct_text'] = '';
        } else {
            $q['type'] = 'short_answer';
            if ($answer === '') {
                $warnings[] = "Question $n: no answer was given. Add one, or delete the question.";
            }
        }

        if (trim($q['text']) === '') {
            $warnings[] = "Question $n: the question itself is blank.";
        }

        // Letters were only ever for matching an Answer line to an option,
        // and the worksheet's own numbering only for finding the key.
        foreach ($q['options'] as &$o) {
            unset($o['letter']);
        }
        unset($o);
        unset($q['number']);

        return $q;
    }
}
