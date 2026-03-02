<?php
/*
    Secure one-file pairing drop with client-side AES-GCM + Desktop QR mode
    =========================================================================

    PURPOSE
    -------
    Frictionless, encrypted transfer of a secret from phone → desktop browser.
    - Token-derived AES-256-GCM encryption (end-to-end)
    - Server stores only ciphertext
    - Desktop shows universal QR link for phone to scan
    - Phone pastes secret → encrypts + sends
    - Desktop auto-hides after copy / timeout / 'D' key

    CONFIGURATION
    -------------
    Edit ONLY the CONFIG block below.
    All other paths/URLs are derived from these values.

    TEST STEPS
    ----------
    1. Desktop:  https://YOURDOMAIN.com/?init=abcdef12
       → Shows QR + waiting message

    2. Phone: Scan QR with camera → tap link
       → Opens phone send page

    3. Phone: Paste secret → "Encrypt & Send"

    4. Desktop: Secret appears blurred → press E to reveal/copy
       → Auto-hides on copy, or press D to force cleanup

    Enjoy!
*/

// ────────────────────────────────────────────────
//          CONFIG – change only here!
// ────────────────────────────────────────────────
define('BASE_URL',          'https://YOURDOMAIN.com');     // your domain (with https://)
define('STORAGE_DIR',       '/home/yourdomain/folder/');    // writable directory for temp files
define('STORAGE_PREFIX',    'pair-');
define('STORAGE_SUFFIX',    '.dat');
define('MAX_FILE_AGE',      60);                          // seconds before auto-delete
define('PBKDF2_ITER',       600000);
define('SALT_STR',          'pairing-salt-v1-2026-march');
// ────────────────────────────────────────────────

function get_storage_path($token) {
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
    if (strlen($safe) < 4 || strlen($safe) > 32) return false;
    return STORAGE_DIR . STORAGE_PREFIX . $safe . STORAGE_SUFFIX;
}

function cleanup_old_file($path) {
    if (file_exists($path) && filemtime($path) < time() - MAX_FILE_AGE) {
        @unlink($path);
        return true;
    }
    return false;
}

$token = $_GET['init'] ?? $_GET['pong'] ?? $_GET['poll'] ?? $_GET['done'] ?? null;
if (!$token || strlen($token) < 4) {
    http_response_code(204);
    exit;
}

$path = get_storage_path($token);
if ($path === false) {
    http_response_code(400);
    exit;
}

// INIT – desktop waiting page with QR
if (isset($_GET['init'])) {
    http_response_code(200);
}

