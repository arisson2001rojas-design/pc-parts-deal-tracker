<?php

namespace Tests\Feature;

use App\Services\HardwareEvidenceNormalizer;
use App\Services\HardwareIdentityIngestionService;
use App\Services\RetailerProductUrl;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class HardwareIdentityConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /** @var array<int, string> */
    protected array $exceptTables = ['settings'];

    protected function tearDown(): void
    {
        try {
            if (isset($this->app)) {
                $this->truncateDatabaseTables();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_concurrent_cross_retailer_ingestion_with_overlapping_mpns_has_one_identity(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The PCNTL extension is required for the identity concurrency regression.');
        }

        $barrier = tempnam(sys_get_temp_dir(), 'pricebuddy-identity-start-');
        $firstResult = tempnam(sys_get_temp_dir(), 'pricebuddy-identity-result-');
        $secondResult = tempnam(sys_get_temp_dir(), 'pricebuddy-identity-result-');
        if (! is_string($barrier) || ! is_string($firstResult) || ! is_string($secondResult)) {
            $this->fail('Unable to allocate identity concurrency files.');
        }
        unlink($barrier);
        $cases = [
            [$firstResult, 'https://www.amazon.com/dp/B089C3TZL9', ['MZ-77Q8T0B/AM'], '8TB'],
            [$secondResult, 'https://www.newegg.com/p/N82E16820147784', ['MZ-77Q8T0B/AM', 'MZ-77Q8T0BW'], '8TB'],
        ];
        $pids = [];

        try {
            foreach ($cases as [$resultFile, $url, $mpns, $capacity]) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid, 'Unable to fork the identity test process.');
                if ($pid === 0) {
                    $this->runIngestionProcess($barrier, $resultFile, $url, $mpns, $capacity);
                }
                $pids[] = $pid;
            }
            touch($barrier);

            foreach ($pids as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $details = file_get_contents($cases[$index][0]) ?: 'No child-process result was written.';
                $this->assertTrue(pcntl_wifexited($status), $details);
                $this->assertSame(0, pcntl_wexitstatus($status), $details);
            }

            $results = array_map(
                static fn (array $case): array => json_decode((string) file_get_contents($case[0]), true, flags: JSON_THROW_ON_ERROR),
                $cases,
            );
            $this->assertSame($results[0]['identity_id'], $results[1]['identity_id']);
            $this->assertSame('verified', $results[0]['state']);
            $this->assertSame('verified', $results[1]['state']);
            $this->assertDatabaseCount('hardware_identities', 1);
            $this->assertDatabaseCount('retailer_listings', 2);
            $this->assertDatabaseCount('hardware_identity_claims', 2);
        } finally {
            if (file_exists($barrier)) {
                unlink($barrier);
            }
            foreach ([$firstResult, $secondResult] as $resultFile) {
                if (file_exists($resultFile)) {
                    unlink($resultFile);
                }
            }
        }
    }

    public function test_concurrent_same_authoritative_identity_with_conflicting_capacity_re_resolves_the_loser(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The PCNTL extension is required for the identity concurrency regression.');
        }

        $barrier = tempnam(sys_get_temp_dir(), 'pricebuddy-identity-start-');
        $firstResult = tempnam(sys_get_temp_dir(), 'pricebuddy-identity-result-');
        $secondResult = tempnam(sys_get_temp_dir(), 'pricebuddy-identity-result-');
        if (! is_string($barrier) || ! is_string($firstResult) || ! is_string($secondResult)) {
            $this->fail('Unable to allocate identity concurrency files.');
        }
        unlink($barrier);
        $cases = [
            [$firstResult, 'https://www.amazon.com/dp/B089C3TZL9', ['MZ-77Q8T0B/AM'], '8TB'],
            [$secondResult, 'https://www.newegg.com/p/N82E16820147784', ['MZ-77Q8T0B/AM'], '1TB'],
        ];
        $pids = [];

        try {
            foreach ($cases as [$resultFile, $url, $mpns, $capacity]) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid, 'Unable to fork the identity test process.');
                if ($pid === 0) {
                    $this->runIngestionProcess($barrier, $resultFile, $url, $mpns, $capacity);
                }
                $pids[] = $pid;
            }
            touch($barrier);

            foreach ($pids as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $details = file_get_contents($cases[$index][0]) ?: 'No child-process result was written.';
                $this->assertTrue(pcntl_wifexited($status), $details);
                $this->assertSame(0, pcntl_wexitstatus($status), $details);
            }

            $results = array_map(
                static fn (array $case): array => json_decode((string) file_get_contents($case[0]), true, flags: JSON_THROW_ON_ERROR),
                $cases,
            );
            $states = array_column($results, 'state');
            sort($states);
            $this->assertSame(['conflicting', 'verified'], $states);
            $this->assertCount(1, array_filter(array_column($results, 'identity_id')));
            $this->assertDatabaseCount('hardware_identities', 1);
            $this->assertDatabaseCount('retailer_listings', 2);
            $this->assertDatabaseCount('hardware_identity_claims', 1);
            $this->assertSame(1, DB::table('retailer_listings')->whereNotNull('hardware_identity_id')->count());
            $this->assertSame(1, DB::table('retailer_listings')
                ->where('resolution_state', 'conflicting')
                ->whereNull('hardware_identity_id')
                ->count());
        } finally {
            if (file_exists($barrier)) {
                unlink($barrier);
            }
            foreach ([$firstResult, $secondResult] as $resultFile) {
                if (file_exists($resultFile)) {
                    unlink($resultFile);
                }
            }
        }
    }

    /** @param list<string> $mpns */
    private function runIngestionProcess(
        string $barrier,
        string $resultFile,
        string $url,
        array $mpns,
        string $capacity,
    ): never {
        try {
            DB::purge();
            $deadline = microtime(true) + 5;
            while (! file_exists($barrier)) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Timed out waiting for the identity concurrency barrier.');
                }
                clearstatcache(true, $barrier);
                usleep(1000);
            }
            DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
            $evidence = app(HardwareEvidenceNormalizer::class)->fromArray([
                'component_type' => 'ssd',
                'manufacturer' => 'Samsung',
                'model' => '870 QVO',
                'mpns' => $mpns,
                'capacity' => $capacity,
            ]);
            $result = app(HardwareIdentityIngestionService::class)->ingest(
                app(RetailerProductUrl::class)->identify($url),
                $evidence,
            );
            file_put_contents($resultFile, json_encode([
                'identity_id' => $result->identity?->getKey(),
                'state' => $result->resolution->state->value,
            ], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (Throwable $exception) {
            file_put_contents($resultFile, json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            exit(1);
        }
    }
}
