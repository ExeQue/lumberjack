<?php

declare(strict_types=1);

namespace App\Domains\OpenTelemetry\Support;

/**
 * Reads the frames out of an `exception.stacktrace` text, as the common runtimes print it.
 * Lines it does not recognize are skipped; a trace it reads nothing from gives no frames.
 */
class StackTraceParser
{
    /**
     * Innermost frame first. Python prints its innermost frame last, so its frames are turned around.
     */
    private const array PATTERNS = [
        // PHP: #0 /app/src/Foo.php(12): App\Foo->bar()
        '~^#\d+\s+(?<file>[^(]+)\((?<line>\d+)\):\s*(?<function>.+)$~',
        // .NET: at App.Foo.Bar() in /app/Foo.cs:line 12
        '~^at\s+(?<function>.+?)\s+in\s+(?<file>.+):line\s+(?<line>\d+)$~',
        // JavaScript: at bar (/app/foo.js:12:5), or at /app/foo.js:12:5
        '~^at\s+(?:(?<function>.+?)\s+\()?(?<file>[^()\s]+):(?<line>\d+):(?<column>\d+)\)?$~',
        // Java and Kotlin: at com.example.Foo.bar(Foo.java:12)
        '~^at\s+(?<function>[^\s(]+)\((?<file>[^:()]+):(?<line>\d+)\)$~',
        // Ruby: /app/foo.rb:12:in `bar'
        '~^(?<file>[^:\s]+):(?<line>\d+):in\s+[`\'](?<function>[^\'`]+)\'$~',
        // Python: File "/app/foo.py", line 12, in bar
        '~^File\s+"(?<file>[^"]+)",\s+line\s+(?<line>\d+)(?:,\s+in\s+(?<function>.+))?$~',
    ];

    /** Paths and functions of libraries and the runtime itself. */
    private const string VENDOR_FILE = '~(^|[/\\\\])(vendor|node_modules|site-packages|dist-packages)[/\\\\]|^node:|^internal/|<anonymous>~';

    private const string VENDOR_FUNCTION = '~^(java|javax|jdk|sun|kotlin|System|Microsoft)\.~';

    /**
     * @return list<array{file: string, line: int, column?: int, function?: string, in_app: bool}>
     */
    public function parse(string $trace): array
    {
        $frames = [];
        $python = false;

        foreach (preg_split('~\R~', $trace) ?: [] as $line) {
            $line = trim($line);

            foreach (self::PATTERNS as $pattern) {
                if (preg_match($pattern, $line, $match) !== 1) {
                    continue;
                }

                $python = $python || str_starts_with($line, 'File ');
                $function = trim($match['function'] ?? '');
                $frame = ['file' => trim($match['file']), 'line' => (int) $match['line']];

                if (($match['column'] ?? '') !== '') {
                    $frame['column'] = (int) $match['column'];
                }

                if ($function !== '') {
                    $frame['function'] = $function;
                }

                $frame['in_app'] = preg_match(self::VENDOR_FILE, $frame['file']) !== 1 && preg_match(self::VENDOR_FUNCTION, $function) !== 1;
                $frames[] = $frame;

                break;
            }
        }

        return $python ? array_reverse($frames) : $frames;
    }
}
