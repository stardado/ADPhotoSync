<?php

namespace Efront\Plugin\ADPhotoSync\Model;

/**
 * Shared constants for the ADPhotoSync plugin.
 *
 * @package Efront\Plugin\ADPhotoSync\Model
 */
class ADPhotoSync
{
    public const PLUGIN_NAME = 'ADPhotoSync';

    /** Settings table, holds exactly one row (id = 1). */
    public const SETTINGS_TABLE = 'plugin_ad_photo_sync_settings';

    /** Per-user synchronisation state, so an unchanged photo is never rewritten. */
    public const STATE_TABLE = 'plugin_ad_photo_sync_state';

    /**
     * Outcome of the last attempt for a user.
     *
     * The three failure states are kept apart on purpose, because each one calls
     * for different follow-up: NOT_IN_DIRECTORY means the account could not be
     * found at all, NO_PHOTO means it was found but carries no picture, and
     * NO_MAIL means the eFront account has no address to look up in the first
     * place.
     */
    public const STATUS_SYNCED = 'synced';
    public const STATUS_NO_PHOTO = 'no_photo';
    public const STATUS_NOT_IN_DIRECTORY = 'not_in_ad';
    public const STATUS_NO_MAIL = 'no_mail';
    public const STATUS_ERROR = 'error';

    /**
     * Avatar dimensions eFront actually renders.
     *
     * These are the values proven in production by the shell scripts this plugin
     * replaces, not the *_THUMB_MAX_DIMENSION constants on the Avatar model.
     * The medium size is deliberately not square.
     */
    public const SIZE_LARGE = [200, 200];
    public const SIZE_MEDIUM = [132, 140];
    public const SIZE_SMALL = [120, 120];

    /**
     * eFront stores avatars as PNG data URIs in TEXT columns. Writing JPEG here
     * produces broken images in the interface.
     */
    public const IMAGE_MIME = 'image/png';

    public const DEFAULT_PHOTO_ATTRIBUTE = 'thumbnailPhoto';
    public const DEFAULT_MAIL_ATTRIBUTE = 'mail';

    /**
     * Secondary accounts are the base login plus an underscore and a suffix:
     * "someone@example.com_ad", "..._dt", "..._mdt", and whatever else gets
     * created later.
     *
     * The underscore is only treated as a separator when it appears after the
     * "@". A mail address may legitimately contain one in its local part
     * ("max_mustermann@example.com"), but a domain name cannot, so anything
     * after the "@" marks a suffix.
     *
     * Secondary accounts are skipped when collecting candidates — only the base
     * account is looked up in the directory — and then receive the same avatar.
     */
    public const SECONDARY_ACCOUNT_SEPARATOR = '_';

    /**
     * Name of the environment variable that may hold the bind password instead
     * of the database. If set, it always wins over the stored value.
     */
    public const PASSWORD_ENV_VAR = 'AD_PHOTO_SYNC_BIND_PASSWORD';

    /**
     * A msgid whose translation is the version the catalogue was built for.
     *
     * gettext loads a catalogue once per process and keeps it, so after an
     * update the running workers go on serving the previous translations. The
     * symptom is baffling rather than obvious: strings that existed before stay
     * translated while everything newer falls back to English, which reads as a
     * broken translation instead of a stale cache. Comparing this value with
     * VERSION turns that into a sentence telling the administrator to restart
     * PHP.
     */
    public const CATALOGUE_VERSION_KEY = '__catalogue_version__';

    /**
     * The version the loaded catalogue was built for, or an empty string when
     * no catalogue is loaded at all (an untranslated language, say).
     */
    public static function catalogueVersion(): string
    {
        $version = self::t(self::CATALOGUE_VERSION_KEY);

        return $version === self::CATALOGUE_VERSION_KEY ? '' : $version;
    }

    /**
     * Turns the exclusion patterns from the settings into one MySQL regular
     * expression, or an empty string when nothing is excluded.
     *
     * Patterns are separated by commas, semicolons or line breaks, and "*"
     * stands for any run of characters:
     *
     *   *@example.org        every address in that domain
     *   test*                every login starting with "test"
     *   muster               contains "muster" anywhere
     *
     * A pattern without any "*" keeps the old substring behaviour, so existing
     * configurations go on working untouched.
     *
     * REGEXP is used rather than LIKE because LIKE would read an underscore in
     * a pattern as a single-character wildcard — and underscores are exactly
     * what the secondary account suffixes are made of.
     */
    public static function buildExclusionRegex(string $patterns): string
    {
        $alternatives = [];

        foreach (preg_split('/[,;\r\n]+/', $patterns) ?: [] as $raw) {
            $pattern = trim($raw);

            if ('' === $pattern) {
                continue;
            }

            if (false === strpos($pattern, '*')) {
                $pattern = '*' . $pattern . '*';
            }

            // Escape everything the regular expression engine would otherwise
            // interpret, then give "*" its meaning back.
            $escaped = preg_replace('/([.^$|()\[\]{}+?\\\\])/', '\\\\$1', $pattern);
            $alternatives[] = '^' . str_replace('*', '.*', (string) $escaped) . '$';
        }

        return implode('|', $alternatives);
    }

    /**
     * Translates a string in this plugin's domain, without letting a broken
     * catalogue take the page down.
     *
     * eFront's own translate() guards its vsprintf and falls back to the
     * original string when the format does not match the arguments. dtranslate()
     * does not, so anything that leaves more placeholders in the final text than
     * the call supplies throws a ValueError that reaches the user as "a serious
     * error has occurred" and replaces the entire page.
     *
     * That final text is not only the translation: dtranslate() applies the
     * language override table afterwards, so an override containing "%s" breaks
     * a call that is otherwise perfectly correct — and nothing in this plugin
     * could prevent it. Hence the guard.
     *
     * @param string $string
     *          - The English source string, which is also the msgid.
     * @param string|int|float ...$values
     *          - Values for the placeholders, if the string has any.
     */
    public static function t(string $string, ...$values): string
    {
        try {
            return dtranslate($string, self::PLUGIN_NAME, ...$values);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[ADPhotoSync] translation of "%s" failed (%s); falling back to the original',
                mb_substr($string, 0, 60),
                $e->getMessage()
            ));
        }

        // The translation was unusable. The source string is still correct, so
        // show that rather than nothing.
        try {
            return [] === $values ? $string : vsprintf($string, $values);
        } catch (\Throwable $e) {
            return $string;
        }
    }
}
