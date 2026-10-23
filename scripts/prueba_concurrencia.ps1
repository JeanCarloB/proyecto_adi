<#
  Prueba de concurrencia del módulo de alquileres (Issue #15).

  Lanza dos procesos PHP EN PARALELO que intentan apartar el mismo espacio,
  día y franja en el mismo instante. Lo repite varias rondas.

  Esperado en cada ronda: uno "INSERTADA" y el otro "RECHAZADA (11000)".

  Uso (desde la raíz del proyecto, en PowerShell):
    .\scripts\prueba_concurrencia.ps1
    .\scripts\prueba_concurrencia.ps1 -Rondas 10 -Php "C:\ruta\a\php.exe"

  Si PowerShell no deja ejecutar scripts, use:
    powershell -ExecutionPolicy Bypass -File .\scripts\prueba_concurrencia.ps1
#>
param(
    [int]$Rondas = 5,
    [string]$Php = "php"
)

$script = Join-Path $PSScriptRoot "prueba_concurrencia.php"
$raiz   = Split-Path $PSScriptRoot -Parent

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
Write-Host "Prueba de concurrencia: $Rondas rondas, 2 procesos por ronda`n"
$insertadas = 0; $rechazadas = 0

for ($r = 1; $r -le $Rondas; $r++) {
    & $Php $script limpiar | Out-Null

    # Instante acordado: dentro de 6 s, para que ambos procesos ya estén conectados
    $instante = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds() + 6000

    $jobs = "A", "B" | ForEach-Object {
        Start-Job -ScriptBlock {
            param($php, $script, $raiz, $nombre, $instante)
            Set-Location $raiz
            [Console]::OutputEncoding = [System.Text.Encoding]::UTF8   # tildes de la salida de PHP
            & $php $script $nombre $instante 2>&1
        } -ArgumentList $Php, $script, $raiz, $_, $instante
    }
    $salida = $jobs | Wait-Job | Receive-Job
    $jobs | Remove-Job

    Write-Host "Ronda ${r}:"
    $salida | ForEach-Object { Write-Host "  $_" }
    $insertadas += @($salida | Select-String "INSERTADA").Count
    $rechazadas += @($salida | Select-String "11000").Count
}

& $Php $script limpiar
Write-Host "`nTotal: $insertadas insertadas, $rechazadas rechazadas por el índice único (esperado: $Rondas y $Rondas)."