// PONG – phone send form
if (isset($_GET['pong'])) {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        cleanup_old_file($path);
        $b64 = trim($_POST['payload'] ?? '');
        if ($b64 && strlen($b64) < 8192 && base64_decode($b64, true) !== false) {
            if (!file_exists($path)) file_put_contents($path, '');
            file_put_contents($path, $b64);
            echo '<!DOCTYPE html><html><head><meta charset=utf-8></head><body>'
               . '<h3 style="color:#2e7d32;text-align:center;margin-top:4rem;">Sent securely ✓</h3>'
               . '</body></html>';
        } else {
            http_response_code(400);
            echo '<!DOCTYPE html><html><head><meta charset=utf-8></head><body>'
               . '<h3 style="color:#c62828;text-align:center;margin-top:4rem;">Invalid payload</h3>'
               . '</body></html>';
        }
        exit;
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Secure Transfer</title>
        <style>
            body{font-family:system-ui,sans-serif;max-width:520px;margin:2rem auto;padding:1rem;line-height:1.5;}
            textarea{width:100%;font-family:monospace;font-size:1.1rem;padding:0.8rem;border:1px solid #ccc;border-radius:6px;resize:vertical;}
            button{width:100%;padding:0.9rem;font-size:1.1rem;background:#1e88e5;color:white;border:none;border-radius:6px;cursor:pointer;margin-top:1rem;}
            button:hover{background:#1565c0;}
            #status{min-height:2.5rem;margin-top:1.5rem;font-weight:bold;}
        </style>
    </head>
    <body>
        <h2>Send secret to computer</h2>
        <p>Encrypted in your browser before sending.</p>

        <textarea id="secret" rows="7" placeholder="Paste password, code or secret here..."></textarea>

        <button onclick="send()">Encrypt & Send</button>

        <div id="status"></div>

        <script>
        const token = "<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>";
        const PBKDF2_ITER = <?= PBKDF2_ITER ?>;
        const SALT = new TextEncoder().encode("<?= htmlspecialchars(SALT_STR, ENT_QUOTES, 'UTF-8') ?>");

        async function send() {
            const plaintext = document.getElementById('secret').value.trim();
            if (!plaintext) return alert("Nothing to send");

            const status = document.getElementById('status');
            status.innerHTML = "Encrypting...";

            try {
                const pwBytes = new TextEncoder().encode(token);
                const baseKey = await crypto.subtle.importKey("raw", pwBytes, "PBKDF2", false, ["deriveKey"]);

                const aesKey = await crypto.subtle.deriveKey(
                    {name:"PBKDF2", salt:SALT, iterations:PBKDF2_ITER, hash:"SHA-256"},
                    baseKey, {name:"AES-GCM", length:256}, false, ["encrypt"]
                );

                const iv = crypto.getRandomValues(new Uint8Array(12));
                const encoded = new TextEncoder().encode(plaintext);

                const ctBuffer = await crypto.subtle.encrypt(
                    {name:"AES-GCM", iv, tagLength:128},
                    aesKey,
                    encoded
                );

                const combined = new Uint8Array(iv.byteLength + ctBuffer.byteLength);
                combined.set(iv);
                combined.set(new Uint8Array(ctBuffer), iv.byteLength);

                const b64 = btoa(String.fromCharCode(...combined));

                const formData = new FormData();
                formData.append('payload', b64);

                const resp = await fetch(`?pong=${encodeURIComponent(token)}`, {
                    method: 'POST',
                    body: formData
                });

                status.innerHTML = resp.ok
                    ? '<span style="color:#2e7d32">Sent securely ✓</span>'
                    : '<span style="color:#c62828">Error – try again</span>';
            } catch(e) {
                status.innerHTML = '<span style="color:#c62828">Encryption failed: ' + e.message + '</span>';
            }
        }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// POLL – desktop receives ciphertext
if (isset($_GET['poll'])) {
    cleanup_old_file($path);
    if (file_exists($path)) {
        $b64 = file_get_contents($path);
        if ($b64 && $b64 !== '') {
            @unlink($path);
            header('Content-Type: text/plain');
            echo $b64;
            exit;
        }
    }
    http_response_code(204);
    exit;
}

// DONE – explicit cleanup
if (isset($_GET['done'])) {
    if (file_exists($path)) @unlink($path);
    http_response_code(205);
    exit;
}

// Desktop waiting page with QR
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Secure Transfer – Scan with Phone</title>
    <style>
        body{font-family:system-ui,sans-serif;text-align:center;padding:2rem 1rem;background:#f8f9fa;}
        h2{color:#333;}
        #qrContainer{margin:2rem auto;max-width:300px;}
        #secret{display:none;max-width:90%;margin:2rem auto;padding:1.5rem;background:white;border:1px solid #ddd;border-radius:8px;white-space:pre-wrap;word-break:break-all;font-family:monospace;font-size:1.1rem;min-height:6rem;cursor:pointer;}
        #hint{color:#555;margin:1rem 0;}
        #status{color:#666;font-size:0.95rem;margin-top:1rem;}
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>
    <h2>Scan this QR with your phone</h2>
    <div id="qrContainer"><div id="qrcode"></div></div>
    <p id="hint">Or go to: <code><?= BASE_URL ?>/?pong=<?= htmlspecialchars($token ?? '', ENT_QUOTES) ?></code> on phone</p>

    <div id="secret">•••••••••••• (press E to reveal)</div>
    <div id="status">Waiting for secret...</div>

    <script>
    const token = "<?= htmlspecialchars($token ?? '', ENT_QUOTES, 'UTF-8') ?>";
    const statusEl = document.getElementById('status');
    const secretEl = document.getElementById('secret');

    // Show QR with universal link
    new QRCode(document.getElementById('qrcode'), {
        text: `<?= BASE_URL ?>/?pong=${encodeURIComponent(token)}`,
        width: 280,
        height: 280,
        colorDark: "#000000",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.M
    });

    const PBKDF2_ITER = <?= PBKDF2_ITER ?>;
    const SALT = new TextEncoder().encode("<?= htmlspecialchars(SALT_STR, ENT_QUOTES, 'UTF-8') ?>");

    let revealed = false;
    let countdownActive = false;
    let countdownTimer = null;
    let cleanedUp = false;
    const COUNTDOWN_SECONDS = 10;

    async function cleanup() {
        if (cleanedUp) return;
        cleanedUp = true;
        await fetch(`?done=${encodeURIComponent(token)}`).catch(()=>{});
        statusEl.textContent = "Server cleaned up ✓";
        secretEl.textContent = "Secret hidden";
        secretEl.style.filter = 'blur(8px)';
        secretEl.style.background = '#eee';
    }

    function startCountdown() {
        if (countdownActive) return;
        countdownActive = true;
        let remaining = COUNTDOWN_SECONDS;

        secretEl.textContent = `Visible (${remaining}s) – press E again to keep visible`;

        countdownTimer = setInterval(() => {
            remaining--;
            if (remaining > 0) {
                secretEl.textContent = `Visible (${remaining}s) – press E again to keep visible`;
            } else {
                clearInterval(countdownTimer);
                countdownActive = false;
                cleanup();
                secretEl.textContent = "Auto-hidden (timeout)";
                secretEl.style.filter = 'blur(8px)';
            }
        }, 1000);
    }

    function stopCountdown() {
        if (countdownTimer) clearInterval(countdownTimer);
        countdownActive = false;
        secretEl.textContent = "Visible – ready to copy (press D to cleanup)";
    }

    async function poll() {
        try {
            const r = await fetch(`?poll=${encodeURIComponent(token)}`);
            if (r.status === 200) {
                const b64 = await r.text();
                if (!b64) return;

                statusEl.textContent = "Secret received – decrypting...";

                const blob = Uint8Array.from(atob(b64), c => c.charCodeAt(0));
                const iv  = blob.subarray(0, 12);
                const ct  = blob.subarray(12);

                const pwBytes = new TextEncoder().encode(token);
                const baseKey = await crypto.subtle.importKey("raw", pwBytes, "PBKDF2", false, ["deriveKey"]);

                const aesKey = await crypto.subtle.deriveKey(
                    {name:"PBKDF2", salt:SALT, iterations:PBKDF2_ITER, hash:"SHA-256"},
                    baseKey, {name:"AES-GCM", length:256}, false, ["decrypt"]
                );

                const ptBuffer = await crypto.subtle.decrypt(
                    {name:"AES-GCM", iv, tagLength:128},
                    aesKey,
                    ct
                );

                const text = new TextDecoder().decode(ptBuffer);

                secretEl.style.display = 'block';
                secretEl.style.filter = 'blur(6px)';
                secretEl.textContent = "•••••••••••• (press E to reveal)";

                const revealAndTryCopy = async () => {
                    if (revealed && countdownActive) {
                        stopCountdown();
                        return;
                    }
                    if (revealed) return;

                    revealed = true;
                    secretEl.style.filter = 'none';

                    try {
                        await navigator.clipboard.writeText(text);
                        statusEl.textContent = "Auto-copied ✓ – cleaning up";
                        secretEl.textContent = "Copied successfully";
                        secretEl.style.filter = 'blur(8px)';
                        await cleanup();
                    } catch (err) {
                        statusEl.textContent = "Auto-copy blocked – Ctrl+C or E to pause countdown";
                        startCountdown();

                        const onCopy = () => {
                            statusEl.textContent = "Copy detected – hiding & cleaning up";
                            secretEl.textContent = "Copied & hidden";
                            secretEl.style.filter = 'blur(8px)';
                            cleanup();
                            document.removeEventListener('copy', onCopy);
                        };
                        document.addEventListener('copy', onCopy, { once: true });
                    }
                };

                secretEl.onclick = revealAndTryCopy;

                document.addEventListener('keydown', e => {
                    if (e.key.toLowerCase() === 'e') {
                        e.preventDefault();
                        revealAndTryCopy();
                    }
                    if (e.key.toLowerCase() === 'd') {
                        e.preventDefault();
                        statusEl.textContent = "Manual cleanup (D key)";
                        cleanup();
                        secretEl.textContent = "Manually cleared";
                        secretEl.style.filter = 'blur(8px)';
                    }
                });
            }
        } catch(e) {}
    }

    setInterval(poll, 1500);
    poll();
    </script>
</body>
</html>
