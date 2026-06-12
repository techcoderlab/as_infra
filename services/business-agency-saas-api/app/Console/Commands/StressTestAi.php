<?php

namespace App\Console\Commands;

use App\Models\AiAgent;
use App\Models\AiJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Ai\AiGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StressTestAi extends Command
{
    /**
     * Usage:
     * php artisan test:stress {slug} --count=10 --poison
     */
    protected $signature = 'test:stress
                            {slug : The slug of the AI Agent to test}
                            {--count=10 : How many concurrent agents to fire}
                            {--tenant=1 : The Tenant ID to run as}
                            {--poison : Inject a 1MB payload to test protections}';

    protected $description = 'Simulate high-concurrency AI load (Robust & Isolated).';

    public function handle(AiGateway $gateway)
    {
        $count = (int) $this->option('count');
        $slug = $this->argument('slug');
        $tenantId = $this->option('tenant');
        $isPoison = $this->option('poison');

        // 1. Setup & Validation
        $this->info("🔥 Preparing Isolated Stress Test: {$count} Concurrent Jobs...");

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant {$tenantId} not found.");

            return 1;
        }

        $agent = AiAgent::where('tenant_id', $tenantId)->where('slug', $slug)->first();
        if (! $agent) {
            $this->error("Agent '{$slug}' not found for Tenant {$tenantId}.");

            return 1;
        }

        // 2. Create Bulk Test Leads (ISOLATION STEP)
        // We create unique records so we can track ONLY these, ignoring real user traffic.
        $this->info("Creating {$count} ephemeral test leads...");

        $payloadData = ['name' => 'Stress Test User', 'source' => 'Load Balancer'];
        if ($isPoison) {
            $this->warn('☣️  POISON MODE ACTIVE: Injecting 1MB payload...');
            $payloadData['garbage'] = str_repeat('A', 1024 * 1024);
        }

        $leads = [];
        // Use a Transaction for speed
        DB::beginTransaction();
        for ($i = 0; $i < $count; $i++) {
            $leads[] = Lead::create([
                'tenant_id' => $tenantId,
                'status' => 'test',
                'source' => 'stress-cli',
                'payload' => $payloadData,
                'notes' => "Stress Test Record #{$i} (Batch: ".uniqid().')',
            ]);
        }
        DB::commit();

        // Capture IDs for strict filtering
        $targetIds = collect($leads)->pluck('id')->toArray();
        $targetType = 'App\\Models\\Lead';

        // 3. Burst Dispatch (The "Enqueue" Phase)
        $this->info('🚀 Dispatching to Sidecar...');
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $dispatchStart = microtime(true);

        foreach ($leads as $lead) {
            try {
                // This triggers the Job -> Sidecar -> 202 Accepted flow
                // It should take ~50ms per request (not 30s)
                $gateway->executeAgent($slug, $lead);
            } catch (\Exception $e) {
                $this->error('Dispatch failed: '.$e->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $dispatchTime = microtime(true) - $dispatchStart;
        $this->newLine(2);

        $this->info('✅ Enqueue Complete in '.round($dispatchTime, 2).'s');
        $this->info('   ↳ Speed: '.round($count / ($dispatchTime > 0 ? $dispatchTime : 1)).' req/sec (PHP -> Python)');

        // 4. Async Monitoring (Watching the Database)
        $this->info('Waiting for Webhooks... (Listening for specific IDs only)');

        $monitorStart = microtime(true);
        $lastReport = '';

        while (true) {
            // ROBUST QUERY: Only check the IDs we created.
            // If a real user creates a job now, this query ignores it.
            $stats = AiJob::where('tenant_id', $tenantId)
                ->where('target_type', $targetType)
                ->whereIn('target_id', $targetIds) // <--- CRITICAL ISOLATION
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $pending = ($stats['pending'] ?? 0);
            $processing = ($stats['processing'] ?? 0);
            $completed = ($stats['completed'] ?? 0);
            $failed = ($stats['failed'] ?? 0);

            $active = $pending + $processing;
            $finished = $completed + $failed;

            $report = "\r   ⏳ Active: {$active} (Pend:{$pending} Proc:{$processing}) | ✅ Done: {$completed} | ❌ Failed: {$failed}   ";

            // Only update screen if numbers changed (saves flicker)
            if ($report !== $lastReport) {
                $this->output->write($report);
                $lastReport = $report;
            }

            // Exit Condition
            if ($finished >= $count) {
                break;
            }

            // Timeout safety (5 minutes)
            if ((microtime(true) - $monitorStart) > 300) {
                $this->newLine();
                $this->error('Timeout reached! Some jobs may be stuck.');
                break;
            }

            sleep(2);
        }

        $totalTime = microtime(true) - $monitorStart;
        $throughput = $count / ($totalTime > 0 ? $totalTime : 1) * 60; // jobs per minute

        // 5. Cleanup
        $this->newLine();
        $this->info('Cleaning up test data...');
        // Only delete OUR leads
        Lead::whereIn('id', $targetIds)->delete();

        // Optional: Clean up the Job records too?
        // AiJob::whereIn('target_id', $targetIds)->where('target_type', $targetType)->delete();

        // 6. Report Card
        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Concurrent Agents', $count, 'OK'],
                ['Enqueue Time (Latency)', round($dispatchTime, 2).'s', 'Fast'],
                ['Total Processing Time', round($totalTime, 2).'s', '-'],
                ['Throughput (End-to-End)', round($throughput, 0).' RPM', $throughput > 10 ? '✅ Healthy' : '⚠️ Slow'],
                ['Isolation', 'Active', '✅ User traffic ignored'],
                ['Failures', $failed, $failed === 0 ? '✅ None' : '❌ Errors Found'],
            ]
        );

        return 0;
    }
}
