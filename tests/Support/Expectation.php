<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;

/**
 * Shared exception-expectation helpers for test suites.
 *
 * The canonical way to assert "this call must throw" from a try/catch
 * block. Using it instead of a hand-rolled try/fail/catch removes the
 * classic swallow bug: `self::fail()` throws AssertionFailedError, which
 * EXTENDS RuntimeException — a broad catch around a fail()-guarded call
 * swallows the fail and mis-asserts against the fail message. Here the
 * fail() lives OUTSIDE the catch, so the bug is impossible by
 * construction.
 */
final class Expectation
{
    /**
     * Run a callable expected to throw and return the caught exception.
     *
     * Fails the test when nothing is thrown. The exception is returned
     * (not asserted internally) so callers can make additional
     * assertions on code, message or accessors.
     *
     * @template TException of \Throwable
     *
     * @param  callable(): mixed    $callback  The call expected to throw.
     * @param  class-string<TException> $exception The expected exception class.
     * @return TException The caught exception.
     */
    public static function throws(callable $callback, string $exception): \Throwable
    {
        try {
            $callback();
        } catch (AssertionFailedError $e) {
            throw $e; // an assertion inside the callback is a test failure, not the subject.
        } catch (\Throwable $e) {
            if (!$e instanceof $exception) {
                self::failWrongClass($exception, $e);
            }

            return $e;
        }

        self::failNotThrown($exception);
    }

    /**
     * Run a callable expected to throw and assert the exception's class
     * AND that its message contains the expected fragment.
     *
     * @template TException of \Throwable
     *
     * @param  callable(): mixed        $callback The call expected to throw.
     * @param  class-string<TException> $exception The expected exception class.
     * @param  string                   $message   A fragment the message must contain.
     * @return TException The caught exception, for further assertions.
     */
    public static function throwsWithMessage(
        callable $callback,
        string $exception,
        string $message,
    ): \Throwable {
        $caught = self::throws($callback, $exception);

        \PHPUnit\Framework\Assert::assertStringContainsString(
            $message,
            $caught->getMessage(),
        );

        return $caught;
    }

    /**
     * Fail the test because nothing was thrown.
     *
     * @param class-string<\Throwable> $exception The expected exception class.
     * @return never
     */
    private static function failNotThrown(string $exception): never
    {
        \PHPUnit\Framework\Assert::fail(
            "Expected {$exception} was not thrown.",
        );
    }

    /**
     * Fail the test because a different exception class was thrown.
     *
     * @param class-string<\Throwable> $exception The expected exception class.
     * @param \Throwable               $thrown    The exception that was thrown.
     * @return never
     */
    private static function failWrongClass(string $exception, \Throwable $thrown): never
    {
        \PHPUnit\Framework\Assert::fail(
            sprintf(
                'Expected %s but %s was thrown: %s',
                $exception,
                $thrown::class,
                $thrown->getMessage(),
            ),
        );
    }
}
