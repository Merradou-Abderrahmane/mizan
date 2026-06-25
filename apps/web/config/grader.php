<?php

return [
    // opencode/zen gateway (OpenAI-compatible). Metered, pay-per-token key.
    'base_url' => env('GRADER_BASE_URL', 'https://opencode.ai/zen/v1'),
    'api_key' => env('GRADER_API_KEY', ''),
    'model' => env('GRADER_MODEL', 'glm-5.2'),
    'fallback_model' => env('GRADER_FALLBACK_MODEL', 'qwen3.6-plus'),

    // Low temperature for deterministic, JSON-stable grading.
    'temperature' => (float) env('GRADER_TEMPERATURE', 0.0),
    // Per-request HTTP timeout (seconds).
    'timeout' => (int) env('GRADER_TIMEOUT', 120),
    // Hard cap on the source digest sent to the model (bytes).
    'digest_max_bytes' => (int) env('GRADER_DIGEST_MAX_BYTES', 200_000),
];
