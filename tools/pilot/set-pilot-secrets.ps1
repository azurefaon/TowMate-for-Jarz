<#
.SYNOPSIS
  Privately writes the two PayMongo TEST credentials into .env.pilot. Contains no secrets itself.

.DESCRIPTION
  Run this in YOUR OWN PowerShell window. It asks for each credential as hidden input (nothing is echoed,
  and Read-Host input is never stored in the PowerShell history), validates the format, and changes only the
  PAYMONGO_SECRET_KEY and PAYMONGO_WEBHOOK_SECRET lines of .env.pilot. Every other line is preserved.

  It never prints a secret, takes none as an argument (so none can show up in a process listing or in history),
  touches no other file (not .env, not .env.testing), and refuses to run unless .env.pilot is git-ignored and
  not tracked.

  Usage (from the repository root):
      powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\pilot\set-pilot-secrets.ps1

.PARAMETER AcceptOtherWebhookFormat
  Only if PayMongo shows a webhook secret that does not start with whsk_ : accepts any 16+ character
  letters/digits/underscore value. The secret KEY must always be an sk_test_ key.
#>
[CmdletBinding()]
param(
    [string] $EnvFile = (Join-Path (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path '.env.pilot'),
    [switch] $AcceptOtherWebhookFormat
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Test-SecretKeyFormat([string] $Value) {
    # PayMongo TEST secret keys: sk_test_ + letters/digits. sk_live_ and pk_ keys never match.
    return $Value -cmatch '^sk_test_[A-Za-z0-9]{16,}$'
}

function Test-WebhookSecretFormat([string] $Value, [bool] $AllowOther) {
    if ($Value -cmatch '^whsk_[A-Za-z0-9]{16,}$') { return $true }
    return $AllowOther -and ($Value -cmatch '^[A-Za-z0-9_]{16,}$') -and ($Value -cnotmatch '^(sk|pk)_')
}

function ConvertFrom-SecureValue([System.Security.SecureString] $Secure) {
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Secure)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}

# Replaces ONLY the named KEY= lines. Fails (changing nothing) if a line is missing; never appends or guesses.
function Set-EnvFileValues([string] $Path, [hashtable] $Values) {
    $raw = [IO.File]::ReadAllText($Path)
    $newline = if ($raw -match "`r`n") { "`r`n" } else { "`n" }
    $lines = $raw -split "`r?`n"

    foreach ($name in $Values.Keys) {
        $index = -1
        for ($i = 0; $i -lt $lines.Count; $i++) {
            if ($lines[$i].StartsWith("$name=", [StringComparison]::Ordinal)) { $index = $i; break }
        }
        if ($index -lt 0) { throw "The line '$name=' was not found in the file; nothing was changed." }
        $lines[$index] = "$name=" + $Values[$name]
    }

    [IO.File]::WriteAllText($Path, ($lines -join $newline), (New-Object Text.UTF8Encoding($false)))
}

function Get-EnvFileFlag([string] $Path, [string] $Name) {
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        if ($line.StartsWith("$Name=", [StringComparison]::Ordinal)) { return $line.Substring($Name.Length + 1).Length -gt 0 }
    }
    return $false
}

function Assert-PilotFileIsSafe([string] $Path) {
    if ((Split-Path $Path -Leaf) -ne '.env.pilot') { throw 'This script only ever edits a file named .env.pilot.' }
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw ".env.pilot not found at the expected location. Create it from .env.pilot.example first." }

    $root = Split-Path $Path -Parent

    # Tracked first: Git never reports a tracked file as "ignored", so this gives the clearer reason.
    if (Test-GitSucceeds $root @('ls-files', '--error-unmatch', '--', '.env.pilot')) {
        throw '.env.pilot is tracked by Git. Refusing to write secrets into it.'
    }

    if (-not (Test-GitSucceeds $root @('check-ignore', '-q', '--', '.env.pilot'))) {
        throw '.env.pilot is NOT git-ignored. Refusing to write secrets into a file Git could commit.'
    }
}

