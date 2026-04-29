<?php
namespace Grav\Plugin\BatchReplace;

/**
 * Core find-and-replace logic for the GravBatchReplace plugin.
 *
 * Scans .md page files recursively under a base directory and either reports
 * matches (find-only) or applies replacements. The class is filesystem-only;
 * it has no Grav-specific dependencies, which makes it easy to unit test.
 *
 * @package Grav\Plugin\BatchReplace
 */
class BatchReplaceTool
{
    /** Control-char sentinels used to mark match start/end inside excerpts.
     *  Survive JSON encoding and never appear in normal text content. */
    public const HL_OPEN  = "\x01";
    public const HL_CLOSE = "\x02";

    /** @var string Absolute path to the directory to scan (no trailing slash). */
    protected string $baseDir;

    /** @var int Maximum number of matching lines to retain in the report. */
    protected int $maxResults;

    /** @var bool Whether the limit was reached during scanning. */
    protected bool $truncated = false;

    /** @var array<string> Errors collected during processing (permissions, encoding, regex, etc.). */
    protected array $errors = [];

    /**
     * @param string $baseDir    Absolute path to scan recursively.
     * @param int    $maxResults Cap on matching lines included in the report.
     */
    public function __construct(string $baseDir, int $maxResults = 500)
    {
        $this->baseDir    = rtrim($baseDir, DIRECTORY_SEPARATOR);
        $this->maxResults = max(10, $maxResults);
    }

    /**
     * Validate a regex pattern by attempting a no-op preg_match.
     *
     * @param string $pattern PCRE pattern (already wrapped with delimiters).
     * @return string|null    Error message on failure, null on success.
     */
    public static function validateRegex(string $pattern): ?string
    {
        // Suppress warnings, capture via error handler.
        $error = null;
        set_error_handler(function ($errno, $errstr) use (&$error) {
            $error = $errstr;
            return true;
        });
        $result = @preg_match($pattern, '');
        restore_error_handler();

        if ($result === false) {
            $code = preg_last_error();
            $msg  = $error ?: ('preg_match error code ' . $code);
            return 'Invalid regex: ' . $msg;
        }
        return null;
    }

    /**
     * Build a PCRE pattern from user input.
     *
     * If $useRegex is true the user is responsible for the body of the regex;
     * we wrap it with `~` delimiters and add UTF-8 + multiline flags. Any
     * occurrence of `~` in user input is escaped so the delimiters stay valid.
     *
     * @param string $find         The user-supplied search string.
     * @param bool   $useRegex     Treat $find as a PCRE pattern when true.
     * @param bool   $caseSensitive Case-sensitive when true.
     * @return string              A complete PCRE pattern ready for preg_*.
     */
    public static function buildPattern(string $find, bool $useRegex, bool $caseSensitive): string
    {
        $flags = 'u'; // UTF-8
        if (!$caseSensitive) {
            $flags .= 'i';
        }

        if ($useRegex) {
            $body = str_replace('~', '\~', $find);
        } else {
            $body = preg_quote($find, '~');
        }
        return '~' . $body . '~' . $flags;
    }

    /**
     * Scan files for matches without modifying anything.
     *
     * @param string $find          User input.
     * @param bool   $useRegex      Whether $find is a regex.
     * @param bool   $caseSensitive Case sensitivity flag.
     * @return array{
     *     summary: array{files_scanned:int,files_with_matches:int,total_matches:int,truncated:bool},
     *     files: array<int, array{path:string, count:int, lines: array<int, array{line:int, excerpt:string}>}>,
     *     errors: array<int, string>
     * }
     */
    public function findOnly(string $find, bool $useRegex, bool $caseSensitive): array
    {
        $pattern = self::buildPattern($find, $useRegex, $caseSensitive);
        $patternError = self::validateRegex($pattern);
        if ($patternError !== null) {
            return [
                'summary' => [
                    'files_scanned' => 0,
                    'files_with_matches' => 0,
                    'total_matches' => 0,
                    'truncated' => false,
                ],
                'files' => [],
                'errors' => [$patternError],
            ];
        }

        $this->truncated = false;
        $this->errors    = [];
        $totalLinesShown = 0;
        $filesScanned    = 0;
        $filesWithHits   = 0;
        $totalMatches    = 0;
        $report          = [];

        foreach ($this->iterateMarkdownFiles() as $absPath) {
            $filesScanned++;
            $contents = $this->readFile($absPath);
            if ($contents === null) {
                continue;
            }

            $fileMatches = 0;
            $fileLines   = [];
            $lines       = preg_split('/\R/u', $contents) ?: [];

            foreach ($lines as $idx => $line) {
                $count = @preg_match_all($pattern, $line);
                if ($count === false) {
                    $this->errors[] = sprintf(
                        'Pattern error while scanning %s: %s',
                        $this->relPath($absPath),
                        preg_last_error_msg()
                    );
                    continue 2;
                }
                if ($count > 0) {
                    $fileMatches += $count;
                    if ($totalLinesShown < $this->maxResults) {
                        $fileLines[] = [
                            'line'    => $idx + 1,
                            'excerpt' => $this->makeExcerpt($line, $pattern),
                        ];
                        $totalLinesShown++;
                    } else {
                        $this->truncated = true;
                    }
                }
            }

            if ($fileMatches > 0) {
                $filesWithHits++;
                $totalMatches += $fileMatches;
                $report[] = [
                    'path'  => $this->relPath($absPath),
                    'count' => $fileMatches,
                    'lines' => $fileLines,
                ];
            }
        }

        return [
            'summary' => [
                'files_scanned'      => $filesScanned,
                'files_with_matches' => $filesWithHits,
                'total_matches'      => $totalMatches,
                'truncated'          => $this->truncated,
            ],
            'files'  => $report,
            'errors' => $this->errors,
        ];
    }

