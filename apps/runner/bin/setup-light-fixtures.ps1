# Creates the LIGHTWEIGHT fixtures under apps/runner/tests/fixtures/.
# These are tiny, committed in the mizan repo (no Laravel needed).
# Heavy real-Laravel fixtures are created by setup-heavy-fixtures.ps1.

$ErrorActionPreference = 'Stop'
$base = 'C:\Users\lenovo\Desktop\mizan\apps\runner\tests\fixtures'

function New-FixtureDir($name) {
    $p = Join-Path $base $name
    if (Test-Path $p) { Remove-Item -Recurse -Force $p }
    New-Item -ItemType Directory -Path $p -Force | Out-Null
    return $p
}

# no_readme_repo: a non-empty dir with no README-like file.
$d = New-FixtureDir 'no_readme_repo'
Set-Content -Path (Join-Path $d '.gitkeep') -Value '' -NoNewline

# non_git_dir: files but no .git.
$d = New-FixtureDir 'non_git_dir'
Set-Content -Path (Join-Path $d 'composer.json') -Value '{}' -NoNewline

# env_committed_repo: git repo, >1 commit, .env tracked by git.
$d = New-FixtureDir 'env_committed_repo'
git -C $d init -q
git -C $d -c core.autocrlf=false -c user.email=fix@t -c user.name=fix add -A 2>$null
Set-Content -Path (Join-Path $d '.env') -Value 'APP_KEY=abc123' -NoNewline
git -C $d add -f '.env'
git -C $d -c user.email=fix@t -c user.name=fix commit -q -m 'add env'
$padding = New-Object string '-', 220
Set-Content -Path (Join-Path $d 'README.md') -Value "# env committed fixture`r`n`r`n$padding" -NoNewline
git -C $d add -f 'README.md'
git -C $d -c user.email=fix@t -c user.name=fix commit -q -m 'add readme'

# single_commit_repo: git repo with exactly 1 commit.
$d = New-FixtureDir 'single_commit_repo'
git -C $d init -q
$padding = New-Object string '-', 250
Set-Content -Path (Join-Path $d 'README.md') -Value "# single commit fixture`r`n`r`n$padding" -NoNewline
git -C $d add 'README.md'
git -C $d -c user.email=fix@t -c user.name=fix commit -q -m 'initial'

Write-Output "Lightweight fixtures created."
Write-Output "  env_committed_repo commits: $(git -C (Join-Path $base 'env_committed_repo') rev-list --count HEAD)"
Write-Output "  single_commit_repo commits: $(git -C (Join-Path $base 'single_commit_repo') rev-list --count HEAD)"