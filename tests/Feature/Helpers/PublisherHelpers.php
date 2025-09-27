<?php

use Ameax\LaravelChangeDetection\Contracts\Publisher as PublisherContract;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Publishers\LogPublisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

// Prevent function redeclaration errors when running multiple test files
if (!defined('PUBLISHERHELPERS_LOADED')) {
    define('PUBLISHERHELPERS_LOADED', true);

// ===== PUBLISHER CREATION HELPERS =====

function createWebhookPublisher(string $modelType, string $webhookUrl, ?string $name = null): Publisher
{
    return Publisher::create([
        'name' => $name ?? 'Webhook Publisher '.uniqid(),
        'model_type' => $modelType,
        'publisher_class' => MockWebhookPublisher::class,
        'status' => 'active',
        'config' => [
            'webhook_url' => $webhookUrl,
            'timeout' => 30,
            'retry_on_failure' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => 'test-key-'.uniqid(),
            ],
        ],
    ]);
}

function createLogPublisher(string $modelType, ?string $name = null): Publisher
{
    return Publisher::create([
        'name' => $name ?? 'Log Publisher '.uniqid(),
        'model_type' => $modelType,
        'publisher_class' => LogPublisher::class,
        'status' => 'active',
        'config' => [
            'log_channel' => 'testing',
            'log_level' => 'info',
            'include_hash_data' => true,
        ],
    ]);
}

function createPublisherWithCustomRetryIntervals(string $modelType, array $retryIntervals): Publisher
{
    return Publisher::create([
        'name' => 'Custom Retry Publisher '.uniqid(),
        'model_type' => $modelType,
        'publisher_class' => MockWebhookPublisher::class,
        'status' => 'active',
        'config' => [
            'webhook_url' => 'https://api.example.com/webhook',
            'retry_intervals' => $retryIntervals,
            'max_attempts' => count($retryIntervals),
        ],
    ]);
}

function createPublisherWithErrorStrategy(string $modelType, array $errorStrategies): Publisher
{
    return Publisher::create([
        'name' => 'Strategic Publisher '.uniqid(),
        'model_type' => $modelType,
        'publisher_class' => MockWebhookPublisher::class,
        'status' => 'active',
        'config' => array_merge([
            'webhook_url' => 'https://api.example.com/webhook',
        ], $errorStrategies),
    ]);
}

function createPublisherWithPriority(string $modelType, int $priority, string $name): Publisher
{
    return Publisher::create([
        'name' => $name,
        'model_type' => $modelType,
        'publisher_class' => MockWebhookPublisher::class,
        'status' => 'active',
        'config' => [
            'webhook_url' => 'https://api.example.com/webhook',
            'priority' => $priority,
        ],
    ]);
}

function createEnvironmentPublisher(string $modelType, string $environment, string $webhookUrl): Publisher
{
    return Publisher::create([
        'name' => ucfirst($environment).' Publisher',
        'model_type' => $modelType,
        'publisher_class' => MockWebhookPublisher::class,
        'status' => $environment === app()->environment() ? 'active' : 'inactive',
        'config' => [
            'webhook_url' => $webhookUrl,
            'environment' => $environment,
        ],
    ]);
}

// ===== PUBLISH RECORD HELPERS =====

function createPendingPublish(int $hashId, int $publisherId, array $overrides = []): Publish
{
    return Publish::create(array_merge([
        'hash_id' => $hashId,
        'publisher_id' => $publisherId,
        'status' => 'pending',
        'attempts' => 0,
    ], $overrides));
}

function createMultiplePendingPublishes(int $hashId, int $publisherId, int $count): array
{
    $publishes = [];

    for ($i = 0; $i < $count; $i++) {
        $publishes[] = createPendingPublish($hashId, $publisherId, [
            'metadata' => ['batch' => $i + 1],
        ]);
    }

    return $publishes;
}

function simulateValidationError(Publish $publish): void
{
    $publish->markAsDeferred(
        'Validation failed: Required field missing',
        400,
        'validation'
    );
}

function simulateInfrastructureError(Publish $publish): void
{
    $publish->markAsDeferred(
        'Connection timeout after 30 seconds',
        504,
        'infrastructure'
    );
}

function simulateDataError(Publish $publish): void
{
    $publish->markAsDeferred(
        'Related model not found',
        404,
        'data'
    );
}

// ===== ERROR HANDLING HELPERS =====

function determinePublisherErrorStrategy(Publisher $publisher, string $errorMessage): string
{
    $config = $publisher->config ?? [];

    // Check permission denied
    if (str_contains(strtolower($errorMessage), 'permission denied') &&
        ($config['stop_on_permission_denied'] ?? false)) {
        return 'stop_job';
    }

    // Check rate limit
    if (str_contains(strtolower($errorMessage), 'rate limit') &&
        ($config['defer_on_rate_limit'] ?? false)) {
        return 'defer_record';
    }

    // Check invalid data
    if (str_contains(strtolower($errorMessage), 'invalid data') &&
        ($config['fail_on_invalid_data'] ?? false)) {
        return 'fail_record';
    }

    // Default strategy
    return 'defer_record';
}

function extractHttpCodeFromError(string $errorMessage): ?int
{
    // Try various patterns to extract HTTP status code
    $patterns = [
        '/HTTP\/\d+\.\d+\s+(\d{3})/',                    // HTTP/1.1 404
        '/status code (\d{3})/',                         // status code 429
        '/`(\d{3})\s+\w+/',                             // `503 Service Unavailable`
        '/:\s*(\d{3})\s+[A-Z]/i',                       // : 400 Bad Request
        '/error:\s*(\d{3})/',                           // error: 401
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $errorMessage, $matches)) {
            return (int) $matches[1];
        }
    }

    return null;
}

