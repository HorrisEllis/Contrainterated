#!/usr/bin/env php
<?php
/**
 * Zone Generation Worker
 * 
 * Processes queued zone generation jobs in the background.
 * 
 * Usage:
 *   php worker.php              # Process queue once
 *   php worker.php --daemon     # Run continuously
 *   php worker.php --status     # Show queue status
 *   php worker.php --cleanup    # Clean old jobs
 * 
 * Environment:
 *   keys.json          - API keys (one level above project root)
 *   GAMEMASTER_MOCK_AI - Set to "true" for mock AI
 */

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/config.php';

use GameMaster\Logger;
use GameMaster\GameStreamFactory;
use GameMaster\Jobs\GenerateZoneJob;

Logger::init(__DIR__ . '/errors.log');

// Catch fatal errors that would otherwise silently kill the worker
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Logger::fatal($err);
    }
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    Logger::warn("PHP Error [{$severity}]: {$message}", ['file' => "{$file}:{$line}"]);
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

$_sisoPath = __DIR__ . '/siso/php/code/core/stream.php';
if (!file_exists($_sisoPath)) {
    Logger::error('SISO core not found at siso/php/code/core/ — worker zone jobs will fail');
}
unset($_sisoPath);

// Parse arguments
$args = array_slice($argv, 1);
$daemon = in_array('--daemon', $args);
$status = in_array('--status', $args);
$cleanup = in_array('--cleanup', $args);
$limit = 10;

// Extract limit if provided
foreach ($args as $arg) {
    if (preg_match('/--limit=(\d+)/', $arg, $matches)) {
        $limit = (int)$matches[1];
    }
}

// Configuration
$config = [
    'worldTemplatesPath' => __DIR__ . '/data/worlds',
    'savesPath' => __DIR__ . '/saves',
    'zonesPath' => __DIR__ . '/data/zones',
    'useMockAI' => getenv('GAMEMASTER_MOCK_AI') === 'true' || !getAnthropicApiKey(),
    'aiKey' => getAnthropicApiKey(),
];

$factory = new GameStreamFactory($config);
$job = new GenerateZoneJob($factory);

// Status command
if ($status) {
    $queueStatus = $job->getQueueStatus();
    echo "Zone Generation Queue Status\n";
    echo "============================\n";
    echo "Queued:     {$queueStatus['queued']}\n";
    echo "Processing: {$queueStatus['processing']}\n";
    echo "Completed:  {$queueStatus['completed']}\n";
    echo "Failed:     {$queueStatus['failed']}\n";
    exit(0);
}

// Cleanup command
if ($cleanup) {
    $deleted = $job->cleanup(86400); // 24 hours
    echo "Cleaned up {$deleted} old job files.\n";
    exit(0);
}

// Register completion callback
$job->onComplete(function ($jobData, $result) {
    $symbol = $result['success'] ? '✓' : '✗';
    $zoneId = $jobData['zoneId'];
    $gameId = $jobData['gameId'];

    if ($result['success']) {
        Logger::info("Zone job complete: {$zoneId} for game {$gameId}", [
            'zoneName' => $result['zoneName'] ?? null,
        ]);
        echo "[" . date('H:i:s') . "] {$symbol} Zone '{$zoneId}' for game '{$gameId}' — " . ($result['zoneName'] ?? 'done') . "\n";
    } else {
        Logger::error("Zone job failed: {$zoneId} for game {$gameId}", [
            'error' => $result['error'] ?? 'Unknown',
        ]);
        echo "[" . date('H:i:s') . "] {$symbol} Zone '{$zoneId}' for game '{$gameId}' — Error: " . ($result['error'] ?? 'Unknown') . "\n";
    }
});

// Process queue
if ($daemon) {
    echo "Starting zone generation worker (daemon mode)...\n";
    echo "Press Ctrl+C to stop.\n\n";
    
    while (true) {
        $processed = $job->processQueue($limit);
        
        if (empty($processed)) {
            // No jobs, wait before checking again
            sleep(5);
        } else {
            // Small delay between batches
            usleep(100000); // 100ms
        }
    }
} else {
    echo "Processing zone generation queue...\n";
    
    $processed = $job->processQueue($limit);
    
    if (empty($processed)) {
        echo "No jobs to process.\n";
    } else {
        $success = count(array_filter($processed, fn($r) => $r['success']));
        $failed = count($processed) - $success;
        
        echo "\nProcessed " . count($processed) . " jobs: {$success} success, {$failed} failed.\n";
    }
}