    /**
     * Apply replacements to all matching files. Files with no matches are not
     * touched on disk.
     *
     * @param string $find          User input.
     * @param string $replace       Replacement string (may be empty).
     * @param bool   $useRegex      Whether $find is a regex.
     * @param bool   $caseSensitive Case sensitivity flag.
     * @return array{
     *     summary: array{files_scanned:int,files_modified:int,total_replacements:int,truncated:bool},
     *     files: array<int, array{path:string, count:int, lines: array<int, array{line:int, excerpt:string}>}>,
     *     errors: array<int, string>
     * }
     */
    public function findAndReplace(string $find, string $replace, bool $useRegex, bool $caseSensitive): array
    {
        $pattern = self::buildPattern($find, $useRegex, $caseSensitive);
        $patternError = self::validateRegex($pattern);
        if ($patternError !== null) {
            return [
                'summary' => [
                    'files_scanned'      => 0,
                    'files_modified'     => 0,
                    'total_replacements' => 0,
                    'truncated'          => false,
                ],
                'files'  => [],
                'errors' => [$patternError],
            ];
        }

        $this->truncated = false;
        $this->errors    = [];
        $totalLinesShown = 0;
        $filesScanned    = 0;
        $filesModified   = 0;
        $totalReps       = 0;
        $report          = [];

        foreach ($this->iterateMarkdownFiles() as $absPath) {
            $filesScanned++;
            $original = $this->readFile($absPath);
            if ($original === null) {
                continue;
            }

            $count    = 0;
            $modified = @preg_replace($pattern, $replace, $original, -1, $count);
            if ($modified === null) {
                $this->errors[] = sprintf(
                    'Replacement error in %s: %s',
                    $this->relPath($absPath),
                    preg_last_error_msg()
                );
                continue;
            }
            if ($count === 0 || $modified === $original) {
                continue;
            }

            // Collect line-level report for the user, based on the ORIGINAL file.
            $fileLines = [];
            $lines     = preg_split('/\R/u', $original) ?: [];
            foreach ($lines as $idx => $line) {
                $lineHits = @preg_match_all($pattern, $line);
                if ($lineHits > 0) {
                    if ($totalLinesShown < $this->maxResults) {
                        $fileLines[] = [
                            'line'    => $idx + 1,
                            'excerpt' => $this->makeExcerpt($line, $pattern),
                        ];
                        $totalLinesShown++;
                    } else {
                        $this->truncated = true;
                    }
                }
            }

            if (!is_writable($absPath)) {
                $this->errors[] = 'Cannot write (permission denied): ' . $this->relPath($absPath);
                continue;
            }

            $bytes = @file_put_contents($absPath, $modified);
            if ($bytes === false) {
                $this->errors[] = 'Write failed: ' . $this->relPath($absPath);
                continue;
            }

            $filesModified++;
            $totalReps += $count;
            $report[] = [
                'path'  => $this->relPath($absPath),
                'count' => $count,
                'lines' => $fileLines,
            ];
        }

        return [
            'summary' => [
                'files_scanned'      => $filesScanned,
                'files_modified'     => $filesModified,
                'total_replacements' => $totalReps,
                'truncated'          => $this->truncated,
            ],
            'files'  => $report,
            'errors' => $this->errors,
        ];
    }

