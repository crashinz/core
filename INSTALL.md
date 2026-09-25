# Install ChatSpace Community Edition

ChatSpace Community Edition is designed for ordinary PHP hosting. If a host can run common PHP apps like WordPress or 4images, it should be in the right neighborhood for ChatSpace CE.

## Requirements

- PHP 8.2 or newer
- Apache, NGINX, LiteSpeed, or another web server capable of serving PHP
- SQLite through PDO SQLite, recommended
- Optional MySQL 5.7+ or MariaDB 10.3+ through PDO MySQL
- Writable `includes/`, `db/`, and `assets/uploads/` directories

## Required PHP Extensions

| Extension | Purpose |
| --- | --- |
| `pdo` | Database abstraction |
| `pdo_sqlite` | Recommended SQLite install path |
| `fileinfo` | Upload MIME validation |
| `gd` | Avatar and image handling |
| `mbstring` | Text handling |
| `openssl` | Password and session safety |

## Optional PHP Extensions

| Extension | Purpose |
| --- | --- |
| `pdo_mysql` | MySQL or MariaDB install path |
| `zip` | Database backup/package support |

## Tech Stack

- PHP 8.x
- SQLite or MySQL/MariaDB
- Vanilla JavaScript
- Long-polling for room, community, link, and DM events
- WebRTC for voice chat
- File uploads for avatars, room backgrounds, attachments, voice notes, and webcam frames

## Installation

1. Upload and extract ChatSpace Community Edition into the folder where it should run.

2. Visit `setup.php` in your browser.

3. Run Setup and select or configure your database.

   SQLite is recommended for most Community Edition installs. MySQL/MariaDB is available when your host or deployment requires it.

4. Create the first admin account.

   The first admin account requires a display name, avatar image, email address, and password.

5. Enter the lobby and create the first room.

   A room starts with a name and an optional image or video background. Room owners, developers, and admins can edit rooms later.

## Updating an Existing Installation

The normal update workflow is:

1. Optionally use the protected Site Owner **Prepare for Update** action.
2. Preserve the installation-specific `includes/config.php`, database, uploads,
   configured private storage, and any verified recovery set it creates.
3. Overwrite the application files.
4. Open the normal chat URL.

The shared **Enforce database/release compatibility before runtime** control is
under **Setup/Admin -> System -> Database & Backup** and defaults to Disabled.
When Disabled, opening the normal URL attempts ordinary runtime against the
configured database without first running the proactive release/schema
compatibility blocker. It does not automatically back up, migrate, repair,
recover, or claim compatibility; a genuine mismatch may therefore surface as
an ordinary PHP, query, or feature failure. Active recovery maintenance and
all unrelated security controls remain enforced.

When the control is Enabled, the protected compatibility workflow described
below runs before ordinary runtime. The same control remains available on the
owner-authenticated Update & Recovery page if ordinary Admin cannot load.
Changing it never migrates or rolls back the database. A normal manual
Enabled-to-Disabled change requires an inline risk confirmation; Restore
Default returns to Disabled without that additional confirmation.

**Prepare for Update** creates one private verified paired recovery set before
files are overwritten: a database recovery point and a snapshot of the matching
currently installed deployable application release. The application snapshot
uses ChatSpace's authoritative deployment inventory, records every normalized
path, byte size, and SHA-256 hash, and does not blindly archive the
installation. Its manifest binds the installation/release identity, database
engine and migration/schema state, creation time, database recovery
identity/checksums, compatibility, verification, and availability.

Recovery storage remains outside the public web root, Git, upload/deployment
packages, and private media storage. Application recovery never overwrites
`includes/config.php`, live databases, uploads, configured private storage,
backups, recovery/runtime state, evidence, or other installation-specific
content. When the host cannot safely support automatic database or application
restoration, ChatSpace remains in maintenance and provides exact bounded manual
instructions for the same verified pair.

