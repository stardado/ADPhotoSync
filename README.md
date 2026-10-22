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

- PHP with the `ldap`, `gd` and `json` extensions
- A read-only service account that may bind to the directory and read
  `thumbnailPhoto`

No ImageMagick and no `ldapsearch` binary are needed; the plugin uses PHP's own
LDAP and GD extensions.

## Tested with

This is what the plugin has actually been run against, in production, rather
than a range it is assumed to cover:

| | |
| --- | --- |
| eFront | 7.8.3, then 8.2.1 after an in-place upgrade |
| PHP | 8.0, then 8.4.27 |
| Database | MariaDB 10.11 |
| Operating system | Ubuntu 24.04 LTS |
| Directory | Active Directory over LDAPS, bound with a service account |

`plugin.ini` declares 5.2.18 as the minimum eFront version, but nothing older
than 7.8.3 has been tried. The PHP versions in between — 8.1 to 8.3 — have not
been exercised either; the code carries nothing that 8.4 deprecates, so they
ought to work, but that is reasoning rather than evidence.

## Installation

Download `ADPhotoSync.zip` from the
[latest release](https://github.com/stardado/ADPhotoSync/releases/latest) and
upload it under **Administrator → Plugins → Install new plugin**. Building it
yourself works too: pack the contents of this repository into a ZIP named
`ADPhotoSync.zip`, with `plugin.ini` at the archive root rather than inside a
folder.

The file name matters. eFront extracts the upload into a directory named after
it and then looks for the class declared in `plugin.ini` at
`<plugins>/ADPhotoSync/Model/ADPhotoSyncPlugin.php`. A name like
`ADPhotoSync-1.0.zip` extracts to the wrong directory, and the installer fails
with the unhelpful message *"Plugins with custom definitions cannot be
automatically installed"*.

Then open the **AD Photo Sync** tile on the administrator dashboard, fill in the
directory settings, press **Test connection**, and import a single user to check
the result before enabling automatic synchronisation.

The dashboard tile is the only way in, on purpose. The plugin does not add
itself to the **Plugin links** drawer in the side navigation: eFront shows that
drawer only once some plugin fills it, so installing this one would otherwise
conjure up a whole menu that was not there before — for a tool an administrator
opens a few times a year.

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

**A run started by hand looks at everyone.** The retry window below keeps the
scheduled run from asking the directory again about accounts that had no photo
last time. It does not apply to **Synchronise now** or **Check only**: someone
who has just put the missing pictures into the directory wants them now, and
skipping exactly those accounts is what makes the button look broken.

**The plugin remembers which avatar it wrote.** That is the only way a later run
can tell its own work from a picture the user uploaded, and it is why updating
the plugin must not throw that record away — see *Uninstalling* below.

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
| Retry users without a photo after (hours) | 168 | Scheduled runs only; a run started by hand ignores it |
| Replace photos that users uploaded themselves | off | Off by default; pictures uploaded by hand are preserved |

## Uninstalling

Uninstalling drops the settings, so no directory credentials are left behind.

Two things are deliberately kept. The **avatars**, because removing them would
blank out profiles, and they are ordinary eFront avatars by then. And the
**state table**, which records what the plugin wrote for whom.

That second one matters more than it sounds. eFront has no in-place update for
plugins: installing a new version uninstalls the old one first, so the uninstall
path runs on every update. A version that dropped the state table therefore
erased its own memory each time — after which every picture it had placed looked
like one the user had uploaded, and with **Replace photos that users uploaded
themselves** switched off, exactly those accounts fell out of every later run.
The plugin went on working while quietly ignoring everyone it had already
served.

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
