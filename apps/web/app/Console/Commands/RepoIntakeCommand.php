<?php

namespace App\Console\Commands;

use App\Services\RepoIntakeService;
use App\Services\RunnerCrashException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * `php artisan repo:intake {source} {brief}` — thin entry point around
 * RepoIntakeService::intake(). The command contains NO domain logic (R5): it
 * forwards both arguments to the service, prints the new Run id, and exits
 * with a clear status. It does NOT modify apps/runner/ (R2) — the service is
 * the caller, not the owner, of the runner subprocess.
 *
 * {source} accepts a local path OR a git/http(s)/file:// URL; the service
 * decides via isUrl(). The operator should pass a LOCAL PATH when the run
 * will subsequently be graded via `pass1:grade`, because URL clones are
 * deleted by the service's finally block after the runner finishes, and the
 * Pass 1 digest needs a readable filesystem path.
 */
class RepoIntakeCommand extends Command
{
    protected $signature = 'repo:intake {source : Local path or git/http(s) URL of the student repo} {brief : Brief id}';

    protected $description = 'Clone (if URL) or accept a local path, run the runner\'s structural checks against it, and persist a Run for the given brief. For pass1:grade to read a digest, pass a LOCAL PATH — URL clones are deleted by the service after the runner finishes.';

    public function handle(RepoIntakeService $service): int
    {
        $source = (string) $this->argument('source');
        $briefArg = $this->argument('brief');

        if (!is_numeric($briefArg)) {
            $this->error('Brief id must be an integer.');
            return Command::FAILURE;
        }

        $briefId = (int) $briefArg;

        try {
            $run = $service->intake($source, $briefId);
        } catch (ModelNotFoundException) {
            $this->error("Brief not found: {$briefId}");

            return Command::FAILURE;
        } catch (ProcessFailedException $e) {
            $this->error('Intake failed: ' . $e::class . ': ' . $e->getMessage());

            return Command::FAILURE;
        } catch (RunnerCrashException $e) {
            $this->error('Intake failed: ' . $e::class . ': ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->line("Run {$run->id} created (status: {$run->status}).");

        return Command::SUCCESS;
    }
}