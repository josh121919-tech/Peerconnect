<#
.SYNOPSIS
    Registers, shows or removes PeerConnect's two scheduled tasks.

.DESCRIPTION
    PeerConnect Backup        every day at 02:00     php scripts\backup.php
    PeerConnect Maintenance   every 30 minutes       php scripts\maintenance.php

    Both run as your own Windows account "whether signed in or not", with
    "do not store password" (S4U). That is what keeps them in the background:
    a task that runs only while you are signed in runs on your desktop, and
    mysqldump then opens a terminal window every night. No password is saved
    anywhere. The trade-off is that creating a task of this kind needs an
    elevated PowerShell - so does removing one.

    If the laptop is off or asleep at the scheduled time, the task runs as soon
    as it can afterwards. Each run's outcome is in C:\PeerConnectBackups\logs
    and in Task Scheduler's "Last Run Result" (0x0 means success).

.EXAMPLE
    From PowerShell opened with "Run as administrator":

    powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\case\case\scripts\tasks.ps1 -Register
    powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\case\case\scripts\tasks.ps1 -Status
    powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\case\case\scripts\tasks.ps1 -Remove

    -ExecutionPolicy Bypass applies to that one command only; it does not
    change the machine's policy.
#>
[CmdletBinding()]
param(
    [switch]$Register,
    [switch]$Status,
    [switch]$Remove,
    [string]$User = "$env:USERDOMAIN\$env:USERNAME",
    [string]$Php  = 'C:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'
$TaskPath = '\PeerConnect\'
$Root     = Split-Path -Parent $PSScriptRoot

function Show-Status {
    $tasks = @(Get-ScheduledTask -TaskPath $TaskPath -ErrorAction SilentlyContinue)
    if ($tasks.Count -eq 0) {
        'No PeerConnect tasks are registered.'
        return
    }
    foreach ($t in $tasks) {
        $info = Get-ScheduledTaskInfo -TaskPath $TaskPath -TaskName $t.TaskName
        $last = if ($info.LastRunTime -and $info.LastRunTime.Year -gt 2000) { $info.LastRunTime } else { 'never' }
        '{0}' -f $t.TaskName
        '    state        {0}' -f $t.State
        '    runs as      {0} ({1})' -f $t.Principal.UserId, $t.Principal.LogonType
        '    last run     {0}   result 0x{1:X}' -f $last, $info.LastTaskResult
        '    next run     {0}' -f $info.NextRunTime
    }
}

if ($Status -or (-not $Register -and -not $Remove)) {
    Show-Status
    if (-not $Register -and -not $Remove -and -not $Status) {
        ''
        'Use -Register, -Status or -Remove. Run Get-Help on this file for details.'
    }
    return
}

if ($Remove) {
    foreach ($name in 'PeerConnect Backup', 'PeerConnect Maintenance') {
        if (Get-ScheduledTask -TaskPath $TaskPath -TaskName $name -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskPath $TaskPath -TaskName $name -Confirm:$false
            "Removed: $name"
        }
    }
    return
}

# ---- Register ---------------------------------------------------------------

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    throw 'Creating these tasks needs PowerShell opened with "Run as administrator".'
}
foreach ($need in $Php, "$Root\scripts\backup.php", "$Root\scripts\maintenance.php") {
    if (-not (Test-Path $need)) { throw "Not found: $need" }
}

$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30) `
    -MultipleInstances IgnoreNew

$principal = New-ScheduledTaskPrincipal -UserId $User -LogonType S4U -RunLevel Limited

Register-ScheduledTask -Force -TaskPath $TaskPath -TaskName 'PeerConnect Backup' `
    -Description 'Nightly database dump and weekly uploads archive into C:\PeerConnectBackups. See scripts\README.md in the PeerConnect project.' `
    -Action (New-ScheduledTaskAction -Execute $Php -Argument "`"$Root\scripts\backup.php`"" -WorkingDirectory $Root) `
    -Trigger (New-ScheduledTaskTrigger -Daily -At '02:00') `
    -Settings $settings -Principal $principal | Out-Null
'Registered: PeerConnect Backup'

$every30 = New-ScheduledTaskTrigger -Once -At (Get-Date).Date.AddHours((Get-Date).Hour + 1) -RepetitionInterval (New-TimeSpan -Minutes 30)
Register-ScheduledTask -Force -TaskPath $TaskPath -TaskName 'PeerConnect Maintenance' `
    -Description 'Every 30 minutes: marks sessions missed after the grace period, then refreshes mentor scores. See scripts\README.md in the PeerConnect project.' `
    -Action (New-ScheduledTaskAction -Execute $Php -Argument "`"$Root\scripts\maintenance.php`"" -WorkingDirectory $Root) `
    -Trigger $every30 `
    -Settings $settings -Principal $principal | Out-Null
'Registered: PeerConnect Maintenance'

''
Show-Status
