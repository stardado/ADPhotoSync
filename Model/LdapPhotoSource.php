<?php

namespace Efront\Plugin\ADPhotoSync\Model;

/**
 * Reads user photos from Active Directory over LDAP.
 *
 * Uses the php-ldap extension rather than shelling out to ldapsearch, so the
 * plugin has no dependency on command line tools being installed or on a shell
 * being available to the web server user.
 *
 * @package Efront\Plugin\ADPhotoSync\Model
 */
class LdapPhotoSource
{
    private const CONNECT_TIMEOUT = 10;

    /**
     * Exchange stores every address of a mailbox here, the primary one prefixed
     * "SMTP:" and the rest "smtp:". After a rename the former address survives
     * only in this attribute, so searching it is what keeps those people
     * findable.
     */
    public const PROXY_ADDRESSES_ATTRIBUTE = 'proxyAddresses';

    private string $uri;
    private string $bindDn;
    private string $bindPassword;
    private string $baseDn;

    /** @var array<int, string> */
    private array $mailAttributes;

    private string $photoAttribute;
    private bool $verifyCertificate;

    /** @var resource|\LDAP\Connection|null */
    private $connection;

    /**
     * @param array<int, string>|string $mailAttributes
     */
    public function __construct(
        string $uri,
        string $bindDn,
        string $bindPassword,
        string $baseDn,
        $mailAttributes = ADPhotoSync::DEFAULT_MAIL_ATTRIBUTE,
        string $photoAttribute = ADPhotoSync::DEFAULT_PHOTO_ATTRIBUTE,
        bool $verifyCertificate = false
    ) {
        $this->uri = $uri;
        $this->bindDn = $bindDn;
        $this->bindPassword = $bindPassword;
        $this->baseDn = $baseDn;
        $this->mailAttributes = self::normaliseAttributes($mailAttributes);
        $this->photoAttribute = $photoAttribute ?: ADPhotoSync::DEFAULT_PHOTO_ATTRIBUTE;
        $this->verifyCertificate = $verifyCertificate;
    }

    public static function fromSettings(ADPhotoSyncSettings $settings): self
    {
        return new self(
            (string) $settings->ldap_uri,
            (string) $settings->bind_dn,
            $settings->getBindPassword(),
            (string) $settings->base_dn,
            $settings->getMailAttributes(),
            (string) $settings->photo_attribute,
            (bool) $settings->verify_certificate
        );
    }

    /**
     * Accepts either a list or a comma separated string, and drops empties.
     *
     * @param  array<int, string>|string $attributes
     * @return array<int, string>
     */
    public static function normaliseAttributes($attributes): array
    {
        if (!is_array($attributes)) {
            $attributes = preg_split('/[,;\s]+/', (string) $attributes) ?: [];
        }

        $attributes = array_values(array_filter(array_map('trim', $attributes), static fn ($a): bool => '' !== $a));

        return [] === $attributes ? [ADPhotoSync::DEFAULT_MAIL_ATTRIBUTE] : $attributes;
    }

    /**
     * The search filter for one address.
     *
     * proxyAddresses needs the "smtp:" prefix, because its values carry it.
     * Active Directory matches that attribute case-insensitively, so one clause
     * covers both the primary "SMTP:" entry and the secondary "smtp:" ones —
     * which is exactly what finds someone whose address changed after a rename.
     *
     * Public so the composed filter can be checked without a directory.
     */
    public function buildFilter($mails): string
    {
        $clauses = [];

        foreach (self::normaliseMails($mails) as $mail) {
            $escaped = $this->escape($mail);

            foreach ($this->mailAttributes as $attribute) {
                $clauses[] = 0 === strcasecmp($attribute, self::PROXY_ADDRESSES_ATTRIBUTE)
                    ? sprintf('(%s=smtp:%s)', $attribute, $escaped)
                    : sprintf('(%s=%s)', $attribute, $escaped);
            }
        }

        if ([] === $clauses) {
            throw new LdapException('No address to look up.');
        }

        return 1 === count($clauses) ? $clauses[0] : '(|' . implode('', $clauses) . ')';
    }

