$bytes = [IO.File]::ReadAllBytes('.env')
$cleanBytes = $bytes | Where-Object { $_ -ne 0 }
[IO.File]::WriteAllBytes('.env', $cleanBytes)
