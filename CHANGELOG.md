# Changelog

All notable changes to this plugin are documented here.

## [2.13.4] - 2026-10-22

### Changed
- **No entry in the "Plugin links" drawer any more.** The plugin used to add
  itself to the side navigation, and because eFront shows that drawer only once
  some plugin fills it, installing this one could conjure up a whole menu that
  was not there before. A tool an administrator opens a few times a year does
  not belong beside daily work; the dashboard tile stays and is the way in.

## [2.13.3] - 2026-09-21

### Fixed
- **The "translations are out of date" warning no longer cries wolf.** The
  catalogue carries the plugin version it was built for, and the settings page
  compares the two to spot the case PHP keeps a gettext catalogue in memory for
  the life of the process, so an updated plugin can go on serving the previous
  version's labels until the workers are replaced. Three releases in a row
  bumped the version without rebuilding the catalogue, so the page asked for a
  restart that would not have changed anything: no text had changed, only the
  marker was stale. The catalogue is rebuilt and the versions agree again.

## [2.13.2] - 2026-09-21

### Fixed
- **Updating the plugin no longer wipes what it knows.** eFront has no in-place
  update: installing a new version uninstalls the old one first, so
  `uninstallPlugin()` runs on every update — and it dropped the state table
  along with the settings. That table is the plugin's memory of which avatar it
  wrote for which account. Without it, every picture it had placed looks like
  one the user uploaded, and with "overwrite existing pictures" off, exactly
  those accounts fall out of every future run. An update therefore quietly
  stopped the plugin from maintaining the pictures it had already set, and the
  status lists came back empty.

  The state table now survives, for the same reason the avatars always have:
  removing it breaks something nobody asked to have broken. Settings are still
  dropped, so an uninstall leaves no directory credentials behind. A failed
  install no longer destroys the table either.

  **After updating from an earlier version the table is already gone.** It can
  be restored from a database backup; otherwise one run with "overwrite existing
  pictures" enabled rebuilds it, at the cost of replacing manually uploaded
  pictures as well.

## [2.13.1] - 2026-09-21

### Fixed
- **The plugin list no longer breaks on this plugin's row.** 2.12.0 put a link
  into the `description` so the project address would show behind the info
  icon. eFront renders that description unfiltered into an HTML attribute
  (`data-content="…"` in `plugins.tpl`), so a single quote character anywhere in
  it tears the row apart — the icons then spill into the table as raw markup.
  The address is now plain text: still readable in the popover, no longer
  clickable, and impossible to break. Opening the plugin's own page rewrites the
  stored description, so the list repairs itself.

## [2.13.0] - 2026-09-21

### Fixed
- **"Synchronise now" now really does look at everyone.** The retry window —
  which keeps an account that had no photo from being asked about again for a
  while — was also applied to runs started from the button. Someone who had
  just put the missing pictures into the directory then watched the run report
  nothing found, while importing the same person individually worked, because
  the single-user import never consulted that window. The window now applies
  only to the scheduled run, which is what it was for: keeping the nightly pass
  cheap. A run started by hand means now, and for every account.

  The same applies to "Check only": the list it produces would otherwise leave
  out the very accounts the administrator is asking about.

## [2.12.0] - 2026-09-14

### Added
- The project address is shown in the plugin list: eFront renders the
  `description` from `plugin.ini` behind the info icon there, and it accepts
  HTML, so the entry now carries a link to the repository.
- **The registered metadata keeps itself current.** eFront copies title,
  description, version and author into the plugins table once, at install time,
  and never reads `plugin.ini` again — so the list went on showing whatever was
  installed originally, and reinstalling to correct it would have dropped this
  plugin's settings along with its tables. Opening the plugin's own page now
  compares the two and writes only what differs.

## [2.11.0] - 2026-09-14

