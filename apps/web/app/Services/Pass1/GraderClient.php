<?php

namespace App\Services\Pass1;

interface GraderClient
{
    /**
     * Send one blind grading call and return the model's raw text response.
     */
    public function complete(string $system, string $user): string;
}
