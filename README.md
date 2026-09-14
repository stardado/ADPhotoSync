# ADPhotoSync

An eFront plugin that fills in user profile pictures from Active Directory.

The photo is read from the `thumbnailPhoto` attribute over LDAP and written back
as an ordinary eFront avatar. People are matched by mail address: the plugin
takes both the eFront login and the profile address and looks them up against
every configured directory attribute, so someone is found whichever of their
addresses the two systems happen to agree on.

The plugin is self-contained. It carries its own directory credentials and does
not depend on any single sign-on or user-import configuration in the host
application.

## Requirements

- eFront 5.2.18 or newer (developed against 7.8.3)
- PHP 8.0 or newer with the `ldap`, `gd` and `json` extensions
- A read-only service account that may bind to the directory and read
  `thumbnailPhoto`

No ImageMagick and no `ldapsearch` binary are needed; the plugin uses PHP's own
LDAP and GD extensions.

## Installation

Pack the contents of this repository into a ZIP named `ADPhotoSync.zip` — with
`plugin.ini` at the archive root, not inside a folder — and upload it under
**Administrator → Plugins → Install new plugin**.

The file name matters. eFront extracts the upload into a directory named after
it and then looks for the class declared in `plugin.ini` at
`<plugins>/ADPhotoSync/Model/ADPhotoSyncPlugin.php`. A name like
`ADPhotoSync-1.0.zip` extracts to the wrong directory, and the installer fails
with the unhelpful message *"Plugins with custom definitions cannot be
automatically installed"*.

Then open the **AD Photo Sync** tile on the administrator dashboard, fill in the
directory settings, press **Test connection**, and import a single user to check
the result before enabling automatic synchronisation.

## How it works

Runs are triggered by eFront's own cron job, so no separate system cron entry is
required. Each run:

1. Selects active, non-archived users whose login looks like a mail address,
   skipping secondary accounts and any login matching the exclusion patterns.
2. Looks the person up by every address the LMS holds for them, against every
   configured directory attribute.
3. Compares an MD5 of the photo with the one stored from the previous run and
   skips the user when nothing changed.
4. Renders three PNG sizes with GD and writes them to the avatar.

### Things worth knowing

**eFront stores avatars as base64 PNG data URIs in TEXT columns**, not as binary
blobs, and not as JPEG.

**An existing avatar row is updated in place.** Creating a new row and
repointing `users.avatars_ID` leaves the old picture visible, because the
interface caches per avatar; the `hash` column is what busts that cache.

**Secondary accounts** — a base login plus an underscore and a suffix, such as
`someone@example.com_mdt` — are not looked up on their own, since that is not a
real address. They receive the base account's picture instead. The first
underscore after the `@` marks the suffix; one before the `@` belongs to the
mail address and is left alone.

**Addresses that changed.** After a rename, Exchange keeps the former address as
a secondary SMTP address and makes the new one primary, so a search on `mail`
alone no longer finds the person. Listing `proxyAddresses` under **Mail
attributes** covers every address of the mailbox.

**Restart PHP after updating the plugin.** gettext loads a translation catalogue
once per process and keeps it, so parts of the interface fall back to English
until the workers are replaced. The settings page detects this and says so.

## Settings

| Setting | Default | Meaning |
| --- | --- | --- |
| Directory server | — | e.g. `ldaps://dc1.example.com:636` |
| Bind user | — | e.g. `DOMAIN\svc_ldap` |
| Bind password | — | Can be supplied through `AD_PHOTO_SYNC_BIND_PASSWORD` instead, keeping it out of database dumps |
| Search base (DN) | — | The OU holding the user accounts |
| Mail attributes | `mail, proxyAddresses` | Searched for the address, comma separated |
| Photo attribute | `thumbnailPhoto` | Attribute holding the picture |
| Verify the TLS certificate | off | Internal CAs are usually not trusted by the server |
| Skip logins matching | — | Comma separated patterns, `*` allowed, e.g. `*@example.org` |
| Enable automatic synchronisation | off | Whether the cron hook does anything |
| Users per run | 100 | Upper bound on users examined per run |
| Minimum hours between runs | 24 | Throttles the cron hook |
| Retry users without a photo after (hours) | 168 | Avoids re-reading the directory for accounts with no picture |
| Replace photos that users uploaded themselves | off | Off by default; pictures uploaded by hand are preserved |

Uninstalling drops the plugin's own tables but deliberately leaves the written
avatars in place, since removing them would blank out profiles.

## Translations

English is the source language; German is supplied in
`i18n/de_DE/LC_MESSAGES/`. A language without a catalogue falls back to English.
To add one, copy the `de_DE` folder, translate the `msgstr` lines and compile the
`.po` to a `.mo`.

## Licence

MIT — see [LICENSE](LICENSE).

## Disclaimer

This software is provided "as is", without warranty of any kind, express or
implied. It is published in the hope that it is useful to others running eFront
against an Active Directory, but it was written for one specific installation
and is not a supported product.

It writes to the `users` and `avatars` tables of your eFront database. Try it
against a test system first, take a backup, and use **Check only, write
nothing** and the single-user import to see what it would do before enabling
automatic synchronisation. The authors accept no liability for any damage or
data loss arising from its use.

© 2026 Denis Apel
