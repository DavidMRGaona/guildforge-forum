<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * Runs the forum's reconciliation (forum:sync-publications) after a module is enabled, so the
 * system categories of the publications it brings («Mesas», «Campañas») and the discussions of what
 * it already published come without waiting for the daily run.
 *
 * The command runs in a fresh PHP process: this one (a request or a long-lived worker) booted with
 * the previous set of modules, so the core's registry here lacks the sources of the module just
 * enabled. A failing run is logged and left to the daily reconciliation; it never fails the job.
 */
final class SyncPublicationsAfterModuleChangeJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Seconds the run may take: it only creates categories and queues checks */
    public const int TIMEOUT_SECONDS = 120;

    /** Seconds the uniqueness lock lasts, so enabling several modules at once queues a single run */
    public int $uniqueFor = 300;

    public int $timeout = self::TIMEOUT_SECONDS + 30;

    /**
     * Queues the run without ever failing the caller: with a sync queue the job runs inline, and a broken queue throws on dispatch.
     */
    public static function dispatchSafely(): void
    {
        try {
            self::dispatch();
        } catch (Throwable $exception) {
            Log::warning('[Forum] Could not queue the publications reconciliation after a module change.', ['exception' => $exception]);
        }
    }

    public function handle(): void
    {
        $command = [(new PhpExecutableFinder)->find(false) ?: 'php', base_path('artisan'), 'forum:sync-publications', '--no-interaction'];

        try {
            $result = Process::path(base_path())->timeout(self::TIMEOUT_SECONDS)->run($command);

            if (! $result->successful()) {
                Log::warning('[Forum] The publications reconciliation after a module change had problems.', [
                    'error' => trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : "exit code {$result->exitCode()}",
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('[Forum] Could not run the publications reconciliation after a module change.', ['error' => $exception->getMessage()]);
        }
    }
}
