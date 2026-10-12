<?php

namespace Rocketeers\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Rocketeers\Laravel\Support\StackTrace;
use RocketeersStackTraceFixture\AppDiver;
use RocketeersStackTraceFixture\Runner;
use RocketeersStackTraceFixture\Thrower;
use RocketeersStackTraceFixture\VendorDiver;
use RuntimeException;

class StackTraceTest extends TestCase
{
    protected string $basePath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        @mkdir(self::fixtureRoot());
    }

    public static function tearDownAfterClass(): void
    {
        self::removeDirectory(self::fixtureRoot());

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = self::fixtureRoot();
    }

    protected static function fixtureRoot(): string
    {
        return sys_get_temp_dir().'/rocketeers-stack-trace-'.getmypid();
    }

    public function test_the_throw_site_is_the_first_frame_with_the_function_it_was_thrown_in()
    {
        $frames = $this->throwThroughVendor();

        $this->assertSame('app/Thrower.php', $frames[0]['file']);
        $this->assertSame(51, $frames[0]['line']);
        $this->assertSame(Thrower::class, $frames[0]['class']);
        $this->assertSame('fail', $frames[0]['function']);
        $this->assertSame('->', $frames[0]['type']);
        $this->assertSame("        throw new \\RuntimeException('boom');", $frames[0]['code_snippet'][51]);
        $this->assertSame('[top]', end($frames)['function']);
    }

    public function test_every_frame_carries_the_line_of_its_own_function()
    {
        $frames = $this->throwThroughVendor();
        $runner = $this->frameOf($frames, 'run');

        $this->assertSame('vendor/acme/runner/Runner.php', $runner['file']);
        $this->assertSame(7, $runner['line']);
        $this->assertSame(Runner::class, $runner['class']);
        $this->assertSame('        return $callback();', $runner['code_snippet'][7]);
    }

    public function test_it_marks_frames_under_vendor_as_vendor_frames()
    {
        $frames = $this->throwThroughVendor();

        $this->assertTrue($frames[0]['application_frame']);
        $this->assertFalse($this->frameOf($frames, 'run')['application_frame']);
        $this->assertSame([
            'file', 'line', 'class', 'function', 'type', 'application_frame', 'code_snippet',
        ], array_keys($frames[0]));
    }

    public function test_it_keeps_a_file_outside_the_base_path_absolute()
    {
        $frames = $this->throwThroughVendor();
        $caller = $frames[1];

        $this->assertSame(__FILE__, $caller['file']);
        $this->assertTrue($caller['application_frame']);
    }

    public function test_the_snippet_is_fourteen_lines_either_side_of_the_line()
    {
        $file = $this->numberedFixture('app/Numbers.php', 100);

        $snippet = StackTrace::frames($this->exceptionAt($file, 50), $this->basePath)[0]['code_snippet'];

        $this->assertSame(range(36, 64), array_keys($snippet));
        $this->assertSame('$line50 = 50;', $snippet[50]);
    }

    public function test_the_snippet_is_clamped_to_the_start_of_the_file()
    {
        $file = $this->numberedFixture('app/Numbers.php', 100);

        $snippet = StackTrace::frames($this->exceptionAt($file, 3), $this->basePath)[0]['code_snippet'];

        $this->assertSame(range(1, 29), array_keys($snippet));
    }

    public function test_the_snippet_is_clamped_to_the_end_of_the_file()
    {
        $file = $this->numberedFixture('app/Numbers.php', 100);

        $snippet = StackTrace::frames($this->exceptionAt($file, 98), $this->basePath)[0]['code_snippet'];

        $this->assertSame(range(72, 100), array_keys($snippet));
    }

    public function test_the_snippet_of_a_short_file_is_the_whole_file()
    {
        $file = $this->numberedFixture('app/Numbers.php', 5);

        $snippet = StackTrace::frames($this->exceptionAt($file, 4), $this->basePath)[0]['code_snippet'];

        $this->assertSame(range(1, 5), array_keys($snippet));
    }

    public function test_a_line_is_trimmed_and_capped_at_250_characters()
    {
        $file = $this->fixture('app/Long.php', ['<?php', '$long = \''.str_repeat('x', 300).'\';', '$short = 1;     ']);

        $snippet = StackTrace::frames($this->exceptionAt($file, 2), $this->basePath)[0]['code_snippet'];

        $this->assertSame(250, mb_strlen($snippet[2]));
        $this->assertSame('$short = 1;', $snippet[3]);
    }

    public function test_the_snippet_is_null_when_the_file_cannot_be_read()
    {
        $frame = StackTrace::frames($this->exceptionAt('/nowhere/Missing.php', 3), $this->basePath)[0];

        $this->assertSame('/nowhere/Missing.php', $frame['file']);
        $this->assertSame(3, $frame['line']);
        $this->assertNull($frame['code_snippet']);
    }

    public function test_the_snippet_is_null_when_the_line_is_past_the_end_of_the_file()
    {
        $file = $this->numberedFixture('app/Numbers.php', 10);

        $this->assertNull(StackTrace::frames($this->exceptionAt($file, 11), $this->basePath)[0]['code_snippet']);
    }

    public function test_it_drops_the_code_of_vendor_frames_first_when_the_trace_is_too_large()
    {
        $this->longLinesFixture('vendor/acme/diver/VendorDiver.php', 'VendorDiver');

        try {
            (new VendorDiver)->dive(120);
        } catch (RuntimeException $exception) {
            $frames = StackTrace::frames($exception, $this->basePath);
        }

        $vendorFrames = array_filter($frames, fn (array $frame) => ! $frame['application_frame']);

        $this->assertCount(count($exception->getTrace()) + 1, $frames);
        $this->assertGreaterThan(120, count($vendorFrames));
        $this->assertSame([null], array_values(array_unique(array_column($vendorFrames, 'code_snippet'), SORT_REGULAR)));
        $this->assertNotNull($this->frameOf($frames, __FUNCTION__)['code_snippet']);
        $this->assertLessThanOrEqual(StackTrace::MAX_BYTES, strlen(json_encode($frames)));
    }

    public function test_it_keeps_the_first_frames_when_the_application_frames_alone_are_too_large()
    {
        $this->longLinesFixture('app/AppDiver.php', 'AppDiver');

        try {
            (new AppDiver)->dive(120);
        } catch (RuntimeException $exception) {
            $frames = StackTrace::frames($exception, $this->basePath);
        }

        $this->assertCount(StackTrace::MAX_FRAMES, $frames);
        $this->assertSame('app/AppDiver.php', $frames[0]['file']);
        $this->assertNotNull($frames[0]['code_snippet']);
    }

    protected function throwThroughVendor(): array
    {
        $this->fixture('app/Thrower.php', array_merge(
            ['<?php', '', 'namespace RocketeersStackTraceFixture;', '', 'class Thrower', '{'],
            array_map(fn (int $line) => "    const LINE_{$line} = {$line};", range(7, 47)),
            ['', '    public function fail()', '    {', "        throw new \\RuntimeException('boom');", '    }', '}'],
        ));

        $this->fixture('vendor/acme/runner/Runner.php', [
            '<?php',
            'namespace RocketeersStackTraceFixture;',
            'class Runner',
            '{',
            '    public function run(callable $callback)',
            '    {',
            '        return $callback();',
            '    }',
            '}',
        ]);

        require_once $this->basePath.'/app/Thrower.php';
        require_once $this->basePath.'/vendor/acme/runner/Runner.php';

        try {
            (new Runner)->run(fn () => (new Thrower)->fail());
        } catch (RuntimeException $exception) {
            return StackTrace::frames($exception, $this->basePath);
        }

        $this->fail('The fixture did not throw.');
    }

    protected function longLinesFixture(string $path, string $class): void
    {
        $this->fixture($path, array_merge(
            ['<?php', 'namespace RocketeersStackTraceFixture;', "class {$class}", '{'],
            array_map(fn (int $line) => "    const PAD_{$line} = '".str_repeat('x', 260)."';", range(5, 20)),
            [
                '    public function dive(int $depth)',
                '    {',
                '        if ($depth === 0) {',
                "            throw new \\RuntimeException('deep');",
                '        }',
                '        return $this->dive($depth - 1);',
                '    }',
            ],
            array_map(fn (int $line) => "    const PAD_{$line} = '".str_repeat('x', 260)."';", range(28, 45)),
            ['}'],
        ));

        require_once $this->basePath.'/'.$path;
    }

    protected function frameOf(array $frames, string $function): array
    {
        foreach ($frames as $frame) {
            if ($frame['function'] === $function) {
                return $frame;
            }
        }

        $this->fail("No frame for {$function}.");
    }

    protected function exceptionAt(string $file, int $line): RuntimeException
    {
        return new class('boom', $file, $line) extends RuntimeException
        {
            public function __construct(string $message, string $file, int $line)
            {
                parent::__construct($message);

                $this->file = $file;
                $this->line = $line;
            }
        };
    }

    protected function numberedFixture(string $path, int $lines): string
    {
        return $this->fixture($path, array_map(fn (int $line) => "\$line{$line} = {$line};", range(1, $lines)));
    }

    protected function fixture(string $path, array $lines): string
    {
        $file = $this->basePath.'/'.$path;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, implode("\n", $lines)."\n");

        return $file;
    }

    protected static function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }

        @rmdir($directory);
    }
}
