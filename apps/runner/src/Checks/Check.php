<?php

declare(strict_types=1);

namespace Mizan\Runner\Checks;

use Mizan\Runner\Input;

/**
 * A single fixed, stack-specific structural check (R2: dumb and constant).
 * Implementations MUST NOT read per-brief context and MUST NOT grade.
 */
interface Check
{
    /** Stable check id matching the fixed order in spec. */
    public function id(): string;

    /**
     * Run the check against the student repo. MUST NOT throw — turn any
     * environmental failure into a `skip` and any internal fault into a
     * `fail` with evidence citing the failure.
     */
    public function run(Input $in): CheckResult;
}