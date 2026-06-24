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

        $isUrl = $this->isUrl($source);
        $cloneDir = null;

        try {
            $repoPath = $isUrl
                ? ($cloneDir = $this->cloneToTemp($source))
                : $source;

            try {
                $report = $this->invokeRunner($repoPath);
            } catch (RunnerCrashException $e) {
                DB::transaction(function () use ($source, $brief, $studentRepoId, $operatorPersona, $name, $e) {
                    $studentRepo = $this->resolveStudentRepo($source, $studentRepoId, $operatorPersona, $name);
                    $this->createRun($studentRepo, $brief, [
                        'status' => 'error',
                        'runner_version' => null,
                        'repo_path' => $e->repoPath,
                        'started_at' => null,
                        'ended_at' => now()->toIso8601String(),
                        'checks' => [],
                        'raw_stdout' => $e->rawStdout,
                    ]);
                });

                throw $e;
            }

            return DB::transaction(function () use ($source, $brief, $studentRepoId, $operatorPersona, $name, $report) {
                $studentRepo = $this->resolveStudentRepo($source, $studentRepoId, $operatorPersona, $name);

                return $this->createRun($studentRepo, $brief, $report);
            });
        } finally {
            if ($cloneDir !== null) {
                $this->deleteDirectory($cloneDir);
            }
        }
    }

    private function isUrl(string $source): bool
    {
        return Str::startsWith($source, ['http://', 'https://', 'git@'])
            || Str::startsWith($source, 'file://');
    }

    private function cloneToTemp(string $url): string
    {
        $cloneDir = storage_path('runner-clones/' . Str::uuid()->toString());

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
        $runnerBin = $monorepoRoot . '/apps/runner/bin/runner';
        $process = new Process(['php', $runnerBin, $repoPath]);
        $process->run();

        $stdout = $process->getOutput();
        $report = json_decode($stdout, true);

        if (JSON_ERROR_NONE !== json_last_error() || ! is_array($report)) {
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
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}