// ===== BATCH PROCESSING HELPERS =====

function processBatchWithMixedResults(array $publishes): array
{
    $results = [
        'successful' => 0,
        'deferred' => 0,
        'failed' => 0,
    ];

    foreach ($publishes as $index => $publish) {
        if ($index < 6) {
            // Simulate success
            $publish->markAsPublished(['response' => 'Success', 'index' => $index]);
            $results['successful']++;
        } elseif ($index < 8) {
            // Simulate temporary failure
            $publish->markAsDeferred('Temporary error', 503, 'infrastructure');
            $results['deferred']++;
        } else {
            // Simulate permanent failure
            $publish->markAsFailed('Permanent error', 400, 'validation');
            $results['failed']++;
        }
    }

    return $results;
}

// ===== MOCK PUBLISHER IMPLEMENTATION =====

class MockWebhookPublisher implements PublisherContract
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function publish(Model $model, array $data): bool
    {
        // Simulate webhook call
        $response = Http::timeout($this->config['timeout'] ?? 30)
            ->withHeaders($this->config['headers'] ?? [])
            ->post($this->config['webhook_url'], $data);

        return $response->successful();
    }

    public function getData(Model $model): array
    {
        return [
            'id' => $model->getKey(),
            'type' => $model->getMorphClass(),
            'attributes' => $model->getAttributes(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function shouldPublish(Model $model): bool
    {
        // Check environment if configured
        if (isset($this->config['environment'])) {
            return app()->environment($this->config['environment']);
        }

        return true;
    }

    public function getMaxAttempts(): int
    {
        return $this->config['max_attempts'] ?? 3;
    }

    public function getBatchSize(): int
    {
        return $this->config['batch_size'] ?? 50;
    }

    public function getDelayMs(): int
    {
        return $this->config['delay_ms'] ?? 100;
    }

    public function getRetryIntervals(): array
    {
        return $this->config['retry_intervals'] ?? [30, 300, 21600];
    }

    public function handlePublishException(\Throwable $exception): string
    {
        $message = $exception->getMessage();

        // Stop job for critical errors
        if (str_contains($message, 'Permission denied') ||
            str_contains($message, 'Invalid API key')) {
            return 'stop_job';
        }

        // Fail record for data errors
        if (str_contains($message, 'Model not found') ||
            str_contains($message, 'Invalid data')) {
            return 'fail_record';
        }

        // Default to defer
        return 'defer_record';
    }

    public function getMaxValidationErrors(): int
    {
        return $this->config['max_validation_errors'] ?? 10;
    }

    public function getMaxInfrastructureErrors(): int
    {
        return $this->config['max_infrastructure_errors'] ?? 5;
    }

    public function attemptPublish(Model $model, Publish $publish): array
    {
        try {
            // Simulate HTTP request with potential failures
            $response = Http::fake()->timeout($this->config['timeout'] ?? 30)
                ->withHeaders($this->config['headers'] ?? [])
                ->post($this->config['webhook_url'], $this->getData($model));

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'code' => $response->status(),
                ];
            }

            return [
                'success' => false,
                'error' => "HTTP {$response->status()}: {$response->body()}",
                'code' => $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => extractHttpCodeFromError($e->getMessage()),
            ];
        }
    }
}

// ===== JOB PROCESSING HELPERS =====

class BulkPublishJob
{
    public $timeout = 600;

    public $tries = 3;

    public function shouldStopForValidationErrors(Publisher $publisher, int $errorCount): bool
    {
        $publisherClass = app($publisher->publisher_class, ['config' => $publisher->config ?? []]);

        if ($publisherClass instanceof PublisherContract) {
            return $errorCount >= $publisherClass->getMaxValidationErrors();
        }

        return $errorCount >= 10; // Default
    }

    public function shouldStopForInfrastructureErrors(Publisher $publisher, int $errorCount): bool
    {
        $publisherClass = app($publisher->publisher_class, ['config' => $publisher->config ?? []]);

        if ($publisherClass instanceof PublisherContract) {
            return $errorCount >= $publisherClass->getMaxInfrastructureErrors();
        }

        return $errorCount >= 5; // Default
    }
}

// ===== PERFORMANCE MONITORING HELPERS =====

function measurePublisherPerformance(Publisher $publisher, int $recordCount): array
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage(true);

    // Process records
    $publishes = Publish::where('publisher_id', $publisher->id)
        ->where('status', 'pending')
        ->limit($recordCount)
        ->get();

    $processed = 0;
    $errors = 0;

    foreach ($publishes as $publish) {
        try {
            $publish->markAsDispatched();
            // Simulate processing
            usleep(10000); // 10ms
            $publish->markAsPublished(['processed' => true]);
            $processed++;
        } catch (\Exception $e) {
            $publish->markAsDeferred($e->getMessage(), null, 'unknown');
            $errors++;
        }
    }

    return [
        'time' => microtime(true) - $startTime,
        'memory' => memory_get_peak_usage(true) - $startMemory,
        'processed' => $processed,
        'errors' => $errors,
        'rate' => $processed / (microtime(true) - $startTime),
    ];
}

// ===== CLEANUP HELPERS =====

function cleanupPublisherData(): void
{
    // Clean up publish records
    Publish::truncate();

    // Clean up test publishers (keep system publishers)
    Publisher::where('name', 'like', '%Test%')
        ->orWhere('name', 'like', '%Mock%')
        ->delete();

    // Clear any cached publisher data
    cache()->forget('active-publishers');
    cache()->forget('bulk-publish-job-lock');
}

} // End of PUBLISHERHELPERS_LOADED guard
