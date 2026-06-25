<?php

namespace Tests\Feature\Pass1;

use App\Models\Brief;
use App\Models\Competence;
use App\Models\Criterion;
use App\Models\Level;
use App\Models\StudentRepo;
use App\Services\Pass1\FakeGraderClient;
use App\Services\Pass1\Pass1Prompt;
use App\Services\Pass1\Pass1ResponseParser;
use App\Services\Pass1\RepoDigest;
use App\Services\Pass1\ZenGraderClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Pass1PrimitivesTest extends TestCase
{
    use RefreshDatabase;

    private string $repoPath;

    protected function tearDown(): void
    {
        if (isset($this->repoPath) && is_dir($this->repoPath)) {
            File::deleteDirectory($this->repoPath);
        }
        parent::tearDown();
    }

    /** @param array<string,string> $files relPath => contents */
    private function makeRepo(array $files): string
    {
        $this->repoPath = sys_get_temp_dir().'/mizan_digest_'.uniqid();
        foreach ($files as $rel => $contents) {
            $full = $this->repoPath.'/'.$rel;
            File::ensureDirectoryExists(dirname($full));
            File::put($full, $contents);
        }

        return $this->repoPath;
    }

    // --- RepoDigest -------------------------------------------------------

    public function test_digest_excludes_deps_and_vcs_and_verifies_citations(): void
    {
        $path = $this->makeRepo([
            'vendor/autoload.php' => "<?php // dep\n",
            '.git/config' => "[core]\n",
            'app/Models/User.php' => implode("\n", array_fill(0, 20, 'x')),
        ]);

        $digest = RepoDigest::build($path, 200_000);

        $this->assertStringContainsString('app/Models/User.php', $digest->text);
        $this->assertStringNotContainsString('vendor/', $digest->text);
        $this->assertStringNotContainsString('.git/', $digest->text);

        $this->assertTrue($digest->has('app/Models/User.php', 12));
        $this->assertFalse($digest->has('app/Models/User.php', 99));
        $this->assertFalse($digest->has('nope.php', 1));
    }

    public function test_digest_truncates_when_over_cap(): void
    {
        $path = $this->makeRepo([
            'a_first.php' => "line1\nline2\nline3\n",
            'z_second.php' => 'UNIQUE_SECOND_MARKER',
        ]);

        // Cap fits the tree + first file, but not the second.
        $digest = RepoDigest::build($path, 100);

        $this->assertTrue($digest->truncated);
        $this->assertStringContainsString('a_first.php', $digest->text, 'First file fits and is included.');
        $this->assertStringNotContainsString('UNIQUE_SECOND_MARKER', $digest->text);
        $this->assertFalse($digest->has('z_second.php', 1), 'Omitted file must not verify.');
    }

    // --- Pass1Prompt ------------------------------------------------------

    private function makeGradingFixture(): array
    {
        $referentiel = \App\Models\Referentiel::factory()->create();
        $brief = Brief::factory()->create(['referentiel_id' => $referentiel->id, 'title' => 'Build an API']);
        $competence = Competence::factory()->technical()->create(['referentiel_id' => $referentiel->id, 'label' => 'Designs a data model']);
        $level = Level::factory()->create(['referentiel_id' => $referentiel->id, 'label' => 'Adapter', 'sort_order' => 2]);
        $criteria = collect([
            Criterion::factory()->create(['competence_id' => $competence->id, 'level_id' => $level->id, 'label' => 'Has migrations']),
            Criterion::factory()->create(['competence_id' => $competence->id, 'level_id' => $level->id, 'label' => 'Has relations']),
        ]);

        return [$brief, $competence, $level, $criteria];
    }

    public function test_prompt_is_blind_and_lists_criteria_and_forbids_verdicts(): void
    {
        [$brief, $competence, $level, $criteria] = $this->makeGradingFixture();
        StudentRepo::factory()->create(['name' => 'alice-project', 'operator_persona' => 'advanced', 'clone_path' => '/tmp/alice-secret-path']);

        $digest = RepoDigest::build($this->makeRepo(['app/X.php' => "<?php\n"])); // no identity inside
        [$system, $user] = (new Pass1Prompt)->build($brief, $competence, $level, $criteria, $digest);

        $blob = $system."\n".$user;
        $this->assertStringNotContainsString('alice-project', $blob, 'R4: no StudentRepo name in the prompt.');
        $this->assertStringNotContainsString('advanced', $blob, 'R4: no operator_persona in the prompt.');
        $this->assertStringNotContainsString('alice-secret-path', $blob, 'R4: no clone_path in the prompt.');

        foreach ($criteria as $c) {
            $this->assertStringContainsString("id {$c->id}", $user, 'Each criterion must be listed.');
        }

        $this->assertStringContainsString('NEVER output "valide" or "non valide"', $system);
        $this->assertStringContainsString('semble valide', $system);
    }

    public function test_prompt_warns_when_digest_truncated(): void
    {
        [$brief, $competence, $level, $criteria] = $this->makeGradingFixture();
        $path = $this->makeRepo([
            'a_first.php' => "line1\nline2\nline3\n",
            'z_second.php' => 'SECOND',
        ]);
        $digest = RepoDigest::build($path, 100);

        [, $user] = (new Pass1Prompt)->build($brief, $competence, $level, $criteria, $digest);

        $this->assertTrue($digest->truncated);
        $this->assertStringContainsString("Treat any apparent absence as 'not seen', not 'not present'", $user);
    }

    // --- Pass1ResponseParser ---------------------------------------------

    public function test_parser_coerces_bare_verdict_to_a_verifier(): void
    {
        [, , , $criteria] = $this->makeGradingFixture();
        $path = $this->makeRepo(['app/Models/User.php' => implode("\n", array_fill(0, 20, 'x'))]);
        $digest = RepoDigest::build($path);
        $first = $criteria->first();

        $raw = json_encode([
            'competence_draft_rollup' => 'valide',
            'confidence' => 0.9,
            'probe_questions' => ['Why?'],
            'criteria' => [[
                'criterion_id' => (string) $first->id,
                'evidence' => [['file' => 'app/Models/User.php', 'line' => 3, 'note' => 'declares model']],
                'assessment_draft' => 'valide',
                'reasoning' => 'looks fine',
            ]],
        ]);

        $result = (new Pass1ResponseParser)->parse($raw, $digest, $criteria);

        $this->assertSame('à vérifier', $result->rollup, 'R1: bare verdict coerced.');
        $parsedFirst = collect($result->criteria)->firstWhere('criterionId', $first->id);
        $this->assertSame('à vérifier', $parsedFirst->assessment, 'R1: per-criterion bare verdict coerced.');
        $this->assertCount(1, $parsedFirst->evidence);
    }

    public function test_parser_drops_phantom_citation_and_defaults_criterion(): void
    {
        [, , , $criteria] = $this->makeGradingFixture();
        $digest = RepoDigest::build($this->makeRepo(['app/Real.php' => "<?php\n// one\n// two\n"]));
        $first = $criteria->first();

        $raw = json_encode([
            'competence_draft_rollup' => 'semble valide',
            'criteria' => [[
                'criterion_id' => (string) $first->id,
                'evidence' => [['file' => 'does/not/exist.php', 'line' => 10, 'note' => 'phantom']],
                'assessment_draft' => 'semble valide',
                'reasoning' => 'cited code',
            ]],
        ]);

        $parsed = collect((new Pass1ResponseParser)->parse($raw, $digest, $criteria)->criteria)
            ->firstWhere('criterionId', $first->id);

        $this->assertSame([], $parsed->evidence, 'Phantom citation must be dropped.');
        $this->assertSame('à vérifier', $parsed->assessment, 'No surviving evidence → à vérifier.');
    }

    public function test_parser_captures_reasoning(): void
    {
        [, , , $criteria] = $this->makeGradingFixture();
        $digest = RepoDigest::build($this->makeRepo(['app/X.php' => "<?php\n"]));
        $first = $criteria->first();

        $raw = json_encode([
            'competence_draft_rollup' => 'à vérifier',
            'criteria' => [[
                'criterion_id' => (string) $first->id,
                'evidence' => [],
                'assessment_draft' => 'à vérifier',
                'reasoning' => 'not-found: no routes file in the excerpt',
            ]],
        ]);

        $parsed = collect((new Pass1ResponseParser)->parse($raw, $digest, $criteria)->criteria)
            ->firstWhere('criterionId', $first->id);

        $this->assertSame('not-found: no routes file in the excerpt', $parsed->reasoning);
    }

    public function test_parser_flags_unparseable_after_one_repair_retry(): void
    {
        [, , , $criteria] = $this->makeGradingFixture();
        $digest = RepoDigest::build($this->makeRepo(['app/X.php' => "<?php\n"]));

        $result = (new Pass1ResponseParser)->parseWithRepair(
            'not json',
            $digest,
            $criteria,
            fn (string $hint) => 'still not json',
        );

        $this->assertTrue($result->unparseable);
        $this->assertSame('still not json', $result->raw);
    }

    // --- Grader clients ---------------------------------------------------

    public function test_zen_client_shapes_request_and_falls_back_on_5xx(): void
    {
        config()->set('grader.api_key', 'test-key');
        config()->set('grader.model', 'primary-m');
        config()->set('grader.fallback_model', 'fallback-m');
        config()->set('grader.temperature', 0.0);

        Http::fakeSequence()
            ->push(['error' => 'overloaded'], 503)
            ->push(['choices' => [['message' => ['content' => 'OK-FROM-FALLBACK']]]], 200);

        $out = (new ZenGraderClient)->complete('SYS', 'USER');

        $this->assertSame('OK-FROM-FALLBACK', $out);
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['temperature'] === 0.0
                && $request['response_format']['type'] === 'json_object';
        });
        Http::assertSent(fn ($request) => $request['model'] === 'fallback-m');
    }

    public function test_fake_grader_returns_queued_and_records_prompts(): void
    {
        $fake = (new FakeGraderClient)->queue('CANNED');

        $out = $fake->complete('SYS', 'USER');

        $this->assertSame('CANNED', $out);
        $this->assertSame('SYS', $fake->calls[0]['system']);
        $this->assertSame('USER', $fake->calls[0]['user']);
    }
}
