<?php

namespace GameMaster\Jobs;

use GameMaster\Boundary\AiTransport;
use GameMaster\GameStreamFactory;
use GameMaster\Logger;
use Siso\Core\Event;

/**
 * GenerateZoneJob - Background job for zone generation
 *
 * Boundary code. The generation itself runs through the stream
 * (handle() emits zone_generate_requested, like POST …/zone/generate).
 * The queue mechanics below — job files, status transitions, retry
 * scheduling, job selection — are still logic outside gates; see
 * doc/conformance.md finding B3.
 * 
 * This job can be processed by:
 * - A simple file-based queue worker
 * - A Redis/database queue system
 * - A cron job
 */
class GenerateZoneJob
{
    private GameStreamFactory $factory;
    private string $queuePath;
    private array $callbacks = [];
    private ?AiTransport $transport;
    
    public function __construct(?GameStreamFactory $factory = null, string $queuePath = '', ?AiTransport $transport = null)
    {
        $this->factory = $factory ?? new GameStreamFactory();
        $this->transport = $transport;
        $config = $this->factory->getConfig();
        $this->queuePath = $queuePath ?: ($config['zonesPath'] ?? '/tmp') . '/_queue';
    }
    
    /**
     * Queue a zone generation job
     */
    public function queue(
        string $gameId,
        string $zoneId,
        ?string $parentZoneId = null,
        ?string $direction = null,
        array $worldContext = [],
        ?string $callbackUrl = null
    ): string {
        // Ensure queue directory exists
        if (!is_dir($this->queuePath)) {
            @mkdir($this->queuePath, 0755, true);
        }
        
        $jobId = uniqid('zoneJob_', true);
        
        $jobData = [
            'jobId' => $jobId,
            'type' => 'zone_generation',
            'gameId' => $gameId,
            'zoneId' => $zoneId,
            'parentZoneId' => $parentZoneId,
            'direction' => $direction,
            'worldContext' => $worldContext,
            'callbackUrl' => $callbackUrl,
            'status' => 'queued',
            'queuedAt' => time(),
            'attempts' => 0,
            'maxAttempts' => 3,
        ];
        
        $filename = "{$this->queuePath}/{$jobId}.json";
        file_put_contents($filename, json_encode($jobData, JSON_PRETTY_PRINT));
        
        return $jobId;
    }
    