### Fixed
- **The result of the last run stayed English** — *"examined 62, would update 47,
  no photo 9, not in AD 6, failed 0"*. It was assembled from hard-coded words and
  never went through the translation layer at all.

  Fixing it needed more than a translation: the sentence was also written to the
  database as it stood, so it would have carried the language of whoever started
  the run, and a cron run has no language at all. The counters are stored now,
  and the page renders them when it is read — in the reader's language. A result
  written by an earlier version is shown unchanged rather than discarded.

  The German wording puts the number first (*"62 geprüft"* against *"examined
  62"*), which is why the translation uses positional placeholders.

## [2.10.0] - 2026-09-14

### Fixed
- **A secondary account created after its base account was synchronised never
  received the picture.** When the photo turned out unchanged the run returned
  early, and re-pointing the person's accounts only happened when something was
  written — so a new `_mdt` account had to wait for that person's photo to
  change in the directory before it ever got one. The accounts are now
  re-pointed on every run, which writes nothing when they already match.
- **"LMS accounts still without any picture" counted a different population than
  the counters above it**, which is why it read 22 where only about 10 were
  expected. It included secondary accounts and accounts excluded by pattern —
  neither of which the plugin ever looks up. Every counter now uses the same
  population, and secondary accounts are reported on their own line, since they
  inherit the base account's picture rather than lacking one.

### Added
- The settings page notices when PHP is serving translations from an older
  version of the plugin and says so, with the command to run. gettext keeps a
  catalogue per process while OPcache does pick up changed code, so after an
  update the two disagree and part of the interface falls back to English —
  which looks like a broken translation rather than a process needing a restart.

## [2.9.1] - 2026-09-14

### Changed
- **All example data uses the domains RFC 2606 reserves for documentation**
  (`example.com`, `example.org`), and the sample logins are generic names rather
  than real people. Documentation, code comments, tests, form placeholders and
  the shell script are covered.

  Besides being the correct thing to put in documentation, this keeps staff names
  and the internal network layout — a domain controller address, the service
  account, the OU path — out of a repository that is going to be published.
- The shell script's defaults are examples too now, and its header says so along
  with how to supply the real values through the environment.

Ownership metadata is deliberately untouched: the composer vendor name, the
author and the copyright line are not example data.

## [2.9.0] - 2026-09-14

### Added
- **Every address on both sides is used for the lookup.** From the LMS the
  plugin now takes the login *and* the profile address; the directory side
  already searched every configured attribute since 2.6.0. One search spans the
  whole cross product, so a person is found whichever of their addresses the two
  systems happen to agree on.

  The two LMS fields drift apart in practice — a rename leaves the old address in
  one of them — and previously the profile address was only consulted when the
  login was not an address at all.

- Addresses are de-duplicated case-insensitively, and anything without an `@` is
  dropped, so the filter stays as small as the data allows.

### Note
When the same person has two LMS accounts — an `.at` and a `.de` login, say —
each is looked up on its own and both receive the picture, because the directory
carries both addresses on one mailbox. To keep only one of them, exclude the
other under **Skip logins matching**.

## [2.8.0] - 2026-09-14

### Added
- **Whole domains can be excluded.** *Skip logins containing* became *Skip logins
  matching*, which takes a comma separated list of patterns where `*` stands for
  any run of characters:

  ```
  *@example.org, muster, test*
  ```

  A pattern without a `*` keeps the previous substring behaviour, so existing
  configurations go on working untouched.

  Matching uses `REGEXP` rather than `LIKE`, because `LIKE` reads an underscore
  as a single-character wildcard — and underscores are exactly what the secondary
  account suffixes are made of. Dots are escaped, so `*@example.org` matches that
  domain and not `exampleXorg`.
- The single-user import reports an excluded account by name instead of silently
  doing nothing, the same way it already did for archived accounts.

## [2.7.0] - 2026-09-14

### Fixed
- **A broken translation can no longer take the page down.** The settings page
  was replaced by *"A serious error has occurred: The arguments array must
  contain 2 items, 0 given"* on every load.

  eFront's `translate()` guards its `vsprintf` and falls back to the original
  string when the format does not match the arguments; `dtranslate()` does not,
  so any stray placeholder in the final text throws. And that final text is not
  only the translation: `dtranslate()` applies the `language_overrides` table
  *afterwards*, so an override containing `%s` breaks a call that is otherwise
  correct — nothing in this plugin could prevent it.

  All translation now goes through `ADPhotoSync::t()`, which adds the guard
  `dtranslate()` is missing and logs which string failed, so the culprit names
  itself in the PHP error log.

### Changed
- The template no longer uses the `eF_dtranslate` modifier, which calls the
  unguarded `dtranslate()` directly. Labels are translated in the controller and
  passed in as `$T_TXT`, putting every translation on the guarded path.

## [2.6.0] - 2026-09-14

### Added
- **Several mail attributes can be searched**, given as a comma separated list.
  The setting is now *Mail attributes*, and new installations default to
  `mail, proxyAddresses`.

  This is what finds someone whose address changed. After a rename Exchange
  keeps the former address as a secondary SMTP address and makes the new one
  primary, so `mail` holds only the new address while the LMS login is still the
  old one — a search on `mail` alone reports the account as missing from the
  directory. Every address lives in `proxyAddresses`, and Active Directory
  matches it case-insensitively, so one `smtp:` clause covers the primary
  `SMTP:` entry as well.

### Note for existing installations
The stored setting keeps its previous value. Set **Mail attributes** to
`mail, proxyAddresses` by hand to pick this up; nothing changes until you do.

## [2.5.0] - 2026-09-14

### Changed
- **Archived users are ignored throughout.** They are former staff, so a missing
  picture is not worth chasing, and listing them buried the accounts that still
  matter. The filter is applied to the candidates, the counters *and* the lists:
  applying it to only some of them would leave a counter disagreeing with the
  table under it, and then neither can be trusted.
- The single-user import refuses an archived account by name rather than
  silently doing nothing.
- `scripts/sync_all_ad_avatars.sh` skips archived users too, and was brought in
  line with the underscore rule from 2.3.0. Its account matching no longer uses
  a prefix `LIKE` either.

## [2.4.0] - 2026-09-14

### Added
- **"Not found in Active Directory" is now its own result**, separate from "no
  photo stored". Both used to be reported as *no photo*, which hid the
  difference between a data problem worth chasing and an account that simply
  needs a picture uploading.
- A third state for accounts that carry no mail address in the LMS, since there
  is nothing to look those up by either.
- Every section on the settings page has a heading and a sentence explaining
  what is being listed and what to do about it.
- The single-user import says which of the three situations it ran into instead
  of always reporting "no photo".

### Changed
- `LdapPhotoSource::fetchPhoto()` became `lookup()`, returning a `DirectoryLookup`
  that distinguishes "no such entry" from "entry without a picture". Returning
  `null` for both was what made the two indistinguishable.

## [2.3.0] - 2026-09-14

### Fixed
- Only `_ad` and `_dt` counted as secondary accounts. Anything else — `_mdt`,
  `_tl_0975b4d9a4-461c0018c7` — was treated as a base account, looked up in the
  directory under a mail address that does not exist there, and reported as
  having no photo. Any underscore after the `@` now marks a secondary account,
  and all of a person's accounts receive the base account's picture.

  A suffix may itself contain underscores, so the **first** one after the `@`
  marks the split, not the last. An underscore before the `@` belongs to the
  mail address (`max_mustermann@example.com`) and is left alone; a domain name
  cannot contain one, which is what makes the rule safe.

### Changed
- Assigning the avatar to a person's accounts no longer uses a prefix `LIKE`,
  which would also have matched an unrelated login that merely starts with the
  same text. The base login and the separator are now matched explicitly.

## [2.2.0] - 2026-09-14

### Added
- **A list of the accounts the directory has no photo for**, by login and name,
  on the settings page. The counter alone did not answer the question that
  actually gets asked.
- A list of the accounts that failed, with the reason.
- **Check only, write nothing**: walks every candidate and records who has no
  photo, without touching a single avatar. Accounts that do have a photo are
  deliberately left unrecorded, so a later real run still writes them.

## [2.1.2] - 2026-09-14

### Fixed
- A synchronised photo could stay invisible in the interface. eFront caches model
  rows and the cached user record carries `avatars_ID`, so writing the tables
  directly left the cached copy pointing at the previous picture. The cache
  entries for every affected account and for the avatar itself are now dropped
  after a write, which is what the regular upload path does through
  `deleteCache()` on the related model.

## [2.1.1] - 2026-09-14

### Fixed
- Every message with a placeholder failed with *"The arguments array must
  contain 1 items, 0 given"*, which is what the connection test reported instead
  of its actual result. `dtranslate()` runs `vsprintf` on the translated string
  itself, so the values belong in its own argument list; wrapping it in
  `sprintf()` handed it a format string and nothing to fill it with.

## [2.1.0] - 2026-09-14

### Added
- German interface. The plugin now follows the language of the LMS: English
  stays the source language, and `i18n/de_DE/LC_MESSAGES/ADPhotoSync.po` supplies
  the German catalogue. A language without a catalogue falls back to English.
- `tools/po2mo.php`, a pure-PHP `.po` to `.mo` compiler. Neither the LMS server
  nor a Windows workstation has `msgfmt`, so the build would otherwise depend on
  a tool nobody has installed.

### Changed
- The connection test composes its success message in the controller instead of
  receiving a prebuilt sentence from `LdapPhotoSource::testConnection()`, so it
  can be translated as one sentence rather than assembled from fragments.
- Bind and search failures are translatable too, since they are what an
  administrator reads when the test fails.

## [2.0.1] - 2026-09-14

### Fixed
- The single-user import failed with *"You must specify exactly 1 field for the
  singularity to work"*. `Database::getTableDataSingle()` returns one scalar from
  one column, not a row, and it was being handed the whole candidate field list.
- Actions gave no visible feedback. **Test connection**, the full run and the
  single-user import now redirect back to the plugin page after setting their
  message, which is how eFront surfaces it.
- `action=sync` stayed in the address bar after a run, so a browser refresh
  started another full synchronisation. The redirect removes it.
- Connection and synchronisation errors are caught as `Throwable` rather than
  only `LdapException`, so a PHP-level failure shows as a message instead of a
  blank page, and the message names the file and line it came from.
- Reading the statistics can no longer take the settings page down.

### Changed
- The directory server examples use `ldaps://dc1.example.com:636` instead of a
  real internal address.

## [2.0.0] - 2026-09-14

Photos come from the on-premises Active Directory, not from Entra ID. The
plugin was rewritten around that, and renamed from `EntraPhotoSync` to
`ADPhotoSync`. This is a new plugin as far as eFront is concerned: new folder,
new class, new tables. Install it fresh; there is no upgrade path from 1.x.

### Changed
- **Source**: `thumbnailPhoto` read over LDAP with the `ldap` extension,
  replacing Microsoft Graph. No `ldapsearch` binary required.
- **Matching**: users are matched on `users.login` (which is the mail address
  here) rather than `users.email`, and secondary accounts (`_ad`, `_dt`) are
  skipped when collecting candidates.
- **Avatar writing**: the existing avatar row is now updated in place. Creating a
  new row and repointing `users.avatars_ID` left the old picture visible, because
  the interface caches per avatar. The `hash` column is rewritten to bust that
  cache.
- **Image format**: three PNG data URIs at 200x200, 132x140 and 120x120, written
  to `data`, `data_medium` and `data_small`. eFront does not store avatars as
  binary blobs, and JPEG renders broken.
- **Change detection**: an MD5 of the directory photo replaces the Graph ETag.
- Resizing uses GD instead of ImageMagick, removing that dependency.

### Added
- **Single-user import** on the settings page: enter a login or mail address and
  fetch just that photo. Always overwrites, since the administrator named the
  account explicitly — this is the quickest way to verify a new configuration.
- Settings for the directory server, bind user, search base, mail and photo
  attributes, TLS verification and a login exclusion pattern.
- `AD_PHOTO_SYNC_BIND_PASSWORD` keeps the bind password out of the database.
- The shell scripts this plugin replaces are kept under `scripts/`.

## [1.0.1] - 2026-09-14

### Added
- A tile on the administrator dashboard and an entry in the *Plugin Links*
  drawer. Without them the settings page was only reachable by typing its URL.

### Fixed
- The build scripts produced `EntraPhotoSync-<version>.zip`. eFront derives the
  target directory from the uploaded file name, so the files landed in
  `EntraPhotoSync-1.0.0/` while the installer looked for the class under
  `EntraPhotoSync/`. Installation failed with the catch-all message *"Plugins
  with custom definitions cannot be automatically installed"*.

## [1.0.0] - 2026-09-14

Initial release, reading photos from Microsoft Entra ID via Microsoft Graph.
Superseded by 2.0.0.
