<?php

declare(strict_types=1);

use App\Domains\OpenTelemetry\Support\StackTraceParser;

covers(StackTraceParser::class);

it('reads the innermost frame of a stack trace', function (string $trace, array $frame) {
    expect((new StackTraceParser())->parse($trace)[0] ?? null)->toBe($frame);
})->with([
    'PHP' => ["#0 /app/src/Foo.php(12): App\\Foo->bar()\n#1 {main}", ['file' => '/app/src/Foo.php', 'line' => 12, 'function' => 'App\\Foo->bar()', 'in_app' => true]],
    'JavaScript' => ["Error: boom\n    at bar (/app/foo.js:12:5)", ['file' => '/app/foo.js', 'line' => 12, 'column' => 5, 'function' => 'bar', 'in_app' => true]],
    'JavaScript without a function' => ["Error: boom\n    at /app/node_modules/x/index.js:3:9", ['file' => '/app/node_modules/x/index.js', 'line' => 3, 'column' => 9, 'in_app' => false]],
    'Java' => ["java.lang.IllegalStateException: boom\n\tat com.example.Foo.bar(Foo.java:12)", ['file' => 'Foo.java', 'line' => 12, 'function' => 'com.example.Foo.bar', 'in_app' => true]],
    'Java library frame' => ["\tat java.base/java.lang.Thread.run(Thread.java:840)", ['file' => 'Thread.java', 'line' => 840, 'function' => 'java.base/java.lang.Thread.run', 'in_app' => false]],
    '.NET' => ['   at Shop.Orders.Total() in /src/Orders.cs:line 12', ['file' => '/src/Orders.cs', 'line' => 12, 'function' => 'Shop.Orders.Total()', 'in_app' => true]],
    'Ruby' => ["/app/orders.rb:12:in `total'", ['file' => '/app/orders.rb', 'line' => 12, 'function' => 'total', 'in_app' => true]],
    'Python, innermost last' => ["Traceback (most recent call last):\n  File \"/app/a.py\", line 3, in main\n    b()\n  File \"/usr/lib/python3/site-packages/b.py\", line 9, in b", ['file' => '/usr/lib/python3/site-packages/b.py', 'line' => 9, 'function' => 'b', 'in_app' => false]],
]);

it('reads no frames from a trace in a format it does not know', function () {
    expect((new StackTraceParser())->parse("goroutine 1 [running]:\nmain.main()"))->toBe([]);
});
