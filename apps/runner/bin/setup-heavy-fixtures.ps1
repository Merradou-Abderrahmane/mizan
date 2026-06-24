# Creates the HEAVY fixtures under apps/runner/tests/fixtures/.
# These are real Laravel apps, gitignored and regenerated on demand.
# Requires: PHP + composer on PATH, network access for create-project.
#
# Also drops the committed anchor `laravel_readme_stub.md` (the Laravel default
# README bytes) so ReadmeRealCheck can byte-compare without a fresh create-project.

$ErrorActionPreference = 'Stop'
$root = 'C:\Users\lenovo\Desktop\mizan\apps\runner'
$fx  = Join-Path $root 'tests\fixtures'
$php = 'php'
$composerPhar = 'C:\laragon\bin\composer\composer.phar'

function Copy-Repo($src, $dst) {
    if (Test-Path $dst) { Remove-Item -Recurse -Force $dst }
    Copy-Item -Recurse $src $dst
}

# 1. Base valid_repo via create-project (network).
$valid = Join-Path $fx 'valid_repo'
if (Test-Path $valid) { Remove-Item -Recurse -Force $valid }
Write-Output "Creating valid_repo via composer create-project (may take a minute)..."
& $php -d extension=php_zip.dll $composerPhar create-project --no-interaction --prefer-dist laravel/laravel $valid
if ($LASTEXITCODE -ne 0) { throw "create-project failed for valid_repo" }

# 2. Save the Laravel default README as the committed stub anchor.
$stub = Join-Path $fx 'laravel_readme_stub.md'
Copy-Item -Force (Join-Path $valid 'README.md') $stub
Write-Output "Saved stub anchor: $stub"

# 3. Customize valid_repo README (>200B, not the stub). Init git, 2 commits.
$custom = "# Mizan valid fixture`r`n`r`nThis is a real Laravel app used by the Mizan runner as the canonical passing fixture. It must pass every structural check: composer install, artisan boot, migrations, a real README, .env not tracked, and a git history with more than one commit.`r`n"
Set-Content -Path (Join-Path $valid 'README.md') -Value $custom -NoNewline

if (Test-Path (Join-Path $valid '.git')) { Remove-Item -Recurse -Force (Join-Path $valid '.git') }
git -C $valid init -q
git -C $valid add -A
git -C $valid -c user.email=fix@t -c user.name=fix commit -q -m 'initial scaffold'
Set-Content -Path (Join-Path $valid '.mizan-marker') -Value 'second commit for context-free git history' -NoNewline
git -C $valid add -A
git -C $valid -c user.email=fix@t -c user.name=fix commit -q -m 'add marker'
Write-Output ("valid_repo commits: " + (git -C $valid rev-list --count HEAD))

# 4. stub_readme_repo: copy valid_repo, overwrite README with the stub, commit.
$stubRepo = Join-Path $fx 'stub_readme_repo'
Copy-Repo $valid $stubRepo
Copy-Item -Force $stub (Join-Path $stubRepo 'README.md')
git -C $stubRepo add -A
git -C $stubRepo -c user.email=fix@t -c user.name=fix commit -q -m 'restore stub README'
Write-Output ("stub_readme_repo commits: " + (git -C $stubRepo rev-list --count HEAD))

# 5. broken_deps_repo: copy valid_repo, require a non-existent package, drop lock.
$badDeps = Join-Path $fx 'broken_deps_repo'
Copy-Repo $valid $badDeps
$cj = Get-Content (Join-Path $badDeps 'composer.json') -Raw | ConvertFrom-Json
$cj.require.'nonexistent/mizan-broken-dep' = '^9.9.9'
$cj | ConvertTo-Json -Depth 10 | Set-Content -Path (Join-Path $badDeps 'composer.json') -NoNewline
Remove-Item -Force (Join-Path $badDeps 'composer.lock') -ErrorAction SilentlyContinue
git -C $badDeps add -A
git -C $badDeps -c user.email=fix@t -c user.name=fix commit -q -m 'break deps'
Write-Output "broken_deps_repo ready"

# 6. broken_bootstrap_repo: copy valid_repo, prepend a throw to bootstrap/app.php.
$badBoot = Join-Path $fx 'broken_bootstrap_repo'
Copy-Repo $valid $badBoot
$ba = Join-Path $badBoot 'bootstrap\app.php'
$orig = Get-Content $ba -Raw
$broken = $orig -replace '(?s)^<\?php', "<?php`r`n`r`nthrow new Exception('mizan broken bootstrap');"
Set-Content -Path $ba -Value $broken -NoNewline
git -C $badBoot add -A
git -C $badBoot -c user.email=fix@t -c user.name=fix commit -q -m 'break bootstrap'
Write-Output "broken_bootstrap_repo ready"

# 7. broken_migration_repo: copy valid_repo, add a migration that throws.
$badMig = Join-Path $fx 'broken_migration_repo'
Copy-Repo $valid $badMig
$mig = Join-Path $badMig 'database\migrations\2024_01_01_000002_mizan_broken.php'
Set-Content -Path $mig -Value @'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        throw new RuntimeException('mizan broken migration');
    }

    public function down(): void {}
};
'@ -NoNewline
git -C $badMig add -A
git -C $badMig -c user.email=fix@t -c user.name=fix commit -q -m 'break migration'
Write-Output "broken_migration_repo ready"

Write-Output "All heavy fixtures created at $fx"