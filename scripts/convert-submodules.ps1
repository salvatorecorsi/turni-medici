param([switch] $CheckOnly)

$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$pluginRoot = Join-Path $projectRoot 'wp-content\plugins'
$localRoot = Join-Path $projectRoot '.local'

function Invoke-Git {
    param([string[]] $GitArgs)
    & git @GitArgs
    if ($LASTEXITCODE -ne 0) {
        throw ('Git non riuscito: git ' + ($GitArgs -join ' '))
    }
}

function Assert-ProjectPath {
    param([string] $Path)
    $resolved = [IO.Path]::GetFullPath($Path)
    if (-not $resolved.StartsWith($projectRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Percorso esterno al progetto: $resolved"
    }
}

$gitRoot = (Invoke-Git -GitArgs @('-C', $projectRoot, 'rev-parse', '--show-toplevel')).Trim()
if ([IO.Path]::GetFullPath($gitRoot) -ne $projectRoot) {
    throw 'Esegui la conversione nel repository Turni Medici.'
}
if ((Test-Path -LiteralPath $localRoot) -and ((Get-Item -Force -LiteralPath $localRoot).Attributes -band [IO.FileAttributes]::ReparsePoint)) {
    throw '.local deve essere una cartella del progetto, non un collegamento.'
}

$lock = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'dependencies.lock.json') | ConvertFrom-Json
$expected = @{
    'wp-content/plugins/define-blocks' = 'https://github.com/corsivo-suite/define-blocks'
    'wp-content/plugins/corsivo-core-editor' = 'https://github.com/corsivo-suite/core-editor'
}
$plugins = @($lock.plugins)
if ($plugins.Count -ne 2 -or @($plugins.path | Select-Object -Unique).Count -ne 2) {
    throw 'Il manifest deve dichiarare esattamente i due plugin Corsivo.'
}
foreach ($plugin in $plugins) {
    if (-not $expected.ContainsKey($plugin.path) -or $plugin.repository -ne $expected[$plugin.path] -or $plugin.commit -notmatch '^[0-9a-f]{40}$') {
        throw 'Percorso, repository o commit della dipendenza non valido.'
    }
    $destination = Join-Path $projectRoot $plugin.path
    Assert-ProjectPath $destination
    if (-not (Test-Path -LiteralPath $destination -PathType Container)) {
        throw "Cartella plugin mancante: $destination"
    }
    if ((Get-Item -Force -LiteralPath $destination).Attributes -band [IO.FileAttributes]::ReparsePoint) {
        throw "La cartella plugin non deve essere un collegamento: $destination"
    }
    if (Test-Path -LiteralPath (Join-Path $destination '.git')) {
        throw "Il plugin e gia un repository: controlla git submodule status prima di convertirlo."
    }
    $entries = @(Invoke-Git -GitArgs @('-C', $projectRoot, 'ls-files', '--stage', '--', $plugin.path))
    if (@($entries | Where-Object { $_ -notmatch '^100(644|755) ' }).Count -gt 0) {
        throw "Indice inatteso per $($plugin.path)."
    }
    Write-Host ("{0}: {1} file ordinari nell'indice, commit previsto {2}" -f $plugin.name, $entries.Count, $plugin.commit)
}
if ($CheckOnly) {
    Write-Host 'Controlli preliminari superati. Nessun file o indice modificato.'
    return
}

$migrationRoot = Join-Path $localRoot ('submodule-migration-' + [Guid]::NewGuid().ToString('N'))
Assert-ProjectPath $migrationRoot
New-Item -ItemType Directory -Path $migrationRoot -Force | Out-Null
$originalRoot = Join-Path $migrationRoot 'originals'
New-Item -ItemType Directory -Path $originalRoot | Out-Null
$indexPath = (Invoke-Git -GitArgs @('-C', $projectRoot, 'rev-parse', '--path-format=absolute', '--git-path', 'index')).Trim()
if (Test-Path -LiteralPath $indexPath) {
    Copy-Item -LiteralPath $indexPath -Destination (Join-Path $migrationRoot 'index-before-conversion')
}

try {
    foreach ($plugin in $plugins) {
        $slug = Split-Path $plugin.path -Leaf
        $clonePath = Join-Path $migrationRoot $slug
        Assert-ProjectPath $clonePath
        Invoke-Git -GitArgs @('clone', '--no-checkout', '--', ($plugin.repository + '.git'), $clonePath)
        Invoke-Git -GitArgs @('-C', $clonePath, 'checkout', '--detach', $plugin.commit)
        $head = (Invoke-Git -GitArgs @('-C', $clonePath, 'rev-parse', 'HEAD')).Trim()
        if ($head -ne $plugin.commit) {
            throw "Commit inatteso per $($plugin.name)."
        }
    }

    foreach ($plugin in $plugins) {
        $slug = Split-Path $plugin.path -Leaf
        $name = if ($slug -eq 'corsivo-core-editor') { 'core-editor' } else { $slug }
        $destination = Join-Path $projectRoot $plugin.path
        $clonePath = Join-Path $migrationRoot $slug
        $backupPath = Join-Path $originalRoot $slug
        Assert-ProjectPath $destination
        Assert-ProjectPath $clonePath
        Assert-ProjectPath $backupPath
        if ([IO.Path]::GetDirectoryName([IO.Path]::GetFullPath($destination)) -ne $pluginRoot) {
            throw "Destinazione plugin inattesa: $destination"
        }
        Invoke-Git -GitArgs @('-C', $projectRoot, 'rm', '-r', '--cached', '--ignore-unmatch', '--', $plugin.path)
        Move-Item -LiteralPath $destination -Destination $backupPath
        Move-Item -LiteralPath $clonePath -Destination $destination
        Invoke-Git -GitArgs @('-C', $projectRoot, 'submodule', 'add', '--force', '--name', $name, ($plugin.repository + '.git'), $plugin.path)
    }

    Invoke-Git -GitArgs (@('-C', $projectRoot, 'submodule', 'absorbgitdirs', '--') + @($plugins.path))
    Invoke-Git -GitArgs @('-C', $projectRoot, 'add', '--', '.gitignore', '.gitmodules', 'README.md', 'CHANGELOG.md', 'dependencies.lock.json', 'scripts/convert-submodules.ps1')
    foreach ($plugin in $plugins) {
        $entry = @(Invoke-Git -GitArgs @('-C', $projectRoot, 'ls-files', '--stage', '--', $plugin.path))
        if ($entry.Count -ne 1 -or $entry[0] -notmatch ('^160000 ' + $plugin.commit + ' 0\s+' + [regex]::Escape($plugin.path) + '$')) {
            throw "Gitlink non valido per $($plugin.name)."
        }
        $dirty = @(Invoke-Git -GitArgs @('-C', (Join-Path $projectRoot $plugin.path), 'status', '--porcelain'))
        if ($dirty.Count -ne 0) {
            throw "Il submodule $($plugin.name) contiene modifiche inattese."
        }
    }
    Invoke-Git -GitArgs @('-C', $projectRoot, 'submodule', 'status')
    Write-Host "Conversione completata e staged. Copie originali conservate in $originalRoot"
    Write-Host 'Nessun commit o push eseguito.'
}
catch {
    Write-Host "Conversione interrotta. File originali e backup indice conservati in $migrationRoot"
    throw
}
