# snippetylickety
Small scripts and things Jo has co-created and wants to share

#Secure Phone-to-Desktop Password Transfer#
A tiny, single-file PHP tool for securely transferring long passwords or secrets from your phone to your desktop browser - without typing them manually.

End-to-end encryption using AES-256-GCM derived from a short shared token
Desktop shows a QR code - phone scans to open send page automatically
Auto-copy to clipboard on reveal
Hides secret immediately after copy (or timeout)
No accounts, no persistent storage, no external dependencies beyond browser crypto

Features

Universal QR code (plain HTTPS link) - works with any phone camera
Phone can send before desktop loads (phone-first flow)
Keyboard shortcuts: E = reveal + auto-copy, D = force cleanup
10-second countdown after reveal if no copy detected
Files auto-delete after ~60 seconds or on success

Requirements

PHP 8.0+ (with OpenSSL extension)
HTTPS enabled (Web Crypto API requires secure context)
Writable directory for temporary ciphertext files

Installation

Upload the file as index.php to your server (e.g. /public_html/ or a subdomain folder)
Edit the CONFIG block at the top of index.php:define('BASE_URL',          'https://yourdomain.com');     // your domain
define('STORAGE_DIR',       '/home/yourdomain/folder/');    // writable folder
Make sure STORAGE_DIR is writable by the web server:
chmod 775 /path/to/storage/dir
chown www-data:www-data /path/to/storage/dir   # or your web user
That's it - no database, no composer, no extra files.

Usage
Normal flow (recommended)

On desktop browser:
Open https://yourdomain.com/?init=abcdef12
-> See QR code + waiting message
On phone:
Scan the QR with camera -> tap the link
-> Phone opens send page with token pre-filled
On phone:
Paste your long password/code -> click Encrypt & Send
On desktop:
Secret appears blurred -> press E to reveal + auto-copy
-> Immediately hides + "Server cleaned up ✓"
-> Or press D anytime to force cleanup

Alternate: Manual phone entry (no QR)

Phone manually opens: https://yourdomain.com/?pong=abcdef12
Then paste & send as above

Security Notes

Encryption: AES-256-GCM with PBKDF2-derived key from token (~600k iterations)
Server: Only sees/stores base64-encoded ciphertext (never plaintext)
Ephemeral: Files deleted after success, 'D' key, or 60 seconds
Threat model: Protects against shoulder surfing, keyloggers, casual network snooping
(Not against compromised devices or very powerful adversaries)

Important: Use a random 8-12 character token each time (e.g. x7k9p2m4q8r3).
Short/weak tokens can be brute-forced.
Customization Ideas

Change COUNTDOWN_SECONDS for longer/shorter visibility
Adjust QR size (width/height in new QRCode(...))
Add a random token generator button on desktop page
Replace QR library CDN with local copy for offline use

License
AGP+ - feel free to use, modify, share.
Made with heart in Wellies, NZ - March 2026 with the help of Grok and CoPilot
