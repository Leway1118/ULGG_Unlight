[CmdletBinding()]
param(
    [ValidateSet('Check','Apply','Daily','Rollback','DailyStage')][string]$Mode='Check',
    [Parameter(Mandatory=$true)][string]$Server,
    [Parameter(Mandatory=$true)][int]$Port,
    [Parameter(Mandatory=$true)][string]$Key,
    [string]$Zip='',
    [string]$Plan='',
    [string]$PlanSha='',
    [string]$Transaction='',
    [string]$Download='D:\Download'
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
if($Server -notmatch '^[A-Za-z0-9_.-]+@[A-Za-z0-9.-]+$'){throw 'Server must be user@hostname or user@IPv4'}
if($Port -lt 1 -or $Port -gt 65535){throw 'Invalid SSH port'}
if(-not (Test-Path -LiteralPath $Key -PathType Leaf)){throw 'SSH key not found'}
function Run([string]$Exe,[string[]]$ArgList){ & $Exe @ArgList; if($LASTEXITCODE -ne 0){throw "$Exe failed: $LASTEXITCODE"} }
if($Mode -eq 'DailyStage'){
    & (Join-Path $PSScriptRoot 'Build-Quest-G09.ps1') -Output $Download
    if($LASTEXITCODE -ne 0){throw 'Build failed'}
    $Mode='Check'
}
if($Mode -eq 'Rollback'){
    if($Transaction -notmatch '^\d{8}T\d{6}Z-[0-9a-f]{8}$'){throw 'Invalid transaction ID'}
    Run 'ssh' @('-i',$Key,'-p',"$Port",$Server,"bash ~/quest-g09.sh rollback $Transaction")
    return
}
if(-not $Zip){$Zip=(Get-ChildItem -LiteralPath $Download -Filter 'ULGG-Quest-P1.1-G09-*-HOLD.zip' -File | Sort-Object LastWriteTime -Descending | Select-Object -First 1).FullName}
$zipFile=Get-Item -LiteralPath $Zip
if($zipFile.Name -notmatch '^ULGG-Quest-P1\.1-G09-[A-Za-z0-9-]+-HOLD\.zip$'){throw 'Unexpected package filename'}
$sha=(Get-FileHash -LiteralPath $zipFile.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
if($Mode -eq 'Check'){
    foreach($n in @('quest-g09.sh','quest-g09-release.py')){Run 'scp' @('-i',$Key,'-P',"$Port",(Join-Path $PSScriptRoot $n),"${Server}:~/")}
    Run 'scp' @('-i',$Key,'-P',"$Port",$zipFile.FullName,"${Server}:~/")
    Run 'ssh' @('-i',$Key,'-p',"$Port",$Server,"bash ~/quest-g09.sh check ~/$($zipFile.Name) $sha")
    return
}
if($Plan -notmatch '^/var/tmp/ulgg-quest-g09-plan-[A-Za-z0-9_-]+\.json$' -or $PlanSha -notmatch '^[0-9a-fA-F]{64}$'){throw 'Use PLAN and PLAN_SHA256 from successful Check'}
$verb=$Mode.ToLowerInvariant()
Run 'ssh' @('-i',$Key,'-p',"$Port",$Server,"bash ~/quest-g09.sh $verb ~/$($zipFile.Name) $sha $Plan $PlanSha")
