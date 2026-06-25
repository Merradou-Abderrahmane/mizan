<?php

namespace App\Services\Pass1;

use RuntimeException;

/**
 * Test double: returns queued canned responses and records the prompts it was
 * given (so tests can assert the prompt is blind). No network.
 */
class FakeGraderClient implements GraderClient
{
    /** @var list<string> */
    private array $queue = [];

    /** @var list<array{system: string, user: string}> */
    public array $calls = [];

    public function queue(string $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function complete(string $system, string $user): string
    {
        $this->calls[] = ['system' => $system, 'user' => $user];

        if ($this->queue === []) {
            throw new RuntimeException('FakeGraderClient has no queued response.');
        }

        return array_shift($this->queue);
    }
}
