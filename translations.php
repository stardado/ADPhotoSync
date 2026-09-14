<?php

exit;

// Translation strings for the ADPhotoSync plugin.
// Collected by the eFront translation scanner via dtranslate(..., 'ADPhotoSync').

dtranslate("AD photo synchronisation", "ADPhotoSync");
dtranslate("Directory server", "ADPhotoSync");
dtranslate("Bind user", "ADPhotoSync");
dtranslate("Bind password", "ADPhotoSync");
dtranslate("Leave empty to keep the stored password", "ADPhotoSync");
dtranslate("Search base (DN)", "ADPhotoSync");
dtranslate("Mail attribute", "ADPhotoSync");
dtranslate("Photo attribute", "ADPhotoSync");
dtranslate("Verify the TLS certificate of the directory server", "ADPhotoSync");
dtranslate("Skip logins containing", "ADPhotoSync");
dtranslate("Enable automatic synchronisation", "ADPhotoSync");
dtranslate("Users per run", "ADPhotoSync");
dtranslate("Minimum hours between runs", "ADPhotoSync");
dtranslate("Retry users without a photo after (hours)", "ADPhotoSync");
dtranslate("Replace photos that users uploaded themselves", "ADPhotoSync");
dtranslate("Server, bind user, password and search base are required.", "ADPhotoSync");

dtranslate("Login or mail address", "ADPhotoSync");
dtranslate("Synchronise this user", "ADPhotoSync");
dtranslate("Fetches the photo for one account immediately, even if it is unchanged.", "ADPhotoSync");

dtranslate("Test connection", "ADPhotoSync");
dtranslate("Run synchronisation now", "ADPhotoSync");
dtranslate("Last run", "ADPhotoSync");
dtranslate("never", "ADPhotoSync");
dtranslate("Users with a synchronised photo", "ADPhotoSync");
dtranslate("Users without any photo", "ADPhotoSync");
dtranslate("No photo in Active Directory", "ADPhotoSync");
dtranslate("Connection successful", "ADPhotoSync");
dtranslate("Connection failed", "ADPhotoSync");
dtranslate("Synchronisation finished", "ADPhotoSync");

dtranslate("Check only, write nothing", "ADPhotoSync");
dtranslate("Login", "ADPhotoSync");
dtranslate("Name", "ADPhotoSync");
dtranslate("Checked", "ADPhotoSync");
dtranslate("Only the first entries are listed; the counter above gives the total.", "ADPhotoSync");
dtranslate("Nothing recorded yet. Use 'Check only, write nothing' to find out which accounts the directory has no photo for.", "ADPhotoSync");

dtranslate("Overview", "ADPhotoSync");
dtranslate("Photo taken from Active Directory", "ADPhotoSync");
dtranslate("In Active Directory, but no photo stored", "ADPhotoSync");
dtranslate("Not found in Active Directory", "ADPhotoSync");
dtranslate("No mail address in the LMS", "ADPhotoSync");
dtranslate("Errors", "ADPhotoSync");
dtranslate("LMS accounts still without any picture", "ADPhotoSync");
dtranslate("Reason", "ADPhotoSync");
dtranslate("Nothing recorded.", "ADPhotoSync");
dtranslate("Synchronise a single user", "ADPhotoSync");
dtranslate("Settings", "ADPhotoSync");
dtranslate("No entry with this mail address exists in the search base. Usually a leaver, a typo in the LMS address, or an account outside the configured organisational unit.", "ADPhotoSync");
dtranslate("These accounts exist in Active Directory, but no picture is stored in the photo attribute. Someone has to upload one there; the plugin will pick it up on the next run.", "ADPhotoSync");
dtranslate("These LMS accounts carry no mail address, so there is nothing to look the person up by.", "ADPhotoSync");
dtranslate("Something went wrong for these accounts. The reason is shown next to each one.", "ADPhotoSync");
dtranslate("No entry for \"%s\" was found in Active Directory.", "ADPhotoSync");
dtranslate("\"%s\" has no mail address in the LMS, so there is nothing to look up.", "ADPhotoSync");
dtranslate("Search for \"%s\" failed: %s", "ADPhotoSync");

dtranslate("No LMS account found for \"%s\".", "ADPhotoSync");
dtranslate("\"%s\" is archived and is therefore skipped.", "ADPhotoSync");

dtranslate("Mail attributes (comma separated)", "ADPhotoSync");
dtranslate("Add proxyAddresses to find people whose address changed: after a rename the former address survives only as a secondary entry there.", "ADPhotoSync");

dtranslate("Skip logins matching (comma separated, * allowed)", "ADPhotoSync");
dtranslate("\"%s\" is excluded by the skip pattern and is therefore ignored.", "ADPhotoSync");

dtranslate("__catalogue_version__", "ADPhotoSync");
dtranslate("The translations loaded by PHP are from version %s, but the plugin is version %s. Restart PHP (systemctl restart php-fpm) to pick up the current translations; until then some labels stay in English.", "ADPhotoSync");

dtranslate("Base accounts still without any picture", "ADPhotoSync");
dtranslate("Secondary accounts (they inherit the base account picture)", "ADPhotoSync");

dtranslate("examined %1$d, updated %2$d, unchanged %3$d, no photo %4$d, not in Active Directory %5$d, without a mail address %6$d, failed %7$d", "ADPhotoSync");
dtranslate("examined %1$d, would update %2$d, unchanged %3$d, no photo %4$d, not in Active Directory %5$d, without a mail address %6$d, failed %7$d", "ADPhotoSync");
