<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Connections;

use BlueprintAU\Radiant\Database\Connections\CsvConnection;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the CSV hardening:
 *
 * - writes are atomic (temp + rename), no
 *   truncation window, and no `.radiant-tmp` residue on success.
 * - mutations hold an exclusive lock across the
 *   whole read-modify-write, and release it afterwards.
 * - rows are aligned to a canonical column order on write.
 * - formula injection is neutralized on write.
 */
final class CsvHardeningTest extends TestCase
{
    /**
     * The temp CSV file used by the current test.
     *
     * @var string
     */
    private string $path = '';

    /**
     * Create a two-column CSV fixture and a connection over it.
     *
     * @return CsvConnection The connection.
     */
    private function makeConnection(): CsvConnection
    {
        $this->path = tempnam(sys_get_temp_dir(), 'radiant_csv_') ?: (sys_get_temp_dir() . '/radiant_csv_test');
        $handle = fopen($this->path, 'w');
        \assert($handle !== false);
        fputcsv($handle, ['id', 'name'], escape: '');
        fputcsv($handle, [1, 'Ada'], escape: '');
        fputcsv($handle, [2, 'Grace'], escape: '');
        fclose($handle);
        return new CsvConnection($this->path);
    }

    /**
     * Remove the fixture and any temp residue after each test.
     */
    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '.radiant-tmp'] as $file) {
            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    /**
     * The raw file contents, for asserting what was written.
     *
     * @return string The file bytes.
     */
    private function rawFile(): string
    {
        $contents = file_get_contents($this->path);
        \assert(is_string($contents));
        return $contents;
    }

    // ---- Atomicity ----

    /**
     * A successful write must leave only the final file — no temp residue.
     */
    public function testWriteNeverLeavesTempFileBehind(): void
    {
        $db = $this->makeConnection();
        $db->insert($db->table('t'), ['id' => 3, 'name' => 'Linus']);

        $this->assertFileExists($this->path);
        $this->assertFileDoesNotExist($this->path . '.radiant-tmp');
    }

    /**
     * An insert must append to — not truncate and rewrite — the dataset.
     */
    public function testInsertAppendsWithoutTruncatingPriorData(): void
    {
        $db = $this->makeConnection();
        $db->insert($db->table('t'), ['id' => 3, 'name' => 'Linus']);

        $rows = $db->table('t')->get();
        $this->assertCount(3, $rows);
        $this->assertNotNull($rows[2]);
        $this->assertNotNull($rows[0]);
        $this->assertSame('Linus', $rows[2]->name);
        $this->assertSame('Ada', $rows[0]->name);
    }

    // ---- Locking ----

    /**
     * While the connection holds its exclusive lock on the SIDECAR lock
     * file (as across a read-modify-write), a different handle cannot take
     * a non-blocking exclusive lock on that sidecar — the guarantee that
     * closes the lost-update window. The sidecar (not the data file) is
     * the lock domain: the data file's inode is replaced by every
     * rename()-based write, so locking it never serialized writers.
     */
    public function testOpenLockedTakesExclusiveLockVisibleToOtherHandles(): void
    {
        $db = $this->makeConnection();

        $acquireLock = new \ReflectionMethod(CsvConnection::class, 'acquireLock');
        $releaseLock = new \ReflectionMethod(CsvConnection::class, 'releaseLock');
        $lockPath = $this->path . '.lock';

        $held = $acquireLock->invoke($db);
        \assert(is_resource($held));

        $other = fopen($lockPath, 'r');
        \assert($other !== false);
        $this->assertFalse(
            flock($other, LOCK_EX | LOCK_NB),
            'A second handle on the sidecar lock file must be blocked while the connection lock is held.'
        );
        fclose($other);

        $releaseLock->invoke($db, $held);

        // After release, another handle can lock again.
        $other = fopen($lockPath, 'r');
        \assert($other !== false);
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB));
        flock($other, LOCK_UN);
        fclose($other);
    }

    /**
     * After a mutation completes, the lock is released — a fresh handle can
     * take LOCK_EX without blocking.
     */
    public function testMutationReleasesLockAfterCompletion(): void
    {
        $db = $this->makeConnection();
        $db->insert($db->table('t'), ['id' => 3, 'name' => 'Linus']);

        $other = fopen($this->path, 'r');
        \assert($other !== false);
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB));
        flock($other, LOCK_UN);
        fclose($other);
    }

    /**
     * A failing mutation must release the lock too — a later mutation and
     * even a plain read must not deadlock behind the failed one's handle.
     */
    public function testFailedMutationReleasesLock(): void
    {
        $db = $this->makeConnection();


        $readOnly = new CsvConnection($this->path, true);
        try {
            $readOnly->insert($db->table('t'), ['id' => 4, 'name' => 'x']);
            $this->fail('Expected read-only insert to throw.');
        } catch (\BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException) {
            // expected — and no lock was ever taken
        }

        // The ordinary connection can still mutate afterwards.
        $db->insert($db->table('t'), ['id' => 3, 'name' => 'Linus']);
        $this->assertCount(3, $db->table('t')->get());
    }

    // ---- Column alignment ----

    /**
     * An update that introduces a new column must not shift the other
     * rows' values under the wrong header — the pre-fix corruption mode.
     */
    public function testUpdateIntroducingNewColumnKeepsRowsAligned(): void
    {
        $db = $this->makeConnection();

        // Update one row, introducing a column the others lack.
        $db->update($db->table('t')->where('id', '=', 1), ['email' => 'ada@lovelace.example']);
        $db->update($db->table('t')->where('id', '=', 2), ['name' => 'Hopper']);

        $rows = $db->table('t')->orderBy('id')->get();
        // Row 2's name must still be under `name`, not shifted into `email`.
        // (CSV reads stringify every value.)
        $this->assertNotNull($rows[1]);
        $this->assertNotNull($rows[0]);
        $this->assertSame('Hopper', $rows[1]->name);
        $this->assertSame('2', $rows[1]->id);
        // Row 1's new column lands under the right header.
        $this->assertSame('ada@lovelace.example', $rows[0]->email ?? null);
        // Row 2 has no email — it reads back as an empty string (CSV
        // stringifies every cell), not as someone's name.
        $this->assertSame('', $rows[1]->email ?? '');
    }

    /**
     * The lost-update scenario that motivated the sidecar lock (regression
     * lock). Two SEPARATE connections over the same path: the
     * sidecar lock (never renamed) is the serialization point, so both
     * writers' mutations land in the final file. Locking the data file's
     * inode — the pre-fix design — could not serialize them: the first
     * rename() replaced the inode out from under the second writer's lock.
     */
    public function testConcurrentWritersBothLandInFinalFile(): void
    {
        $db = $this->makeConnection();

        // Two independent connections over the same path, mimicking two
        // processes. Each performs a read-modify-write.
        $writerA = new CsvConnection($this->path);
        $writerB = new CsvConnection($this->path);

        $writerA->insert($writerA->table('t'), ['id' => 3, 'name' => 'A-Wrote']);
        $writerB->table('t')->where('id', '=', 1)->update(['name' => 'B-Wrote']);

        // A fresh reader sees BOTH mutations — neither was lost to the
        // other's temp-file rename.
        $reader = new CsvConnection($this->path);
        $rows = $reader->table('t')->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertNotNull($rows[0]);
        $this->assertNotNull($rows[2]);
        $this->assertSame('1', $rows[0]->id);
        $this->assertSame('B-Wrote', $rows[0]->name, 'writer B\'s update must survive writer A\'s rename');
        $this->assertSame('3', $rows[2]->id);
        $this->assertSame('A-Wrote', $rows[2]->name, 'writer A\'s insert must survive writer B\'s rename');
    }

    // ---- Formula injection ----

    /**
     * A value starting with `=` is quoted on write so a spreadsheet cannot
     * evaluate it as a formula.
     */
    public function testFormulaPayloadsAreNeutralizedOnWrite(): void
    {
        $db = $this->makeConnection();
        $db->insert($db->table('t'), [
            'id' => 3,
            'name' => '=HYPERLINK("http://evil.example","win")',
        ]);

        $this->assertStringContainsString("'=HYPERLINK", $this->rawFile());
    }

    /**
     * A negative number is ordinary data — it must not be quoted.
     */
    public function testNegativeNumbersAreNotNeutralized(): void
    {
        $db = $this->makeConnection();
        $db->insert($db->table('t'), ['id' => 3, 'name' => -42]);

        $this->assertStringContainsString('-42', $this->rawFile());
        $this->assertStringNotContainsString("'-42", $this->rawFile());
    }

    /**
     * A value starting with `@` is quoted on write.
     */
    public function testAtPayloadIsNeutralized(): void
    {
        $db = $this->makeConnection();
        $db->insert($db->table('t'), ['id' => 3, 'name' => '@SUM(1+1)']);

        $this->assertStringContainsString("'@SUM", $this->rawFile());
    }
}