# True when the git command exits 0. Windows PowerShell 5.1 turns a native command's stderr text into a terminating
# error under $ErrorActionPreference = 'Stop' (even with 2>$null), and "git ls-files --error-unmatch" writes to stderr
# in the GOOD case (file untracked), so errors are relaxed just for this call and only the exit code is used.
function Test-GitSucceeds([string] $Root, [string[]] $GitArguments) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & git -C $Root @GitArguments *> $null
        return ($LASTEXITCODE -eq 0)
    }
    finally { $ErrorActionPreference = $previous }
}

function Read-HiddenSecret([string] $Prompt) {
    $secure = Read-Host -Prompt $Prompt -AsSecureString
    try { return (ConvertFrom-SecureValue $secure) } finally { $secure.Dispose() }
}

function Invoke-Main {
    Assert-PilotFileIsSafe $EnvFile
    Write-Host ''
    Write-Host 'PayMongo TEST credentials -> .env.pilot  (git-ignored; nothing you type is shown or stored in history)' -ForegroundColor Cyan
    Write-Host 'Paste each value, then press Enter. Nothing will appear on screen; that is expected.'
    Write-Host ''

    if ((Get-EnvFileFlag $EnvFile 'PAYMONGO_SECRET_KEY') -or (Get-EnvFileFlag $EnvFile 'PAYMONGO_WEBHOOK_SECRET')) {
        $answer = Read-Host 'A credential is already set in .env.pilot. Overwrite it? (type yes to overwrite)'
        if ($answer -ne 'yes') { Write-Host 'Cancelled. Nothing was changed.'; return }
    }

    $key = $null; $hook = $null
    try {
        $key = Read-HiddenSecret '1/2  PayMongo TEST secret API key (starts with sk_test_)'
        if (-not (Test-SecretKeyFormat $key)) {
            if ($key -cmatch '^sk_live_') { throw 'That is a LIVE key. This pilot only accepts test keys. Nothing was changed.' }
            throw 'That does not look like a PayMongo TEST secret key (expected sk_test_ followed by letters/digits). Nothing was changed.'
        }

        $hook = Read-HiddenSecret '2/2  PayMongo TEST webhook secret (starts with whsk_)'
        if (-not (Test-WebhookSecretFormat $hook $AcceptOtherWebhookFormat.IsPresent)) {
            throw 'That does not look like a PayMongo webhook secret (expected whsk_ followed by letters/digits). If PayMongo showed a different format, re-run with -AcceptOtherWebhookFormat. Nothing was changed.'
        }

        Set-EnvFileValues $EnvFile @{ PAYMONGO_SECRET_KEY = $key; PAYMONGO_WEBHOOK_SECRET = $hook }
    }
    finally {
        $key = $null; $hook = $null
        [GC]::Collect()
    }

    Write-Host ''
    Write-Host ('PAYMONGO_SECRET_KEY     : ' + $(if (Get-EnvFileFlag $EnvFile 'PAYMONGO_SECRET_KEY') { 'configured' } else { 'MISSING' }))
    Write-Host ('PAYMONGO_WEBHOOK_SECRET : ' + $(if (Get-EnvFileFlag $EnvFile 'PAYMONGO_WEBHOOK_SECRET') { 'configured' } else { 'MISSING' }))
    Write-Host 'No other line of .env.pilot was changed, and no other file was touched. Tell Claude "secrets configured" (do NOT paste them).' -ForegroundColor Green
}

# Dot-sourcing (. .\set-pilot-secrets.ps1) loads the functions without running; normal execution runs the flow.
if ($MyInvocation.InvocationName -ne '.') {
    try { Invoke-Main }
    catch { Write-Host ''; Write-Host ('STOPPED: ' + $_.Exception.Message) -ForegroundColor Red; exit 1 }
}