    /**
     * Process a specific job
     */
    public function process(string $jobId): array
    {
        $filename = "{$this->queuePath}/{$jobId}.json";
        
        if (!file_exists($filename)) {
            return ['success' => false, 'error' => 'Job not found'];
        }
        
        $jobData = json_decode(file_get_contents($filename), true);
        if (!$jobData) {
            return ['success' => false, 'error' => 'Invalid job data'];
        }
        
        // Mark as processing
        $jobData['status'] = 'processing';
        $jobData['startedAt'] = time();
        $jobData['attempts']++;
        file_put_contents($filename, json_encode($jobData, JSON_PRETTY_PRINT));
        
        try {
            $result = $this->handle($jobData);
            
            // Mark as complete
            $jobData['status'] = $result['success'] ? 'complete' : 'failed';
            $jobData['completedAt'] = time();
            $jobData['result'] = $result;
            file_put_contents($filename, json_encode($jobData, JSON_PRETTY_PRINT));
            
            // Move to completed/failed directory
            $targetDir = $result['success'] 
                ? "{$this->queuePath}/_completed"
                : "{$this->queuePath}/_failed";
            
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }
            
            rename($filename, "{$targetDir}/{$jobId}.json");
            
            // Notify callback if set
            if (!empty($jobData['callbackUrl'])) {
                $this->notifyCallback($jobData['callbackUrl'], $jobData);
            }
            
            // Notify registered callbacks
            foreach ($this->callbacks as $callback) {
                $callback($jobData, $result);
            }
            
            return $result;
            
        } catch (\Throwable $e) {
            Logger::exception($e, 'GenerateZoneJob::processJob ' . ($jobData['zoneId'] ?? '?'));

            $jobData['status'] = 'failed';
            $jobData['error'] = $e->getMessage();
            $jobData['completedAt'] = time();
            
            // Check if should retry
            if ($jobData['attempts'] < $jobData['maxAttempts']) {
                $jobData['status'] = 'queued';
                $jobData['retryAt'] = time() + (60 * $jobData['attempts']); // Exponential backoff
            }
            
            file_put_contents($filename, json_encode($jobData, JSON_PRETTY_PRINT));
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'retry' => $jobData['status'] === 'queued',
            ];
        }
    }
    
    /**
     * Handle the actual zone generation.
     *
     * The job is a client of the same stream flow as
     * POST /api/game/{gameId}/zone/generate: it emits
     * zone_generate_requested through the driver and reads the
     * http_response the gates produce. The game state is read
     * fresh; the queued worldContext snapshot is no longer used.
     */
    public function handle(array $data): array
    {
        $settled = $this->factory->createDriver($this->transport)->run(new Event('zone_generate_requested', [
            'gameId' => $data['gameId'] ?? '',
            'zoneId' => $data['zoneId'] ?? '',
            'parentZoneId' => $data['parentZoneId'] ?? null,
            'direction' => $data['direction'] ?? null,
            'constraints' => [],
            'now' => date('c'),
        ]));

        foreach ($settled as $event) {
            if ($event->type !== 'http_response') {
                continue;
            }
            $body = $event->data['body'];
            if ($event->data['status'] === 201) {
                return [
                    'success' => true,
                    'zoneId' => $body['data']['id'],
                    'zoneName' => $body['data']['name'],
                    'tokensUsed' => $body['data']['tokensUsed'] ?? 0,
                ];
            }
            return ['success' => false, 'error' => $body['error'] ?? 'Zone generation failed'];
        }

        return ['success' => false, 'error' => 'No result from zone generation'];
    }

    /**
     * Process all queued jobs
     */
    public function processQueue(int $limit = 10): array
    {
        $processed = [];
        $jobs = $this->getQueuedJobs($limit);
        
        foreach ($jobs as $jobId) {
            $result = $this->process($jobId);
            $processed[$jobId] = $result;
        }
        
        return $processed;
    }
    
    /**
     * Get queued job IDs
     */
    public function getQueuedJobs(int $limit = 10): array
    {
        if (!is_dir($this->queuePath)) {
            return [];
        }
        
        $files = glob("{$this->queuePath}/*.json");
        $jobs = [];
        
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if (!$content) continue;
            
            $data = json_decode($content, true);
            if (!$data) continue;
            
            // Skip if not queued or not ready for retry
            if ($data['status'] !== 'queued') continue;
            if (isset($data['retryAt']) && $data['retryAt'] > time()) continue;
            
            $jobs[] = $data['jobId'];
            
            if (count($jobs) >= $limit) break;
        }
        
        return $jobs;
    }
    
    /**
     * Get queue status
     */
    public function getQueueStatus(): array
    {
        if (!is_dir($this->queuePath)) {
            return ['queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        }
        
        $status = [
            'queued' => 0,
            'processing' => 0,
            'completed' => count(glob("{$this->queuePath}/_completed/*.json") ?: []),
            'failed' => count(glob("{$this->queuePath}/_failed/*.json") ?: []),
        ];
        
        foreach (glob("{$this->queuePath}/*.json") ?: [] as $file) {
            $content = @file_get_contents($file);
            if (!$content) continue;
            
            $data = json_decode($content, true);
            if (!$data) continue;
            
            if ($data['status'] === 'queued') {
                $status['queued']++;
            } elseif ($data['status'] === 'processing') {
                $status['processing']++;
            }
        }
        
        return $status;
    }
    
    /**
     * Get job details
     */
    public function getJob(string $jobId): ?array
    {
        // Check main queue
        $filename = "{$this->queuePath}/{$jobId}.json";
        if (file_exists($filename)) {
            return json_decode(file_get_contents($filename), true);
        }
        
        // Check completed
        $filename = "{$this->queuePath}/_completed/{$jobId}.json";
        if (file_exists($filename)) {
            return json_decode(file_get_contents($filename), true);
        }
        
        // Check failed
        $filename = "{$this->queuePath}/_failed/{$jobId}.json";
        if (file_exists($filename)) {
            return json_decode(file_get_contents($filename), true);
        }
        
        return null;
    }
    
    /**
     * Register completion callback
     */
    public function onComplete(callable $callback): void
    {
        $this->callbacks[] = $callback;
    }
    
    /**
     * Notify webhook callback
     */
    private function notifyCallback(string $url, array $data): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
        ]);
        
        curl_exec($ch);
        curl_close($ch);
    }
    
    /**
     * Clear completed/failed jobs older than given age
     */
    public function cleanup(int $maxAgeSeconds = 86400): int
    {
        $deleted = 0;
        $cutoff = time() - $maxAgeSeconds;
        
        foreach (['_completed', '_failed'] as $dir) {
            $path = "{$this->queuePath}/{$dir}";
            if (!is_dir($path)) continue;
            
            foreach (glob("{$path}/*.json") as $file) {
                if (filemtime($file) < $cutoff) {
                    @unlink($file);
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }
}
