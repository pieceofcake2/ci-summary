<?php
/**
 * Turns a CI report into a short Markdown job summary for GitHub Actions.
 *
 *   php summary.php junit      <title> <junit.xml> [<clover.xml>]
 *   php summary.php checkstyle <title> <phpcs-checkstyle.xml>
 *   php summary.php phpstan    <title> <phpstan.json | phpstan-checkstyle.xml>
 *
 * The Markdown is written to stdout. A missing or unreadable report gives a
 * short note instead of an error, so this script never fails a job by itself.
 *
 * No dependencies besides SimpleXML and JSON; runs on PHP 8.0 and later.
 */

const MAX_ROWS = 50;
const MAX_CELL = 200;
const MAX_DETAILS = 10;
const MAX_DETAIL_LINES = 60;

/**
 * @param list<string> $args Command line arguments without the script name.
 */
function summary(array $args): string
{
    [$type, $title, $report, $coverage] = array_pad($args, 4, '');

    switch ($type) {
        case 'junit':
            return junitSummary($title ?: 'PHPUnit', $report, $coverage);
        case 'checkstyle':
            return checkstyleSummary($title ?: 'PHPCS', $report);
        case 'phpstan':
            return phpstanSummary($title ?: 'PHPStan', $report);
    }

    return "Usage: php summary.php <junit|checkstyle|phpstan> <title> <report> [<clover>]\n";
}

// ---------------------------------------------------------------------------
// PHPUnit (JUnit XML, optionally with a Clover coverage report)
// ---------------------------------------------------------------------------

function junitSummary(string $title, string $report, string $coverage): string
{
    $xml = loadXml($report);
    if ($xml === null) {
        return missingReport($title, $report);
    }

    // PHPUnit nests suites (directory > class > data provider); the outermost ones hold the totals.
    $suites = $xml->getName() === 'testsuite' ? [$xml] : $xml->testsuite;
    $totals = ['tests' => 0, 'assertions' => 0, 'failures' => 0, 'errors' => 0, 'warnings' => 0, 'skipped' => 0, 'time' => 0.0];
    foreach ($suites as $suite) {
        foreach ($totals as $key => $value) {
            $totals[$key] = $value + ($key === 'time' ? (float)$suite[$key] : (int)$suite[$key]);
        }
    }

    $problems = [];
    $skipped = [];
    foreach ($xml->xpath('//testcase') ?: [] as $case) {
        foreach (['failure', 'error', 'warning'] as $kind) {
            foreach ($case->{$kind} as $node) {
                $problems[] = junitProblem($case, $node, $kind);
            }
        }
        if (isset($case->skipped)) {
            $skipped[] = [testName($case), trim((string)$case->skipped['message'] ?: (string)$case->skipped)];
        }
    }

    $failed = $totals['failures'] + $totals['errors'] + $totals['warnings'];
    $mark = $failed > 0 || $problems ? '❌' : ($totals['tests'] === 0 ? '⚠️' : '✅');

    $parts = [
        plural($totals['tests'], 'test'),
        plural($totals['assertions'], 'assertion'),
        plural($totals['failures'], 'failure'),
    ];
    if ($totals['errors'] > 0) {
        $parts[] = plural($totals['errors'], 'error');
    }
    if ($totals['warnings'] > 0) {
        $parts[] = plural($totals['warnings'], 'warning');
    }
    $parts[] = $totals['skipped'] . ' skipped';
    $line = implode(', ', $parts) . ' · ' . duration($totals['time']);
    $percentages = $coverage !== '' ? coverage($coverage) : null;
    if ($percentages !== null) {
        $line .= ' · coverage ' . $percentages;
    }

    $out = heading($mark, $title) . $line . "\n";

    if ($problems) {
        $rows = array_map(fn(array $p): array => [cell($p['test']), code($p['location']), cell($p['short'])], $problems);
        $out .= "\n" . table(['Test', 'Location', 'Message'], $rows);

        $long = array_filter($problems, fn(array $p): bool => $p['long']);
        if ($long) {
            $out .= "\n<details><summary>Full messages</summary>\n";
            foreach (array_slice($long, 0, MAX_DETAILS) as $p) {
                $out .= "\n**" . cell($p['test']) . "**\n\n" . codeBlock($p['full']);
            }
            $out .= more(count($long), MAX_DETAILS) . "\n</details>\n";
        }
    }

    if ($skipped) {
        $out .= "\n<details><summary>" . $totals['skipped'] . " skipped</summary>\n\n";
        foreach (array_slice($skipped, 0, MAX_ROWS) as [$test, $reason]) {
            $out .= '- ' . cell($test) . ($reason !== '' ? ' — ' . cell($reason) : '') . "\n";
        }
        $out .= more(count($skipped), MAX_ROWS) . "\n</details>\n";
    }

    return $out;
}

/**
 * PHPUnit writes "Class::test", the message, a blank line and the stack trace
 * into the element body. Pick the message and the frame in the test file.
 *
 * @return array{test: string, location: string, short: string, full: string, long: bool}
 */
