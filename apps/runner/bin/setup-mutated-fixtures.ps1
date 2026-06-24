# Creates the MUTATED heavy fixtures from an existing valid_repo (no create-project).
# Run after setup-heavy-fixtures.ps1 has produced valid_repo + laravel_readme_stub.md.

$ErrorActionPreference = 'Stop'
$root = 'C:\Users\lenovo\Desktop\mizan\apps\runner'
$fx  = Join-Path $root 'tests\fixtures'
$valid = Join-Path $fx 'valid_repo'
$stub  = Join-Path $fx 'laravel_readme_stub.md'

if (-not (Test-Path $valid)) { throw 'valid_repo missing: run setup-heavy-fixtures.ps1 first' }

function Copy-Repo($src, $dst) {
    if (Test-Path $dst) { Remove-Item -Recurse -Force $dst }
    Copy-Item -Recurse $src $dst
}

# stub_readme_repo
$stubRepo = Join-Path $fx 'stub_readme_repo'
Write-Output "Copying stub_readme_repo..."
Copy-Repo $valid $stubRepo
Copy-Item -Force $stub (Join-Path $stubRepo 'README.md')
git -C $stubRepo add -A
git -C $stubRepo -c user.email=fix@t -c user.name=fix commit -q -m 'restore stub README'
Write-Output ("stub_readme_repo commits: " + (git -C $stubRepo rev-list --count HEAD))

# broken_deps_repo
$badDeps = Join-Path $fx 'broken_deps_repo'
Write-Output "Copying broken_deps_repo..."
Copy-Repo $valid $badDeps
$composerJson = Join-Path $badDeps 'composer.json'
$raw = Get-Content $composerJson -Raw
$injected = $raw -replace '"require"\s*:\s*\{', '"require": { "nonexistent/mizan-broken-dep": "^9.9.9",'
Set-Content -Path $composerJson -Value $injected -NoNewline
Remove-Item -Force (Join-Path $badDeps 'composer.lock') -ErrorAction SilentlyContinue
git -C $badDeps add -A
git -C $badDeps -c user.email=fix@t -c user.name=fix commit -q -m 'break deps'
Write-Output "broken_deps_repo ready"

# broken_bootstrap_repo
$badBoot = Join-Path $fx 'broken_bootstrap_repo'
Write-Output "Copying broken_bootstrap_repo..."
Copy-Repo $valid $badBoot
$ba = Join-Path $badBoot 'bootstrap\app.php'
$orig = Get-Content $ba -Raw
$broken = $orig -replace '(?s)^<\?php', "<?php`r`n`r`nthrow new Exception('mizan broken bootstrap');"
Set-Content -Path $ba -Value $broken -NoNewline
git -C $badBoot add -A
git -C $badBoot -c user.email=fix@t -c user.name=fix commit -q -m 'break bootstrap'
Write-Output "broken_bootstrap_repo ready"

# broken_migration_repo
$badMig = Join-Path $fx 'broken_migration_repo'
Write-Output "Copying broken_migration_repo..."
Copy-Repo $valid $badMig
$mig = Join-Path $badMig 'database\migrations\2024_01_01_000002_mizan_broken.php'
$migContent = "<?php`r`n`r`nuse Illuminate\Database\Migrations\Migration;`r`nuse Illuminate\Database\Schema\Blueprint;`r`nuse Illuminate\Support\Facades\Schema;`r`n`r`nreturn new class extends Migration {`r`n    public function up(): void`r`n    {`r`n        throw new RuntimeException('mizan broken migration');`r`n    }`r`n`r`n    public function down(): void {}`r`n};`r`n"
Set-Content -Path $mig -Value $migContent -NoNewline
git -C $badMig add -A
git -C $badMig -c user.email=fix@t -c user.name=fix commit -q -m 'break migration'
Write-Output "broken_migration_repo ready"

Write-Output "All mutated fixtures created."