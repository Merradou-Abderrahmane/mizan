<?php

namespace App\Services;

use App\Models\Brief;
use App\Models\Run;
use App\Models\StudentRepo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class RepoIntakeService
{
    public function intake(
        string $source,
        int $briefId,
        ?int $studentRepoId = null,
        ?string $operatorPersona = null,
        ?string $name = null,
    ): Run {
        $brief = Brief::findOrFail($briefId);

        try {
            $report = $this->runRunnerOnSource($source);
        } catch (RunnerCrashException $e) {
            DB::transaction(function () use ($source, $brief, $studentRepoId, $operatorPersona, $name, $e) {
                $studentRepo = $this->resolveStudentRepo($source, $studentRepoId, $operatorPersona, $name);
                $this->createRun($studentRepo, $brief, $this->crashReport($e));
            });

            throw $e;
        }

        return DB::transaction(function () use ($source, $brief, $studentRepoId, $operatorPersona, $name, $report) {
            $studentRepo = $this->resolveStudentRepo($source, $studentRepoId, $operatorPersona, $name);

            return $this->createRun($studentRepo, $brief, $report);
        });
    }

    /**
     * Run the structural runner against an ALREADY-persisted (pending) run and
     * fill in its runner report. The UI pre-creates the run + its StudentRepo so
     * it shows immediately as `pending`; this populates the report. It does NOT
     * set run.status — the caller (the queue job) owns the
     * pending → processing → terminal lifecycle.
     */
    public function intakeIntoRun(Run $run): Run
    {
        $source = $run->studentRepo->clone_path;

        try {
            $report = $this->runRunnerOnSource($source);
        } catch (RunnerCrashException $e) {
            $run->update([
                'runner_report_json' => $this->crashReport($e),
                'ended_at' => now(),
            ]);

            throw $e;
        }

        $run->update([
            'runner_report_json' => $report,
            'started_at' => $report['started_at'] ?? null,
            'ended_at' => $report['ended_at'] ?? null,
        ]);

        return $run;
    }

    /**
     * Clone (when a URL) and run the structural runner, always cleaning up the
     * temp clone. Returns the decoded report or throws RunnerCrashException.
     * Protected so a test can substitute the runner without a subprocess.
     *
     * @return array<string, mixed>
     */
    protected function runRunnerOnSource(string $source): array
    {
        $cloneDir = null;

        try {
            $repoPath = $this->isUrl($source)
                ? ($cloneDir = $this->cloneToTemp($source))
                : $source;

            return $this->invokeRunner($repoPath);
        } finally {
            if ($cloneDir !== null) {
                $this->deleteDirectory($cloneDir);
            }
        }
    }

    /** @return array<string, mixed> */
    private function crashReport(RunnerCrashException $e): array
    {
        return [
            'status' => 'error',
            'runner_version' => null,
            'repo_path' => $e->repoPath,
            'started_at' => null,
            'ended_at' => now()->toIso8601String(),
            'checks' => [],
            'raw_stdout' => $e->rawStdout,
        ];
    }

    private function isUrl(string $source): bool
    {
        return Str::startsWith($source, ['http://', 'https://', 'git@'])
            || Str::startsWith($source, 'file://');
    }

    private function cloneToTemp(string $url): string
    {
        $cloneDir = storage_path('runner-clones/'.Str::uuid()->toString());

        if (! is_dir(dirname($cloneDir)) && ! mkdir(dirname($cloneDir), 0755, true)) {
            throw new \RuntimeException("Failed to create clone parent directory: {$cloneDir}");
        }

        $process = new Process(['git', 'clone', '--depth', '1', $url, $cloneDir]);
        $process->run();

        if (! $process->isSuccessful() || ! is_dir($cloneDir)) {
            throw new ProcessFailedException($process);
        }

        return $cloneDir;
    }

    private function invokeRunner(string $repoPath): array
    {
        $monorepoRoot = dirname(base_path(), 2);
        $runnerBin = $monorepoRoot.'/apps/runner/bin/runner';
        $process = new Process(['php', $runnerBin, $repoPath]);
        $process->run();

        $stdout = $process->getOutput();
        $report = json_decode($stdout, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($report)) {
            throw new RunnerCrashException($stdout, $repoPath);
        }

        return $report;
    }

    private function resolveStudentRepo(
        string $source,
        ?int $studentRepoId,
        ?string $operatorPersona,
        ?string $name,
    ): StudentRepo {
        if ($studentRepoId !== null) {
            return StudentRepo::findOrFail($studentRepoId);
        }

        $derivedName = $name ?? $this->deriveName($source);

        return StudentRepo::create([
            'name' => $derivedName,
            'clone_path' => $source,
            'operator_persona' => $operatorPersona,
        ]);
    }

    private function deriveName(string $source): string
    {
        if ($this->isUrl($source)) {
            $basename = basename(parse_url($source, PHP_URL_PATH) ?: '');
            $basename = preg_replace('/\.git$/', '', $basename);

            return $basename !== '' && $basename !== null ? $basename : 'unknown';
        }

        $basename = basename(rtrim($source, '/\\'));

        return $basename !== '' ? $basename : 'unknown';
    }

    private function createRun(StudentRepo $studentRepo, Brief $brief, array $report): Run
    {
        return Run::create([
            'student_repo_id' => $studentRepo->id,
            'brief_id' => $brief->id,
            'status' => $report['status'] ?? 'error',
            'runner_report_json' => $report,
            'started_at' => $report['started_at'] ?? null,
            'ended_at' => $report['ended_at'] ?? null,
        ]);
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir) ?: [], ['.', '..']);

        foreach ($items as $item) {
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