SQLite recovery uses a verified staged database and the strongest transition
available on the host, retaining a private failed-state safety copy until
validation completes. MariaDB recovery first proves the deployment account can
create isolated staging/safety schemas and perform an atomic cross-schema
InnoDB table transition. If that bounded privilege proof fails, no production
table is changed and the owner page presents the verified recovery-point
identity and manual hosting-owner boundary.

With compatibility enforcement Enabled, ChatSpace opens normally when the
database is current. If a supported forward migration is pending, ChatSpace
enters bounded maintenance mode. Open the protected Site Owner Database Update
entry, authenticate as an administrator, review the exact status, then choose
**Back Up and Update Database**.

The update action creates and independently verifies a private server-side
database backup before any migration mutation. SQLite uses a consistent
snapshot. MariaDB streams a complete logical backup through the configured
database connection; the browser does not download and re-upload the backup.
The existing Database import/export feature remains an additional portability
and recovery tool and is not the migration backup.

Migrations are forward-only. ChatSpace does not automatically downgrade a
newer database. Unknown, inconsistent, incomplete-release, or unsupported
database objects fail closed. When automatic backup coverage is not safe,
obtain and verify a complete manual MariaDB backup before proceeding. Verified
migration backups are preserved in private storage and are not automatically
deleted.

## Subdirectory Installs

ChatSpace Community Edition is intended to run from a domain root or a hosted folder such as `/chat/`. The setup and routing helpers preserve the app base path so assets, API calls, and redirects continue to work from that hosted location.

## Web Server Hardening

Apache and LiteSpeed installs include `.htaccess` files that:

- Block direct web access to `db/` and `includes/`
- Disable directory indexes
- Disable PHP execution in `assets/uploads/`
- Block executable or browser-executable uploads such as PHP, SVG, HTML, JS, and CSS
- Send baseline security headers including `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, and a Content Security Policy that keeps the app same-origin while allowing configured GIF provider media

For NGINX, add equivalent rules to the server block:

```nginx
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(self), microphone=(self), geolocation=(), payment=(), usb=()" always;
add_header Content-Security-Policy "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https://*.giphy.com https://*.klipy.com https://api.klipy.com https://*.tenor.com https://tenor.googleapis.com https://media.tenor.com; font-src 'self'; connect-src 'self' https://api.giphy.com https://*.giphy.com https://api.klipy.com https://*.klipy.com https://tenor.googleapis.com https://*.tenor.com; frame-src 'self' https://www.youtube-nocookie.com https://www.youtube.com https://open.spotify.com https://w.soundcloud.com; child-src 'self' https://www.youtube-nocookie.com https://www.youtube.com https://open.spotify.com https://w.soundcloud.com" always;

location ^~ /db/ { deny all; }
location ^~ /includes/ { deny all; }