    /**
     * Replace a single occurrence on one specific line of one specific file.
     *
     * The path must resolve inside the configured base directory and end in
     * `.md`. The line number is 1-based. Only the first match on that line is
     * replaced.
     *
     * @param string $relOrAbsPath  Path relative to Grav root (e.g. user/pages/foo/item.md)
     *                              or absolute path inside the base directory.
     * @param int    $lineNumber    1-based line number.
     * @param string $find          User input.
     * @param string $replace       Replacement string.
     * @param bool   $useRegex      Whether $find is a regex.
     * @param bool   $caseSensitive Case sensitivity flag.
     * @return array{
     *     success: bool,
     *     error: string|null,
     *     path: string,
     *     line: int,
     *     excerpt: string|null,
     *     remaining_in_line: int
     * }
     */
    public function replaceInLine(
        string $relOrAbsPath,
        int $lineNumber,
        string $find,
        string $replace,
        bool $useRegex,
        bool $caseSensitive
    ): array {
        $abs = $this->resolveSafePath($relOrAbsPath);
        if ($abs === null) {
            return $this->lineResult(false, 'Invalid or unsafe file path.', $relOrAbsPath, $lineNumber);
        }
        if ($lineNumber < 1) {
            return $this->lineResult(false, 'Invalid line number.', $relOrAbsPath, $lineNumber);
        }

        $pattern = self::buildPattern($find, $useRegex, $caseSensitive);
        $patternError = self::validateRegex($pattern);
        if ($patternError !== null) {
            return $this->lineResult(false, $patternError, $relOrAbsPath, $lineNumber);
        }

        if (!is_readable($abs)) {
            return $this->lineResult(false, 'Cannot read file (permission denied).', $relOrAbsPath, $lineNumber);
        }
        $contents = @file_get_contents($abs);
        if ($contents === false) {
            return $this->lineResult(false, 'Read failed.', $relOrAbsPath, $lineNumber);
        }

        // Preserve the original line endings: split with a regex that captures separators.
        $parts = preg_split('/(\R)/u', $contents, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        // $parts is [line0, sep0, line1, sep1, ...]; lines live at even indices.
        $lineIdx = ($lineNumber - 1) * 2;
        if (!isset($parts[$lineIdx])) {
            return $this->lineResult(false, 'Line number out of range.', $relOrAbsPath, $lineNumber);
        }
        $originalLine = $parts[$lineIdx];

        $count = 0;
        $newLine = @preg_replace($pattern, $replace, $originalLine, 1, $count);
        if ($newLine === null) {
            return $this->lineResult(false, 'Replacement error: ' . preg_last_error_msg(), $relOrAbsPath, $lineNumber);
        }
        if ($count === 0 || $newLine === $originalLine) {
            return $this->lineResult(false, 'No match on that line.', $relOrAbsPath, $lineNumber);
        }

        $parts[$lineIdx] = $newLine;
        $newContents = implode('', $parts);

        if (!is_writable($abs)) {
            return $this->lineResult(false, 'Cannot write file (permission denied).', $relOrAbsPath, $lineNumber);
        }
        $bytes = @file_put_contents($abs, $newContents);
        if ($bytes === false) {
            return $this->lineResult(false, 'Write failed.', $relOrAbsPath, $lineNumber);
        }

        $remaining = @preg_match_all($pattern, $newLine) ?: 0;

        return [
            'success'           => true,
            'error'             => null,
            'path'              => $this->relPath($abs),
            'line'              => $lineNumber,
            'excerpt'           => $this->makeExcerpt($newLine, $pattern),
            'remaining_in_line' => (int) $remaining,
        ];
    }

    /**
     * Resolve a user-supplied path to an absolute path that is guaranteed to
     * sit inside the configured base directory and to end in `.md`. Returns
     * null on any safety violation.
     *
     * @param string $relOrAbs
     * @return string|null
     */
    protected function resolveSafePath(string $relOrAbs): ?string
    {
        $candidate = $relOrAbs;

        // If the path is relative (e.g. user/pages/...), join with the parent of base.
        if (!preg_match('~^/|^[A-Z]:[\\\\/]~i', $candidate)) {
            // Strip leading slashes / "user/" prefix; resolve under base dir's parent of "user".
            $needle = DIRECTORY_SEPARATOR . 'user' . DIRECTORY_SEPARATOR;
            $rootPos = strpos($this->baseDir, $needle);
            if ($rootPos === false) {
                return null;
            }
            $gravRoot = substr($this->baseDir, 0, $rootPos);
            $candidate = $gravRoot . DIRECTORY_SEPARATOR . ltrim($candidate, '/\\');
        }

        $real = realpath($candidate);
        $baseReal = realpath($this->baseDir);
        if ($real === false || $baseReal === false) {
            return null;
        }
        // Must live strictly inside the base dir.
        if (strncmp($real, $baseReal . DIRECTORY_SEPARATOR, strlen($baseReal) + 1) !== 0) {
            return null;
        }
        if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'md') {
            return null;
        }
        if (!is_file($real)) {
            return null;
        }
        return $real;
    }

