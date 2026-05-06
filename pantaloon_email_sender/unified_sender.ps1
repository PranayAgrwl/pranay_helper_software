# Force TLS 1.2 for modern security requirements
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# Load the raw content
$rawBody = Get-Content -Path "body.txt" -Raw
$rawSubject = Get-Content -Path "subject.txt" -Raw

# Regex to find /* and everything until */ including newlines
$commentRegex = "(?s)/\*.*?\*/"

# Clean the content
$cleanBody = $rawBody -replace $commentRegex, ""
$cleanSubject = $rawSubject -replace $commentRegex, ""

# Trim whitespace left over from the deleted comments
$cleanBody = $cleanBody.Trim()
$cleanSubject = $cleanSubject.Trim()

# ### ### ###

# Get the current month and year
$currentMonthYear = Get-Date -Format "MMMM yyyy"

# Replace the placeholder with the real date
$finalBody = $cleanBody -replace "\{current_month\}", $currentMonthYear
$finalSubject = $cleanSubject -replace "\{current_month\}", $currentMonthYear

# Optional: Print to console to verify it looks correct
Write-Host "Subject Preview: $finalSubject" -ForegroundColor Green

# ### ### ###

# --- 1. Load Credentials from .env ---
$envFilePath = Join-Path $PSScriptRoot ".env"
if (Test-Path $envFilePath) {
    Get-Content $envFilePath | Where-Object { $_ -match "=" -and $_ -notmatch "^#" } | ForEach-Object {
        $name, $value = $_.Split('=', 2)
        Set-Variable -Name $name.Trim() -Value $value.Trim() -Scope Script
    }
}

# --- 2. Email Configuration ---
$smtpServer = $SMTP_SERVER
$smtpPort = [int]$SMTP_PORT 
$displayName = $DISPLAY_NAME
$fromAddress = "$displayName <$EMAIL_USER>"

# --- 3. Load Recipients ---
$csvPath = Join-Path $PSScriptRoot "email_recipients.csv"
if (Test-Path $csvPath) {
    $recipients = (Import-Csv $csvPath).Email
} else {
    Write-Error "Recipient CSV missing!"
    return
}

# ### ### ###

# --- 4. Path Setup & Attachment Check ---
$pdfFolder = Join-Path $PSScriptRoot "attachements"
$backupFolder = Join-Path $PSScriptRoot "del_attachements"
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"

# Ensure backup folder exists
if (!(Test-Path $backupFolder)) { New-Item -ItemType Directory -Path $backupFolder | Out-Null }

# Get attachments if any exist
$attachments = Get-ChildItem -Path $pdfFolder -Filter "*.pdf"

# --- 5. Prepare Mail Parameters ---
$secPassword = $EMAIL_PASS | ConvertTo-SecureString -AsPlainText -Force
$creds = New-Object System.Management.Automation.PSCredential($EMAIL_USER, $secPassword)

$mailParams = @{
    From = $fromAddress
    To = $recipients
    Subject = $finalSubject
    Body = $finalBody
    SmtpServer = $smtpServer
    Port = $smtpPort
    UseSsl = $true
    Credential = $creds
}

# Add attachments only if files were found
if ($attachments) {
    $mailParams.Attachments = $attachments.FullName
    Write-Host "Found $($attachments.Count) attachment(s)." -ForegroundColor Cyan
} else {
    Write-Host "No attachments found. Sending as text-only email." -ForegroundColor Yellow
}

# ### ### ###

# --- 6. Send and Archive ---
try {
    Write-Host "Sending email to: $($recipients -join ', ')..." -ForegroundColor White
    Send-MailMessage @mailParams
    Write-Host "Email sent successfully!" -ForegroundColor Green

    # Only move files if there were attachments sent
    if ($attachments) {
        foreach ($file in $attachments) {
            $newName = "del_$($timestamp)_$($file.Name)"
            $destination = Join-Path $backupFolder $newName
            Move-Item -Path $file.FullName -Destination $destination
            Write-Host "Archived: $newName" -ForegroundColor Gray
        }
    }
} catch {
    Write-Error "Failed to send email. Error: $($_.Exception.Message)"
}

# --- Add this line to keep the window open ---
# Read-Host -Prompt "Press Enter to exit"

# ### ### ###
