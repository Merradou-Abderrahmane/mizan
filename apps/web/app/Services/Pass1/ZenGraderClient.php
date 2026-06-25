<?php

namespace App\Services\Pass1;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * opencode/zen grader client (OpenAI-compatible chat completions). Tries the
 * primary model, falls back to the configured fallback model on a 5xx or a
 * connection timeout. Metered key — never a chat subscription.
 */
class ZenGraderClient implements GraderClient
{
    public function complete(string $system, string $user): string
    {
        $primary = (string) config('grader.model');
        $fallback = (string) config('grader.fallback_model');

        try {
            return $this->call($primary, $system, $user);
        } catch (RuntimeException|ConnectionException $e) {
            if ($fallback === '' || $fallback === $primary) {
                throw $e;
            }

            return $this->call($fallback, $system, $user);
        }
    }

    private function call(string $model, string $system, string $user): string
    {
        $response = Http::withToken((string) config('grader.api_key'))
            ->timeout((int) config('grader.timeout'))
            ->acceptJson()
            ->post(rtrim((string) config('grader.base_url'), '/').'/chat/completions', [
                'model' => $model,
                'temperature' => (float) config('grader.temperature'),
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

        // 5xx → let complete() fall back to the secondary model.
        if ($response->serverError()) {
            throw new RuntimeException("Grader server error ({$response->status()}) for model {$model}.");
        }

        $response->throw();

        return (string) $response->json('choices.0.message.content', '');
    }
}