    /**
     * Every address the LMS holds for one person, trimmed and de-duplicated.
     *
     * The login and the profile address can differ — after a rename, or simply
     * because they were maintained separately — and either may be the one the
     * directory knows. Trying both costs nothing; it is still a single search.
     *
     * @param  array<int, string>|string $mails
     * @return array<int, string>
     */
    public static function normaliseMails($mails): array
    {
        $list = is_array($mails) ? $mails : [$mails];
        $list = array_map('trim', array_map('strval', $list));

        $seen = [];

        foreach ($list as $mail) {
            if ('' === $mail || false === strpos($mail, '@')) {
                continue;
            }

            // Addresses are not case sensitive, and the two LMS fields often
            // differ only in capitalisation.
            $seen[mb_strtolower($mail)] = $mail;
        }

        return array_values($seen);
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Opens the connection and authenticates. Safe to call repeatedly.
     *
     * @throws LdapException
     */
    public function connect(): void
    {
        if (null !== $this->connection) {
            return;
        }

        if (!function_exists('ldap_connect')) {
            throw new LdapException('The PHP ldap extension is not installed.');
        }

        if ('' === trim($this->uri)) {
            throw new LdapException('No directory server configured.');
        }

        // Must be set on the global context BEFORE connecting: once the handle
        // exists, OpenLDAP has already captured the TLS settings. Internal
        // domain controllers usually present a certificate from a private CA
        // the web server does not trust.
        if (!$this->verifyCertificate) {
            @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
        }

        $connection = @ldap_connect($this->uri);

        if (false === $connection) {
            throw new LdapException(sprintf('Could not open a connection to %s.', $this->uri));
        }

        @ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        // Active Directory returns referrals that a simple bind cannot follow.
        @ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        @ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, self::CONNECT_TIMEOUT);

        if (!@ldap_bind($connection, $this->bindDn, $this->bindPassword)) {
            $error = ldap_error($connection);
            @ldap_unbind($connection);

            // dtranslate() runs vsprintf itself, so the values belong in its
            // argument list. Wrapping it in sprintf() leaves it with a format
            // string and no arguments, which throws.
            throw new LdapException(ADPhotoSync::t('Bind as "%s" failed: %s', $this->bindDn,
                $error
            ));
        }

        $this->connection = $connection;
    }

    public function disconnect(): void
    {
        if (null !== $this->connection) {
            @ldap_unbind($this->connection);
            $this->connection = null;
        }
    }

    /**
     * Binds and runs one cheap search, to prove the settings are usable.
     *
     * The caller composes the success message, so it can be translated as a
     * whole sentence rather than assembled from fragments.
     *
     * @throws LdapException
     */
    public function testConnection(): void
    {
        $this->connect();

        $filter = sprintf('(%s=*)', $this->escape($this->mailAttributes[0]));
        $search = @ldap_search($this->connection, $this->baseDn, $filter, ['dn'], 0, 1);

        if (false === $search) {
            throw new LdapException(ADPhotoSync::t('Bind succeeded, but searching "%s" failed: %s', $this->baseDn,
                ldap_error($this->connection)
            ));
        }

        if (0 === @ldap_count_entries($this->connection, $search)) {
            throw new LdapException(ADPhotoSync::t(
                'Bind succeeded, but no entry with a "%s" attribute was found under "%s". '
                . 'Check the search base.',
                $this->mailAttributes[0],
                $this->baseDn
            ));
        }
    }

    /**
     * Looks a person up in the directory by any of their addresses.
     *
     * Both sides can hold more than one address: the LMS keeps a login and a
     * profile address, and the directory keeps every address of a mailbox in
     * proxyAddresses. One search covers the whole cross product, so a person is
     * found whichever of their addresses the two systems happen to agree on.
     *
     * The result distinguishes "no such account" from "account without a
     * picture", because those call for entirely different follow-up.
     *
     * @param array<int, string>|string $mails
     *
     * @throws LdapException
     */
    public function lookup($mails): DirectoryLookup
    {
        $this->connect();

        $filter = $this->buildFilter($mails);

        $search = @ldap_search($this->connection, $this->baseDn, $filter, [$this->photoAttribute], 0, 2);

        if (false === $search) {
            throw new LdapException(ADPhotoSync::t(
                'Search for "%s" failed: %s',
                implode(', ', self::normaliseMails($mails)),
                ldap_error($this->connection)
            ));
        }

        $entry = @ldap_first_entry($this->connection, $search);

        if (false === $entry) {
            return DirectoryLookup::notFound();
        }

        // ldap_get_values_len is the only accessor that returns octet strings
        // intact; ldap_get_entries mangles binary attributes.
        $values = @ldap_get_values_len($this->connection, $entry, $this->photoAttribute);

        if (false === $values || empty($values[0])) {
            return DirectoryLookup::withoutPhoto();
        }

        return DirectoryLookup::withPhoto((string) $values[0]);
    }

    /**
     * Escapes a value for safe use inside an LDAP filter.
     */
    private function escape(string $value): string
    {
        return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
    }
}
