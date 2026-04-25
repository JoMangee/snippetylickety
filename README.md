# snippetylickety

Small scripts and things Jo has co-created and wants to share.

## Repository Contents

### `pdrop/` - Secure Phone-to-Desktop Password Transfer

A tiny, single-file PHP tool for securely transferring long passwords or secrets from your phone to your desktop browser without typing them manually.

Features:

- End-to-end encryption using AES-256-GCM derived from a short shared token
- Desktop shows a QR code so the phone can open the send page directly
- Auto-copy to clipboard on reveal
- Hides the secret immediately after copy or timeout
- No accounts, no persistent storage, no external dependencies beyond browser crypto
- Universal QR code flow that works with any phone camera
- Phone-first flow supported
- Keyboard shortcuts: `E` to reveal and auto-copy, `D` to force cleanup
- Automatic file cleanup after about 60 seconds or on success

![Secure Phone-to-Desktop Password Transfer screenshot](https://github.com/user-attachments/assets/ee765b26-cac0-400a-a9ac-7e9674830934)

Requirements:

- PHP 8.0+ with the OpenSSL extension
- HTTPS enabled because Web Crypto requires a secure context
- A writable directory for temporary ciphertext files

Installation:

1. In this repository, the file lives at `pdrop/index.php` for organization.
1. For deployment, you can place that file directly at the document root of your `pdrop` subdomain as `index.php`. You do not need a `pdrop/` directory on the server if the subdomain itself points at the app root.
1. Edit the config block at the top of the deployed file:

```php
define('BASE_URL', 'https://pdrop.yourdomain.com');
define('STORAGE_DIR', '/home/yourdomain/folder/');
```

1. Make sure `STORAGE_DIR` is writable by the web server.

```bash
chmod 775 /path/to/storage/dir
chown www-data:www-data /path/to/storage/dir
```

That is it. No database, Composer setup, or extra services required.

Usage:

1. On desktop, open `https://pdrop.yourdomain.com/?init=abcdef12`.
1. On phone, scan the QR code and open the generated link.
1. Paste the secret on the phone and press `Encrypt & Send`.
1. Back on desktop, press `E` to reveal and auto-copy, or `D` to force cleanup.

Manual phone entry:

- Open `https://pdrop.yourdomain.com/?pong=abcdef12` on the phone and send as above.

Security notes:

- Encryption uses AES-256-GCM with a PBKDF2-derived key from the shared token.
- The server only sees base64-encoded ciphertext, never plaintext.
- Files are ephemeral and are deleted after success, the `D` key, or timeout.
- Use a random 8 to 12 character token each time. Short or weak tokens can be brute-forced.

### `ICAM-test.html` - Industrial Camera Test Page

`ICAM-test.html` is a standalone browser-based camera tool intended for tablet or kiosk-style testing.

It provides:

- Live camera preview
- Snapshot capture
- Local thumbnail review
- Per-image download and delete controls
- Bulk save for captured images

Usage:

- Open `ICAM-test.html` in a modern browser.
- Allow camera access when prompted.
- Use the on-screen controls to start the camera, take snapshots, and save images locally.

## License

AGP+ - feel free to use, modify, and share.

Made with heart in Wellies, NZ - March 2026 with the help of Grok and Copilot.