function junitProblem(SimpleXMLElement $case, SimpleXMLElement $node, string $kind): array
{
    $lines = explode("\n", str_replace("\r", '', trim((string)$node)));
    if ($lines && preg_match('/^\S+::\S/', $lines[0]) && str_contains($lines[0], (string)$case['name'])) {
        array_shift($lines);
    }
    $trace = [];
    while ($lines && (trim(end($lines)) === '' || preg_match('/^\S*[\/\\\\]\S*:\d+$/', trim(end($lines))))) {
        $frame = trim(array_pop($lines));
        if ($frame !== '') {
            array_unshift($trace, $frame);
        }
    }

    $full = trim(implode("\n", $lines));
    $short = trim((string)$node['message']);
    if ($short === '') {
        // Stop before an expected/actual diff; keep the lines that explain the failure.
        $head = preg_split('/\n--- Expected/', $full)[0];
        $short = (string)preg_replace('/\s+/', ' ', trim($head));
    }
    // The full text is worth showing when the table cell cannot hold all of it (a diff, a long message).
    $long = $full !== '' && (preg_replace('/\s+/', ' ', $full) !== $short || strlen($short) > MAX_CELL);
    if ($kind === 'warning') {
        $short = 'Warning: ' . $short;
    }

    $file = (string)$case['file'];
    $location = $file !== '' ? $file . ':' . (int)$case['line'] : '';
    foreach ($trace as $frame) {
        if ($file !== '' && str_starts_with($frame, $file . ':')) {
            $location = $frame;
            break;
        }
    }
    if ($location === '' && $trace) {
        $location = $trace[0];
    }

    return ['test' => testName($case), 'location' => relativePath($location), 'short' => $short, 'full' => $full, 'long' => $long];
}

function testName(SimpleXMLElement $case): string
{
    $class = (string)($case['class'] ?: $case['classname']);
    $class = (string)preg_replace('/^.*[\\\\.]/', '', $class);

    return ($class !== '' ? $class . '::' : '') . (string)$case['name'];
}

/**
 * Overall line, method and class coverage from a Clover report, e.g. "89.1% lines, 80.0% methods, 70.0% classes".
 */
function coverage(string $report): ?string
{
    $xml = loadXml($report);
    if ($xml === null || !isset($xml->project->metrics)) {
        return null;
    }
    $metrics = $xml->project->metrics;

    $classes = 0;
    $coveredClasses = 0;
    foreach ($xml->xpath('//class/metrics') ?: [] as $class) {
        if ((int)$class['methods'] > 0) {
            $classes++;
            $coveredClasses += (int)$class['coveredmethods'] === (int)$class['methods'] ? 1 : 0;
        }
    }

    return implode(', ', [
        percent((int)$metrics['coveredstatements'], (int)$metrics['statements']) . ' lines',
        percent((int)$metrics['coveredmethods'], (int)$metrics['methods']) . ' methods',
        percent($coveredClasses, $classes) . ' classes',
    ]);
}

// ---------------------------------------------------------------------------
// phpcs (Checkstyle XML)
// ---------------------------------------------------------------------------

function checkstyleSummary(string $title, string $report): string
{
    $xml = loadXml($report);
    if ($xml === null) {
        return missingReport($title, $report);
    }

    $rows = [];
    $errors = 0;
    $warnings = 0;
    $files = 0;
    foreach ($xml->file as $file) {
        $files += count($file->error) > 0 ? 1 : 0;
        foreach ($file->error as $error) {
            $isWarning = (string)$error['severity'] === 'warning';
            $isWarning ? $warnings++ : $errors++;
            $rows[] = [
                code(relativePath((string)$file['name']) . ':' . (int)$error['line']),
                cell(($isWarning ? 'Warning: ' : '') . (string)$error['message']),
                cell((string)$error['source']),
            ];
        }
    }

    if (!$rows) {
        return heading('✅', $title) . "No coding standard violations.\n";
    }

    $line = plural($errors, 'error') . ', ' . plural($warnings, 'warning') . ' in ' . plural($files, 'file') . '.';

    return heading($errors > 0 ? '❌' : '⚠️', $title)
        . $line . "\n\n"
        . table(['Location', 'Message', 'Rule'], $rows)
        . "\nRun `vendor/bin/phpcbf` to fix the fixable ones.\n";
}

// ---------------------------------------------------------------------------
// PHPStan (--error-format=json, or --error-format=checkstyle)
// ---------------------------------------------------------------------------

function phpstanSummary(string $title, string $report): string
{
    $content = is_file($report) ? trim((string)file_get_contents($report)) : '';
    $rows = null;
    if (str_starts_with($content, '{')) {
        $rows = phpstanJsonRows($content);
    } elseif (str_starts_with($content, '<')) {
        $rows = phpstanCheckstyleRows($report);
    }
    if ($rows === null) {
        return missingReport($title, $report);
    }

    if (!$rows) {
        return heading('✅', $title) . "No errors.\n";
    }

    $files = count(array_unique(array_filter(array_column($rows, 'file'))));

    return heading('❌', $title)
        . plural(count($rows), 'error') . ' in ' . plural($files, 'file') . ".\n\n"
        . table(
            ['Location', 'Message', 'Identifier'],
            array_map(fn(array $r): array => [
                $r['file'] !== '' ? code($r['file'] . ($r['line'] > 0 ? ':' . $r['line'] : '')) : '',
                cell($r['message']),
                cell($r['identifier']),
            ], $rows),
        );
}

