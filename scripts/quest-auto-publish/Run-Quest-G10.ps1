[CmdletBinding()]
param(
  [ValidateSet('Register','Run')][string]$Mode='Run',
  [Parameter(Mandatory=$true)][string]$Server,
  [Parameter(Mandatory=$true)][int]$Port,
  [Parameter(Mandatory=$true)][string]$Key
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
if($Server -notmatch '^root@[A-Za-z0-9.-]+$'){throw 'Use existing authorized root SSH endpoint'}
if($Port -lt 1 -or $Port -gt 65535){throw 'Invalid port'}
$Key=(Resolve-Path -LiteralPath $Key).Path
$HomeDir='D:\ULGG\QuestAnalyticsAutoPublish_P1_1'
$Builder='D:\Download\Build-Quest-G09.ps1'
$ControllerSha='399343332ced80a2ff4077763d947bd905c3e5c2333cd35538d78c9547cc807e'
$FirstDigest='ebbac54e23f6df59fcecefce3a83974f2b588489a618dc878403c067dc925a8c'
$FirstZip='D:\Download\ULGG-Quest-P1.1-G09-20261127T055221Z-13183f88-HOLD.zip'
$TaskName='ULGG-Quest-Daily-Publish'
$Ssh=(Get-Command ssh.exe -ErrorAction Stop).Source
$Scp=(Get-Command scp.exe -ErrorAction Stop).Source
$opts=@('-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','ConnectTimeout=15','-i',$Key)
function Invoke-Checked([string]$Exe,[string[]]$Arguments) {
  & $Exe @Arguments
  if($LASTEXITCODE -ne 0){throw "$Exe failed: $LASTEXITCODE"}
}
function Invoke-Daily([string]$Zip) {
  $sha=(Get-FileHash -LiteralPath $Zip -Algorithm SHA256).Hash.ToLowerInvariant()
  if($sha -notmatch '^[0-9a-f]{64}$'){throw 'Invalid package digest'}
  $remote="/root/quest-g10-$sha.zip"
  $verify='test $(sha256sum /root/quest-g09-release.py | cut -c 1-64) = '+$ControllerSha
  Invoke-Checked $Ssh ($opts+@('-p',"$Port",$Server,$verify))
  Invoke-Checked $Scp ($opts+@('-P',"$Port",$Zip,('{0}:{1}' -f $Server,$remote)))
  $command="$verify && python3 /root/quest-g09-release.py daily-auto $remote $sha && rm -- $remote"
  Invoke-Checked $Ssh ($opts+@('-p',"$Port",$Server,$command))
}
if($Mode -eq 'Register'){
  if(Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue){throw 'Task already exists; inspect it before replacement'}
  if((Get-FileHash -LiteralPath $FirstZip -Algorithm SHA256).Hash.ToLowerInvariant() -ne '59478332e01c77229375723444f7be99205e40fe322605a9c6b3f3f6637e96d0'){throw 'Original published package changed'}
  $verifyLive='test $(sha256sum /var/www/html/unlight/pages/quest_public/v1.4.1/data/manifest.json | cut -c 1-64) = '+$FirstDigest
  Invoke-Checked $Ssh ($opts+@('-p',"$Port",$Server,$verifyLive))
  Invoke-Daily $FirstZip
  Write-Host 'PASS daily replay of the published snapshot'
  $identity=[Security.Principal.WindowsIdentity]::GetCurrent().Name
  $exe=Join-Path $PSHOME 'powershell.exe'
  if(-not (Test-Path -LiteralPath $exe)){throw 'Run registration in Windows PowerShell 5.1'}
  foreach($v in @($PSCommandPath,$Key)){if($v.Contains('"')){throw 'Invalid quoted path'}}
  $arguments=('-NoProfile -NonInteractive -File "{0}" -Mode Run -Server {1} -Port {2} -Key "{3}"' -f $PSCommandPath,$Server,$Port,$Key)
  $action=New-ScheduledTaskAction -Execute $exe -Argument $arguments -WorkingDirectory $HomeDir
  $trigger=New-ScheduledTaskTrigger -Daily -At 6am
  $principal=New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
  $settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -RestartCount 2 -RestartInterval (New-TimeSpan -Minutes 15)
  Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Quest data-only daily publish via pinned G09 controller. Existing SSH identity. No Git sync.' | Out-Null
  Get-ScheduledTaskInfo -TaskName $TaskName | Select-Object NextRunTime,LastRunTime,LastTaskResult
  Write-Host 'G10_SCHEDULE_REGISTERED'
  Start-ScheduledTask -TaskName $TaskName
  Write-Host 'G10_FIRST_RUN_STARTED'
  return
}
$runRoot=Join-Path $HomeDir 'g10_runs'
New-Item -ItemType Directory -Force -Path $runRoot | Out-Null
$lock=$null
$transcript=$false
try {
  $lock=[IO.File]::Open((Join-Path $runRoot 'daily.lock'),[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
  $runDir=Join-Path $runRoot ((Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[guid]::NewGuid().ToString('N').Substring(0,8))
  New-Item -ItemType Directory -Path $runDir | Out-Null
  Start-Transcript -Path (Join-Path $runDir 'daily.log') | Out-Null
  $transcript=$true
  & $Builder -Candidate $HomeDir -Output $runDir
  if($LASTEXITCODE -ne 0){throw 'Build failed'}
  $packages=@(Get-ChildItem -LiteralPath $runDir -Filter 'ULGG-Quest-P1.1-G09-*-HOLD.zip' -File)
  if($packages.Count -ne 1){throw 'Expected exactly one freshly built package'}
  $report=Get-Content -LiteralPath (Join-Path $runDir 'Quest-G09-GATE_REPORT.json') -Raw | ConvertFrom-Json
  if($report.schema_missing_count -ne 0 -or $report.semantic_report.status -ne 'PASS_SELECTED_RECONCILIATION'){throw 'Local candidate gate failed'}
  Invoke-Daily $packages[0].FullName
  Write-Host 'G10_RUN_PASS'
} finally {
  if($transcript){Stop-Transcript | Out-Null}
  if($null -ne $lock){$lock.Dispose()}
}
