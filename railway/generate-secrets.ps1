# Prints fresh secrets to paste into Railway's shared variables (Windows).
# Right-click the file → "Run with PowerShell", or paste these lines into PowerShell.
# Keep the output in your password manager; don't commit it anywhere.
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
function New-Secret([int]$Bytes) { $b = New-Object byte[] $Bytes; $rng.GetBytes($b); [Convert]::ToBase64String($b) }
"APP_KEY=base64:" + (New-Secret 32)
"POD_CARD_SECRET=" + (New-Secret 48)
"POD_ID_HASH_KEY=" + (New-Secret 48)
"SOCKETCLUSTER_AUTH_KEY=" + (New-Secret 48)
""
"POD_CARD_SECRET signs the printed farmer cards and POD_ID_HASH_KEY protects ID numbers:"
"changing either later invalidates every printed card / stops matching IDs. Back them up."
Read-Host "Press Enter to close"