location ^~ /assets/uploads/ {
    autoindex off;
    location ~* \.(php[0-9]?|phtml|phar|cgi|pl|py|rb|asp|aspx|jsp|html?|shtml|xhtml|svgz?|js|mjs|css)$ {
        deny all;
    }
}
```

## Packaging Notes

The owner may maintain a local ignored `deployment/production-staging/` mirror
for manual hosting updates. Its state manifests compare SHA-256 content hashes;
each checkpoint upload folder contains only new or content-changed production
files. `delete-from-host.txt` is review guidance only. ChatSpace never connects
to hosting or deletes hosted files automatically.

During updates, preserve the hosted `includes/config.php`, SQLite or
MySQL/MariaDB data, all user uploads, configured private storage, runtime issue
screenshots, installation-specific state, and enabled private-player assets.
Preserve private migration backups, application release recovery snapshots,
paired recovery-set manifests, and migration/recovery state as
installation-specific data. Never place any of them under the public web root
or inside an upload/deployment package.
Upload the complete production `api/` tree when establishing a baseline. A
deterministic ZIP may be produced at a final checkpoint, but ZIP deployment is
optional and is not assumed by the staging workflow.

`.distignore` is the authoritative release-packaging exclusion record. Local
verification infrastructure, private player assets, generated configuration,
databases, credentials, logs, caches, Git metadata, and existing archives must
not be included in a shared release package.

macOS metadata such as `.DS_Store`, `__MACOSX`, `.AppleDouble`, and `._*` files are excluded from release packages.


## Administrator game review and frozen references

Administrators can open **Game review** from the room's Start a Game list. Choose a named example and appearance, then Start. Live actions use isolated practice state; they do not create matches, rankings, player records or shared game recordings. Reset repeats the prepared position.

The frozen replay uses saved renderers, media and state sequences, independent of current game code. Its files are checked against the versioned reference manifest. A changed or missing file is reported instead of silently using current artwork. The rules comparison is separate from visual and sound review.

The optional [reference pack v3](https://github.com/crashinz/core/releases/tag/game-review-v3) is a separate GitHub Release ZIP, not part of normal application file updates. In Game Review, expand **Reference pack**, download the ZIP, select it and choose **Install reference pack**. The installer uploads small chunks, verifies the pinned ZIP and every file, and installs into private storage. Allow about 331 MB of free storage during installation, plus up to 18 MB for all Classic references. PHP ZIP support is required. Existing matching files are reused; changed files are reported and never overwritten. Nothing downloads or updates automatically.

The public ZIP contains frozen code, prepared sequences, redistributable Built-in assets, and notices. It excludes original OCX media and personal reference images/clips. Choose **Copy installed Classic media** to complete each Classic game's references from that installation's validated artwork and sounds. Only files matching the frozen version are copied. Missing or different media affects that game's Classic comparison; Built-in references and other complete games remain available. Install missing media using the game's administrator controls, then retry. **Check installed references** reports Built-in and per-game Classic status. Older private packs remain available as backups. The current page uses the matching v3 pack.

Preserve and back up `game-review-snapshots` and `game-review-references` within the configured private storage root. Do not expose this storage through the web server or regenerate version 1 when updating games. For manual installation with the matching application, an administrator can extract the public ZIP's contents into private `game-review-snapshots/v3` after checking its published SHA-256; never overwrite differing existing files. The current application's `includes/game_review_snapshot_v3.json` pins the replay bytes. Live examples and the frozen rules comparison remain available without the optional pack. Interrupted browser uploads can be retried; starting another upload replaces that administrator's temporary upload, not installed references.

New verified image/clip references require an administrator's confirmation and note. Earlier revisions remain available. Technical checks do not replace administrator visual acceptance. Review view preferences are temporary and do not overwrite normal game preferences.


## Editing installed files and release checksums

You may edit installed extension and game source files without manually updating hashes. Extension manifests declare `requiredFiles`; release file checksums live together in `release-manifest.json`. Admin Settings > System > Release Checksums lists locally modified and unverified files. Required files must still exist and manifests must remain valid.

“Require matching extension checksums” is off by default. Enable it only when you want extensions with changed or unverified files to be blocked. Turn it off while customizing. The running application never rewrites the checksum inventory. Maintainers regenerate it from reviewed release files during packaging; a new hash means the bytes changed, not that a change was approved or tested.

Password and authorization hashes, game-record commitments, original-media identification, database recovery verification, and frozen administrator review references serve separate purposes and retain their existing checks. Updating a live release does not replace a frozen reference.


### Review unused media

An administrator can open **Storage Management → Find unused files** to review old avatar/nameplate, gesture, imported-room and room-background files. This is separate from duplicate detection: a duplicate can still belong to a saved library and is therefore protected here. Saved private/community items, selected images, retained gesture generations, room layouts/playlists and retained database references are checked without revealing protected private contents. Custom emojis, chat attachments, recordings, backups and temporary staging are outside this cleanup.

Scanning never removes files. Choose individual candidates, then confirm **Move selected to trash**. The service checks the file contents and references again and keeps recoverable bytes in the configured private storage directory under `unused-image-trash`. Back up that directory with private storage if recovery must survive a server migration. **View cleanup trash** offers restoration to the original path without overwriting another file, or separately confirmed permanent deletion. Trash is not automatically purged; moving into trash alone does not free disk space. Restoring bytes does not recreate a removed library or room entry.

Recent authentication and CSRF protection apply. Files younger than 24 hours, unsupported formats, files over 256 MiB, unreadable files and symbolic links are skipped. Unknown reference data stops cleanup. Conservative retained-reference matching can keep additional files; external links outside this application's database cannot be discovered. Use review and recoverable trash before permanent removal. No database migration is needed. Testing is WIP.

Unused-file review supports **Select this page**, **Select all results** across every page in the completed scan, and **Clear selection**. The summary and confirmation show total selected count and bytes. Selections persist across pages, but changing the folder filter requires a new scan and clears old selection. During a batch, **Stop after current file** finishes the submitted request and leaves unprocessed files selected. Skipped/failed items remain selected for review or retry; starting another batch requires a new confirmation. Scanning never selects files automatically.

Reference pack v2 retains every prepared sequence and updates only the approved Five Dice dice presentation. Install the current application before this ZIP. Version 2 installs alongside private v1; it never overwrites it. Matching Classic media in v1 is copied privately to v2 after hash verification. Keep v1 as a local backup.

Reference pack v3 adds 37 approved Pool examples (8 Ball and 9 Ball) without replacing the 316 earlier frozen sequences or their resources. Install the matching application first. The installer reuses verified Classic media from private v2 and preserves that older folder. Manual installation belongs in private game-review-snapshots/v3, never under the public application directory. The application directory may have any name.

### Optional two-factor authentication

Install the matching application files and run the normal protected database update. 2FA is off for existing and new accounts until each user confirms enrollment. Users can opt in during registration, use **Set up 2FA** on the login page after signing in, or open **Account > Security & Privacy**. Aegis and other standard TOTP apps can scan the locally generated QR or enter the manual key (SHA-1, six digits, 30 seconds). Keep server time synchronized.

Users can copy or download ten single-use backup codes as a text file. These replace the authenticator code during sign-in, not the password. Replacing the backup list invalidates the old list. A password reset does not disable 2FA. Authenticator setup, disabling and backup replacement are personal account actions, independent of Private Chat Protection and its recovery phrase.

Authenticator secrets are encrypted using the installation-private `two-factor/key-v1.bin` file, outside the public application folder under the configured private storage root. Back up that key with private host storage and the corresponding database; a database-only backup does not contain it. Keep the key out of public folders, Git and application ZIPs. Do not regenerate or delete it when moving/upgrading an existing installation. A missing key fails closed for authenticator verification; an unused backup code can still be used. Without either the matching key/authenticator or usable backup codes there is no password-only 2FA bypass.

### Optional lost-authenticator email recovery

Normal disabling still accepts the current password and an authenticator or unused backup code. Email recovery is a separate, optional route. It is off by default and is not retroactively added to existing 2FA enrollments.

1. Configure `CHATSPACE_ACCOUNT_MAIL_*` and `CHATSPACE_ACCOUNT_SMTP_*` in your installation's private `includes/config.php`, following the commented example in `includes/config.sample.php`. Use your actual installed application URL for `CHATSPACE_ACCOUNT_MAIL_BASE_URL` (including its subfolder). Credentials belong only in private host configuration, never in Git or application archives. The SMTP transport supports authenticated TLS on port 587 (`tls`) or implicit TLS on port 465 (`ssl`). Certificate verification stays enabled. Alternatively, choose `mail` only when your host has configured PHP's mail transport. Plain SMTP and HTTP links are allowed only for loopback testing; hosted recovery links require HTTPS.
2. Install the matching application files and run the protected database update. Verify your private account email from **Account → Security & Privacy** to test real delivery. SMTP acceptance does not guarantee inbox delivery; check spam filtering and your provider's sender requirements.
3. As Installation Owner, open **Admin → Settings → Moderation, Privacy & Security → Account Recovery** and enable **Email recovery for new 2FA enrollments**. The switch refuses enabling when mail configuration is incomplete. New enrollments must verify their account email first and are warned to retain access to it. Existing enrollments remain unchanged; a user who wants this recovery option can disable and re-enroll after the option is enabled. Turning the switch off only affects later enrollments; previously eligible users retain recovery.

For an existing installation, add the following constants to its private `includes/config.php`, replacing the example values with your provider's details. Do not replace your existing database configuration or publish this file. Set mail enabled to `true` when those details are ready:

```php
const CHATSPACE_ACCOUNT_MAIL_ENABLED = false;
const CHATSPACE_ACCOUNT_MAIL_FROM = 'accounts@example.com';
const CHATSPACE_ACCOUNT_MAIL_BASE_URL = 'https://example.com/core';
const CHATSPACE_ACCOUNT_MAIL_TRANSPORT = 'smtp';
const CHATSPACE_ACCOUNT_SMTP_HOST = 'smtp.example.com';
const CHATSPACE_ACCOUNT_SMTP_PORT = 587;
const CHATSPACE_ACCOUNT_SMTP_SECURITY = 'tls';
const CHATSPACE_ACCOUNT_SMTP_USERNAME = '';
const CHATSPACE_ACCOUNT_SMTP_PASSWORD = '';
```

After entering the correct password, eligible users can select **Lost your authenticator?** and request a code at their already verified private account email. Verification and recovery each allow one email request per rolling 24 hours. Each code expires after 15 minutes, permits five attempts, and can be verified once. An explicit transport failure permits retry after one minute; an accepted send uses the daily allowance. Existing authentication attempt protections also apply.

Confirming the recovery code starts a 24-hour waiting period; it does not disable 2FA or sign the user in. The email provides a cancellation link and a private completion link. Keep that email. After waiting, the user must sign in with the password again and finish in the confirming browser, or use the private email link if their browser/session changed. A password alone cannot finish another person's pending recovery. Completion is available for seven days after the waiting period. An email-link GET never disables 2FA or cancels a request; confirmation is required. A signed-in user can also cancel recovery in Account Security. Normal authenticator/backup sign-in and disabling remain available during the wait.

Completing recovery removes the authenticator and all old 2FA backup codes, invalidates previous sessions, and requires a fresh sign-in. The user can then enroll a new authenticator. Password changes, email changes, and authenticator replacement invalidate mismatched recovery requests. Changing the private account email requires the existing MFA through the normal account form and clears its verification; verify the new address before email recovery becomes available again. Public profile contact email is unrelated. Encrypted-chat device keys and its recovery phrase are unchanged.


## Encrypted site-data backups and selected imports

### Keeping original Classic resources for future updates

#### Automatic server-folder installation

Put supported original OCX files in **`ocx-inbox` inside your configured private-storage root** (`CHATSPACE_PRIVATE_STORAGE_PATH`). With the default layout, that is `../chatspace-private/ocx-inbox` relative to the CoreChat application folder. This folder must remain outside the public website. The Installation Owner can see the exact server path, accepted filenames and last check in **Admin settings → Classic artwork & sound → Automatic server-folder import**.

Accepted filenames (case-insensitive): `Checkers.ocx`, `Chess.ocx`, `Acey Deucy.ocx`, `Backgammon.ocx`, `Battleship.ocx`, `Spades.ocx`, and `Yahtzee.ocx`. Five Dice also accepts and prefers `Yahtzee-mychange.ocx`. For an alternative original copy, use its game's accepted name. Upload under a temporary name such as `Checkers.ocx.part`, then rename when the upload finishes.

Checks run after initial setup finishes creating the owner account, after a successful database update, and once every 24 hours on the next site request. No cron configuration or new code is needed. At most one heavy import runs after each response; additional games resume on subsequent site requests. An unused/offline site is checked when it is next used. Fully installed games are skipped **before reading their inbox OCX**; completion includes currently supported optional media. An incomplete active pack may be repaired; a failed source leaves the installed generation intact. A failed game does not stop the others. Installation Owners can also unlock settings and use **Check inbox again**.

Imported resources live under private storage in `five-dice-media-pack-generations/generation-…` for Five Dice and `ocx-game-<game-id>-media-generations/generation-…` for the other games. Do not manually populate these generated directories: activation is recorded in the database. Only inert artwork, audio and archived resource bytes are installed; OCXs are never registered or executed. The original files you placed in the inbox remain there, separate from installed generations. Successful sources are remembered, so **Remove Classic media** is not undone by an unchanged consumed inbox file. Use the normal owner import controls to deliberately replace/reinstall a complete or removed pack. Installed resources are included in Classic-media backups; the inbox originals are not a substitute for keeping your own source copies.

Import one original OCX per game through its Installation Owner media controls. The installer retains all static resources in the game's installation-private media generation, including artwork and sounds not currently used. It discards the executable OCX container. Future application updates can prepare newly supported media from the retained resources; only validated, explicitly supported slots are served to players. Prepared folders still work, but cannot supply resources they do not contain.

Existing installations need one updated original-OCX import to capture resources older installers discarded. Afterwards, ordinary code updates do not require another media import for those retained resources. Removing or replacing the game's media pack also removes or replaces its retained resources. Complete site backups and selected **Classic media** backups include them.


Open the administrator Database & Backup controls and use **Complete backup and selected content**. Download a `.corechat` archive with a password of at least 12 characters. Every archive entry, including its manifest, uses AES-256 ZIP encryption. Keep the password separately; the site cannot recover it.

- **Complete site data and media** saves every database table plus supported uploaded media, installed Classic artwork/sounds, game recordings and installed review references. It includes the private keys needed by server-side account security. It does not package application code, `includes/config.php`, host credentials, session cookies, temporary uploads, older backups, or encryption keys held only in a user's browser. Keep the matching application release and host configuration separately.
- **Selected content** lets an administrator choose accounts/profiles, avatar or nameplate libraries, gestures, custom emojis, rooms, site settings, game preferences/Pool setups, Classic media and installed review references. Personal/community ownership, sections and visibility are retained. Owners must be included or already exist with matching email and username. Importing accounts from another installation requires compatible username identities; conflicts are reported, never guessed.
- Selective import supports current-schema SQLite and MariaDB installations. Complete restore requires the same database type and matching application schema. On a new host, install the matching release, create a temporary administrator, sign in and restore the complete archive. Then sign in using an account from that backup.

Choose the archive and its password, then **Preview import**. For a selected archive, choose which included sections to import and preview again. The preview lists record/file additions, updates and skips. **Keep existing** is the default; **Replace selected content** changes selected content while preserving existing account passwords, roles and two-factor authentication. Newly imported accounts retain their authentication setup, with authenticator secrets re-encrypted for the destination. Repeated records with matching stable identities and identical files are skipped. Different file contents at the same path are rejected; this is not a global content-deduplication tool.

Every apply first creates an encrypted complete recovery archive in private storage. **Pre-import recovery backups** lists downloads after signing in. Each recovery archive uses the password supplied for that import. Complete restore replaces database records and backed-up files, ends previous sign-ins and expires old room presence; unrelated existing media files are retained. Original recovery files remain available when recovery cannot finish safely. Existing database-only SQLite and portable JSON tools remain under **Older database and portable JSON tools**; they do not cover all modern libraries or private account keys.

Requirements: PHP ZIP with AES-256 support, writable private storage outside the public web root, and transactional InnoDB tables for MariaDB. The archive's uncompressed size is limited by the configured Database import size limit (512 MB by default), with at most 100,000 archive entries, a 16 MB manifest/individual record limit and a 250,000-record interactive selective-import limit. PHP upload/request limits and available server/browser memory also apply. Keep enough disk space for the archive, recovery archive and staged copies. Run backups/restores during a quiet period: other requests briefly receive a retry message while the exclusive backup/restore lock is held. Unknown formats, missing dependencies and damaged files fail before import; database/file changes use rollback and an interruption-recovery journal.
