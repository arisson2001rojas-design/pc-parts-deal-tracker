<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

class BrowserDiscoveryConcurrencyTest extends TestCase
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

    public function test_two_concurrent_first_discoveries_have_one_logical_effect(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The PCNTL extension is required for the real concurrency regression.');
        }

        Queue::fake();
        User::factory()->create();
        Store::factory()->create(['name' => 'Newegg US']);

        $barrier = tempnam(sys_get_temp_dir(), 'pricebuddy-radar-start-');
        $firstResult = tempnam(sys_get_temp_dir(), 'pricebuddy-radar-result-');
        $secondResult = tempnam(sys_get_temp_dir(), 'pricebuddy-radar-result-');
        if (! is_string($barrier) || ! is_string($firstResult) || ! is_string($secondResult)) {
            $this->fail('Unable to allocate concurrency test files.');
        }

        $resultFiles = [$firstResult, $secondResult];
        unlink($barrier);

        /** @var array<int, int> $pids */
        $pids = [];

        try {
            foreach ($resultFiles as $resultFile) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid, 'Unable to fork the discovery test process.');

                if ($pid === 0) {
                    $this->runDiscoveryProcess($barrier, $resultFile);
                }

                $pids[] = $pid;
            }

            touch($barrier);

            foreach ($pids as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $details = file_get_contents($resultFiles[$index]) ?: 'No child-process result was written.';
                $this->assertTrue(pcntl_wifexited($status), $details);
                $this->assertSame(0, pcntl_wexitstatus($status), $details);
            }

            $results = array_map(
                static fn (string $path): array => json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR),
                $resultFiles,
            );

            $this->assertSame($results[0]['offer_id'], $results[1]['offer_id']);
            $this->assertSame($results[0]['product_id'], $results[1]['product_id']);
            $this->assertSame($results[0]['pc_part_id'], $results[1]['pc_part_id']);
            $this->assertDatabaseCount('deal_searches', 1);
            $this->assertDatabaseCount('deal_offers', 1);
            $this->assertDatabaseCount('deal_offer_prices', 1);
            $this->assertDatabaseCount('pc_parts', 1);
            $this->assertDatabaseCount('products', 1);
            $this->assertDatabaseCount('urls', 1);
            $this->assertDatabaseCount('prices', 1);
        } finally {
            if (is_string($barrier) && file_exists($barrier)) {
                unlink($barrier);
            }

            foreach ($resultFiles as $resultFile) {
                if (is_string($resultFile) && file_exists($resultFile)) {
                    unlink($resultFile);
                }
            }
        }
    }

    private function runDiscoveryProcess(string $barrier, string $resultFile): never
    {
        try {
            DB::purge();

            $deadline = microtime(true) + 5;
            while (! file_exists($barrier)) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Timed out waiting for the concurrency barrier.');
                }

                clearstatcache(true, $barrier);
                usleep(1000);
            }

            DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
            $response = $this->withHeader('X-PriceBuddy-Companion', '1')
                ->postJson(route('api.browser-discoveries'), $this->payload());
            file_put_contents($resultFile, json_encode([
                'status' => $response->status(),
                'offer_id' => $response->json('data.offer_id'),
                'product_id' => $response->json('data.product_id'),
                'pc_part_id' => $response->json('data.pc_part_id'),
                'body' => $response->json(),
            ], JSON_THROW_ON_ERROR));

            exit($response->status() === 201 ? 0 : 1);
        } catch (Throwable $exception) {
            file_put_contents($resultFile, json_encode([
                'error' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            exit(1);
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'page_url' => 'https://www.newegg.com/p/9SIC3U3KN44182',
            'title' => 'AMD Ryzen 5 5600 Desktop Processor',
            'image_url' => 'https://c1.neweggimages.com/ProductImage.jpg',
            'availability' => 'in_stock',
            'seller' => 'SenyTech Global',
            'manufacturer' => 'AMD',
            'part_number' => '100-000000927',
            'candidates' => [[
                'price' => 129.99,
                'currency' => 'USD',
                'source' => 'site_specific',
                'confidence' => 0.96,
            ]],
        ];
    }
}
