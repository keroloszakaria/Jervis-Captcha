param(
    [string]$Corpus = '.adversarial-text-corpus',
    [string]$Output = '.adversarial-text-corpus\windows-ocr-predictions.json',
    [string]$Language = 'en-US'
)
$ErrorActionPreference = 'Stop'
$manifestPath = Join-Path $Corpus 'manifest.json'
if (-not (Test-Path $manifestPath)) { throw "Missing corpus manifest: $manifestPath" }

Add-Type -AssemblyName System.Runtime.WindowsRuntime
[Windows.Media.Ocr.OcrEngine, Windows.Foundation, ContentType=WindowsRuntime] | Out-Null
[Windows.Storage.StorageFile, Windows.Storage, ContentType=WindowsRuntime] | Out-Null
[Windows.Storage.Streams.IRandomAccessStream, Windows.Storage.Streams, ContentType=WindowsRuntime] | Out-Null
[Windows.Graphics.Imaging.BitmapDecoder, Windows.Graphics.Imaging, ContentType=WindowsRuntime] | Out-Null
[Windows.Graphics.Imaging.SoftwareBitmap, Windows.Graphics.Imaging, ContentType=WindowsRuntime] | Out-Null
[Windows.Globalization.Language, Windows.Globalization, ContentType=WindowsRuntime] | Out-Null

$script:asTask = [System.WindowsRuntimeSystemExtensions].GetMethods() |
    Where-Object { $_.Name -eq 'AsTask' -and $_.IsGenericMethod -and $_.GetParameters().Count -eq 1 } |
    Select-Object -First 1
function Await($operation, [Type]$resultType) {
    $task = $script:asTask.MakeGenericMethod($resultType).Invoke($null, @($operation))
    $task.Wait()
    return $task.Result
}

$engine = [Windows.Media.Ocr.OcrEngine]::TryCreateFromLanguage(
    [Windows.Globalization.Language]::new($Language)
)
if ($null -eq $engine) { throw "Windows OCR language is unavailable: $Language" }

$manifest = Get-Content $manifestPath -Raw | ConvertFrom-Json
$predictions = @()
foreach ($record in $manifest.records) {
    $path = [System.IO.Path]::GetFullPath([string] $record.path)
    $started = [System.Diagnostics.Stopwatch]::StartNew()
    $file = Await ([Windows.Storage.StorageFile]::GetFileFromPathAsync($path)) ([Windows.Storage.StorageFile])
    $stream = Await ($file.OpenAsync([Windows.Storage.FileAccessMode]::Read)) ([Windows.Storage.Streams.IRandomAccessStream])
    try {
        $decoder = Await ([Windows.Graphics.Imaging.BitmapDecoder]::CreateAsync($stream)) ([Windows.Graphics.Imaging.BitmapDecoder])
        $bitmap = Await ($decoder.GetSoftwareBitmapAsync()) ([Windows.Graphics.Imaging.SoftwareBitmap])
        try {
            $result = Await ($engine.RecognizeAsync($bitmap)) ([Windows.Media.Ocr.OcrResult])
            $prediction = [string] $result.Text
        } finally {
            if ($bitmap -is [System.IDisposable]) { $bitmap.Dispose() }
        }
    } finally {
        if ($stream -is [System.IDisposable]) { $stream.Dispose() }
    }
    $started.Stop()

    $predictions += [pscustomobject]@{
        profile = [string] $record.profile
        variant = [string] $record.variant
        path = [string] $record.path
        prediction = $prediction
        durationMs = [Math]::Round($started.Elapsed.TotalMilliseconds, 3)
    }
}

$outputDir = Split-Path -Parent $Output
if ($outputDir -and -not (Test-Path $outputDir)) { New-Item -ItemType Directory -Force $outputDir | Out-Null }
$json = [pscustomobject]@{
    solver = 'windows-media-ocr'
    language = $Language
    generatedAtUtc = [DateTime]::UtcNow.ToString('o')
    predictions = $predictions
} | ConvertTo-Json -Depth 5
[System.IO.File]::WriteAllText(
    [System.IO.Path]::GetFullPath($Output),
    $json,
    [System.Text.UTF8Encoding]::new($false)
)
Write-Host "wrote $($predictions.Count) predictions to $Output"