    /**
     * Build a uniform line-replace result envelope.
     *
     * @param bool        $ok
     * @param string|null $err
     * @param string      $path
     * @param int         $line
     * @return array{success:bool,error:string|null,path:string,line:int,excerpt:null,remaining_in_line:int}
     */
    protected function lineResult(bool $ok, ?string $err, string $path, int $line): array
    {
        return [
            'success'           => $ok,
            'error'             => $err,
            'path'              => $path,
            'line'              => $line,
            'excerpt'           => null,
            'remaining_in_line' => 0,
        ];
    }

    /**
     * Yield every readable .md file beneath the base directory.
     *
     * @return \Generator<int, string>
     */
    protected function iterateMarkdownFiles(): \Generator
    {
        if (!is_dir($this->baseDir)) {
            $this->errors[] = 'Pages directory not found: ' . $this->baseDir;
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->baseDir,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            yield $file->getPathname();
        }
    }

    /**
     * Read a file as UTF-8 text. Skips binary content and reports permission errors.
     *
     * @param string $absPath
     * @return string|null File contents, or null when unreadable / non-text.
     */
    protected function readFile(string $absPath): ?string
    {
        if (!is_readable($absPath)) {
            $this->errors[] = 'Cannot read (permission denied): ' . $this->relPath($absPath);
            return null;
        }
        $contents = @file_get_contents($absPath);
        if ($contents === false) {
            $this->errors[] = 'Read failed: ' . $this->relPath($absPath);
            return null;
        }
        // Cheap binary-content guard: NUL byte in the first 8KB.
        $sample = substr($contents, 0, 8192);
        if (strpos($sample, "\x00") !== false) {
            $this->errors[] = 'Skipped binary file: ' . $this->relPath($absPath);
            return null;
        }
        return $contents;
    }

    /**
     * Make a human-friendly excerpt of a long line. When $pattern is given,
     * wraps each match with the HL_OPEN/HL_CLOSE sentinels so the client can
     * render highlights via <mark> tags. The wrapping happens BEFORE
     * truncation so the sentinels are never split across the ellipsis.
     *
     * @param string      $line
     * @param string|null $pattern  Optional PCRE pattern (already wrapped with delimiters/flags).
     * @return string
     */
    protected function makeExcerpt(string $line, ?string $pattern = null): string
    {
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
        $line = trim($line);

        if ($pattern !== null && $pattern !== '') {
            $wrapped = @preg_replace_callback(
                $pattern,
                static function ($m) {
                    return self::HL_OPEN . $m[0] . self::HL_CLOSE;
                },
                $line
            );
            if (is_string($wrapped)) {
                $line = $wrapped;
            }
        }

        $softLen = 200;   // preferred max length when the first match fits
        $hardLen = 2000;  // absolute cap regardless

        $totalLen = mb_strlen($line, 'UTF-8');
        if ($totalLen <= $softLen) {
            return $line;
        }

        // Find where the first match starts (first HL_OPEN sentinel).
        $firstHl = mb_strpos($line, self::HL_OPEN, 0, 'UTF-8');

        // If the first match is still inside the soft window, truncate as before.
        // Otherwise show the full line (so the user can actually see the match)
        // up to the hard cap.
        if ($firstHl !== false && $firstHl < ($softLen - 10)) {
            $truncated = mb_substr($line, 0, $softLen, 'UTF-8');
            $opens  = substr_count($truncated, self::HL_OPEN);
            $closes = substr_count($truncated, self::HL_CLOSE);
            if ($opens > $closes) {
                $truncated .= self::HL_CLOSE;
            }
            return $truncated . '…';
        }

        if ($totalLen <= $hardLen) {
            return $line;
        }

        // Hard-cap fallback for pathologically long lines: keep enough context
        // around the first match.
        if ($firstHl !== false) {
            $contextBefore = 80;
            $start = max(0, $firstHl - $contextBefore);
            $slice = mb_substr($line, $start, $hardLen, 'UTF-8');
            $opens  = substr_count($slice, self::HL_OPEN);
            $closes = substr_count($slice, self::HL_CLOSE);
            if ($opens > $closes) {
                $slice .= self::HL_CLOSE;
            }
            return ($start > 0 ? '…' : '') . $slice . (mb_strlen($line, 'UTF-8') > $start + $hardLen ? '…' : '');
        }

        return mb_substr($line, 0, $hardLen, 'UTF-8') . '…';
    }

    /**
     * Convert an absolute path to one relative to the Grav root (parent of /user).
     *
     * @param string $absPath
     * @return string
     */
    protected function relPath(string $absPath): string
    {
        // Show the path relative to the parent of base dir's "user" segment if possible.
        $needle = DIRECTORY_SEPARATOR . 'user' . DIRECTORY_SEPARATOR;
        $pos    = strpos($absPath, $needle);
        if ($pos !== false) {
            return ltrim(substr($absPath, $pos + 1), DIRECTORY_SEPARATOR);
        }
        return $absPath;
    }
}