/**
 * @return list<array{file: string, line: int, message: string, identifier: string}>|null
 */
function phpstanJsonRows(string $json): ?array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    $rows = [];
    foreach ($data['files'] ?? [] as $path => $result) {
        foreach ($result['messages'] ?? [] as $message) {
            $rows[] = [
                'file' => relativePath((string)$path),
                'line' => (int)($message['line'] ?? 0),
                'message' => (string)($message['message'] ?? ''),
                'identifier' => (string)($message['identifier'] ?? ''),
            ];
        }
    }
    foreach ($data['errors'] ?? [] as $error) {
        $rows[] = ['file' => '', 'line' => 0, 'message' => (string)$error, 'identifier' => ''];
    }

    return $rows;
}

/**
 * @return list<array{file: string, line: int, message: string, identifier: string}>|null
 */
function phpstanCheckstyleRows(string $report): ?array
{
    $xml = loadXml($report);
    if ($xml === null) {
        return null;
    }
    $rows = [];
    foreach ($xml->file as $file) {
        foreach ($file->error as $error) {
            $rows[] = [
                'file' => relativePath((string)$file['name']),
                'line' => (int)$error['line'],
                'message' => (string)$error['message'],
                'identifier' => (string)$error['source'],
            ];
        }
    }

    return $rows;
}

// ---------------------------------------------------------------------------
// Markdown helpers
// ---------------------------------------------------------------------------

function heading(string $mark, string $title): string
{
    return '### ' . $mark . ' ' . cell($title) . "\n\n";
}

function missingReport(string $title, string $report): string
{
    return heading('⚠️', $title)
        . 'No readable report at ' . code(relativePath($report)) . '. An earlier step probably failed; see the job log.' . "\n";
}

/**
 * @param list<string> $headers
 * @param list<list<string>> $rows Cells that are already escaped.
 */
function table(array $headers, array $rows): string
{
    $out = '| ' . implode(' | ', $headers) . " |\n|" . str_repeat(' --- |', count($headers)) . "\n";
    foreach (array_slice($rows, 0, MAX_ROWS) as $row) {
        $out .= '| ' . implode(' | ', $row) . " |\n";
    }

    return $out . more(count($rows), MAX_ROWS);
}

function more(int $count, int $shown): string
{
    return $count > $shown ? "\n…and " . ($count - $shown) . " more. See the job log.\n" : '';
}

/**
 * Plain text for a table cell or a list item: one line, trimmed, Markdown and HTML escaped.
 */
function cell(string $text, int $max = MAX_CELL): string
{
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if (preg_match('/^.{' . ($max - 1) . '}(?=.{2})/su', $text, $match)) {
        $text = rtrim($match[0]) . '…';
    }

    return strtr($text, [
        '\\' => '\\\\', '`' => '\\`', '*' => '\\*', '_' => '\\_', '~' => '\\~',
        '[' => '\\[', ']' => '\\]', '|' => '\\|', '<' => '&lt;', '>' => '&gt;', '&' => '&amp;',
    ]);
}

/**
 * Inline code, safe inside a table cell.
 */
function code(string $text): string
{
    $text = trim(str_replace(['`', "\n", "\r"], ["'", ' ', ''], $text));

    return $text === '' ? '' : '`' . str_replace('|', '\\|', $text) . '`';
}

function codeBlock(string $text): string
{
    $lines = explode("\n", $text);
    if (count($lines) > MAX_DETAIL_LINES) {
        $lines = array_slice($lines, 0, MAX_DETAIL_LINES);
        $lines[] = '…';
    }
    // The fence must be longer than any run of backticks inside the block.
    preg_match_all('/`+/', $text, $runs);
    $fence = str_repeat('`', max(3, ...array_map('strlen', $runs[0] ?: [''])) + 1);

    return $fence . "\n" . implode("\n", $lines) . "\n" . $fence . "\n";
}

function plural(int $count, string $word): string
{
    return $count . ' ' . $word . ($count === 1 ? '' : 's');
}

function percent(int $covered, int $total): string
{
    return $total > 0 ? sprintf('%.1f%%', floor($covered / $total * 1000) / 10) : 'n/a';
}

function duration(float $seconds): string
{
    if ($seconds < 60) {
        return sprintf('%.1fs', $seconds);
    }
    $seconds = (int)round($seconds);
    if ($seconds < 3600) {
        return sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }

    return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
}

/**
 * A path relative to the repository root ($GITHUB_WORKSPACE, or the current directory).
 */
function relativePath(string $path): string
{
    $root = rtrim((string)(getenv('GITHUB_WORKSPACE') ?: getcwd()), '/') . '/';

    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}

function loadXml(string $file): ?SimpleXMLElement
{
    if ($file === '' || !is_file($file) || filesize($file) === 0) {
        return null;
    }
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $xml === false ? null : $xml;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    echo summary(array_slice($argv, 1));
}
