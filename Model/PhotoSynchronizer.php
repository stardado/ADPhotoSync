<?php

namespace Efront\Plugin\ADPhotoSync\Model;

use Efront\Model\Avatar;
use Efront\Model\Database;

/**
 * Selects the users that need a photo, reads it from Active Directory and
 * stores it as an eFront avatar.
 *
 * Users are matched on their eFront login, which in this installation is the
 * mail address, against the mail attribute in the directory. Each person owns a
 * base account plus secondary accounts suffixed "_ad" and "_dt"; only the base
 * account is looked up, and all of them end up pointing at the same avatar.
 *
 * A per-user state row holds the hash of the photo last written, so a run that
 * finds nothing changed costs one directory read and no image processing.
 *
 * @package Efront\Plugin\ADPhotoSync\Model
 */
class PhotoSynchronizer
{
    /**
     * Avatar types eFront considers "not a real photo". A user carrying one of
     * these is safe to overwrite even when the administrator asked us to leave
     * manually uploaded pictures alone.
     */
    private const REPLACEABLE_AVATAR_TYPES = [Avatar::TYPE_GRAVATAR, Avatar::TYPE_ALPHATAR];

    /**
     * The accounts this plugin concerns itself with at all.
     *
     * Archived users are left out everywhere — candidates, counters and lists
     * alike. They are former staff, so chasing a missing picture for them is
     * noise, and listing them would bury the accounts that still matter.
     * eFront uses the same `archive = 0` test throughout.
     */
    private const LIVE_USER_CONDITION = '`u`.`active` = 1 AND `u`.`archive` = 0';

    private ADPhotoSyncSettings $settings;
    private LdapPhotoSource $directory;
    private AvatarWriter $writer;
    private Database $db;

    public function __construct(
        ADPhotoSyncSettings $settings,
        ?LdapPhotoSource $directory = null,
        ?AvatarWriter $writer = null
    ) {
        $this->settings = $settings;
        $this->directory = $directory ?: LdapPhotoSource::fromSettings($settings);
        $this->writer = $writer ?: new AvatarWriter();
        $this->db = Database::getInstance();
    }

    /**
     * Runs one synchronisation batch.
     *
     * @throws LdapException
     *          - When the directory cannot be reached or the bind fails.
     *            Per-user failures are collected in the result instead.
     */
    /**
     * @param bool $dryRun
     *          - Report what the directory holds without writing any avatar.
     *            Accounts found to have no photo are still recorded, since that
     *            is the answer being asked for; accounts that do have one are
     *            left untouched so the next real run still writes them.
     */
    public function run(bool $dryRun = false): SyncResult
    {
        $result = new SyncResult();
        $result->dryRun = $dryRun;

        $this->requireConfiguration();

        // Fail fast and loudly if the bind is broken, rather than recording an
        // identical error against every single user.
        $this->directory->connect();

        foreach ($this->getCandidates() as $candidate) {
            $result->examined++;

            try {
                $this->syncUser($candidate, $result, false, $dryRun);
            } catch (LdapException $e) {
                $result->failed++;
                $result->addError(sprintf('%s: %s', (string) $candidate['login'], $e->getMessage()));

                $this->recordState((int) $candidate['id'], ADPhotoSync::STATUS_ERROR, null, null, $e->getMessage());
            }
        }

        $this->settings->setFields([
            'last_run'    => time(),
            // The numbers, not a finished sentence: the page renders them in
            // the reader's language, which need not be the one this run ran in.
            'last_result' => mb_substr($result->toStorage(), 0, 255),
        ])->save();

        return $result;
    }

    /**
     * Synchronises one named user, whether or not a scheduled run would have
     * picked them up.
     *
     * This is the quickest way to verify a fresh configuration, so it ignores
     * both the stored photo hash and the "leave manual uploads alone" rule: the
     * administrator named this account explicitly.
     *
     * @param  string $identifier
     *          - A login or mail address.
     * @throws LdapException
     */
    public function syncOne(string $identifier): SyncResult
    {
        $result = new SyncResult();
        $identifier = trim($identifier);

        if ('' === $identifier) {
            $result->failed++;
            $result->addError('No user given.');

            return $result;
        }

        $this->requireConfiguration();

        $quoted = $this->db->Quote($identifier);

        // getTableDataSingle returns one scalar from one column, not a row, so
        // the row lookup has to go through getTableData with a limit.
        $rows = $this->db->getTableData(
            $this->candidateFrom(),
            $this->candidateFields(),
            sprintf('(`u`.`login` = %s OR `u`.`email` = %s)', $quoted, $quoted),
            '`u`.`id` ASC',
            '',
            '1'
        );

        $candidate = $rows[0] ?? null;

        if (!$candidate) {
            $result->failed++;
            $result->addError(ADPhotoSync::t('No LMS account found for "%s".', $identifier
            ));

            return $result;
        }

        // Named explicitly, so say why nothing happens rather than silently
        // doing nothing.
        $exclude = ADPhotoSync::buildExclusionRegex((string) $this->settings->login_exclude);

        if ('' !== $exclude && preg_match('/' . str_replace('/', '\\/', $exclude) . '/i', (string) $candidate['login'])) {
            $result->failed++;
            $result->addError(ADPhotoSync::t(
                '"%s" is excluded by the skip pattern and is therefore ignored.',
                (string) $candidate['login']
            ));

            return $result;
        }

        if ((int) ($candidate['archive'] ?? 0) !== 0) {
            $result->failed++;
            $result->addError(ADPhotoSync::t('"%s" is archived and is therefore skipped.', $identifier
            ));

            return $result;
        }

        $this->directory->connect();

        $result->examined++;

        try {
            $this->syncUser($candidate, $result, true);
        } catch (LdapException $e) {
            $result->failed++;
            $result->addError($e->getMessage());

            $this->recordState((int) $candidate['id'], ADPhotoSync::STATUS_ERROR, null, null, $e->getMessage());
        }

        return $result;
    }

    /**
     * Handles a single user.
     *
     * @param  array<string, mixed> $candidate
     * @param  bool                 $force
     *          - Ignore the stored hash and write even an unchanged photo.
     * @throws LdapException
     */
    private function syncUser(array $candidate, SyncResult $result, bool $force, bool $dryRun = false): void
    {
        $userId = (int) $candidate['id'];
        $login = trim((string) $candidate['login']);

        // Both LMS fields are offered to the search, not just the login. They
        // drift apart in practice — a rename leaves the old address in one of
        // them — and either may be the one the directory knows. On the
        // directory side proxyAddresses covers every address of the mailbox, so
        // one search spans all of a person's addresses on both sides.
        $addresses = LdapPhotoSource::normaliseMails([
            $login,
            trim((string) $candidate['email']),
        ]);

        if ([] === $addresses) {
            $result->withoutMail++;
            $this->recordState($userId, ADPhotoSync::STATUS_NO_MAIL, null, null, '');

            return;
        }

        $lookup = $this->directory->lookup($addresses);

        if (!$lookup->wasFound()) {
            $result->notInDirectory++;
            $this->recordState($userId, ADPhotoSync::STATUS_NOT_IN_DIRECTORY, null, null, '');

            return;
        }

        if (!$lookup->hasPhoto()) {
            $result->withoutPhoto++;
            $this->recordState($userId, ADPhotoSync::STATUS_NO_PHOTO, null, null, '');

            return;
        }

        $photo = $lookup->getPhoto();
        $photoHash = md5($photo);
        $storedHash = (string) ($candidate['photo_hash'] ?? '');
        $currentAvatarId = (int) ($candidate['avatars_ID'] ?? 0);
        $ourAvatarId = (int) ($candidate['avatar_id'] ?? 0);

        // Nothing to do when the directory photo is unchanged AND the avatar we
        // wrote is still the one on the account. If it was cleared in the
        // meantime we fall through and write it again.
        if (!$force
            && '' !== $storedHash
            && $storedHash === $photoHash
            && $currentAvatarId > 0
            && $currentAvatarId === $ourAvatarId
        ) {
            // The picture itself is unchanged, but the set of accounts that
            // should carry it may not be: a secondary account created after the
            // last run would otherwise wait for the person's photo to change
            // before ever getting one. Re-pointing costs one select and one
            // update, and writes nothing when everything already matches.
            $this->writer->ensureAccountsPointAt(self::baseLoginOf($login), $currentAvatarId);

            $result->unchanged++;

            return;
        }

        if ($dryRun) {
            // Deliberately record nothing: storing the hash here would make the
            // next real run believe this photo had already been written.
            $result->wouldUpdate++;

            return;
        }

        $avatarId = $this->writer->write(self::baseLoginOf($login), $photo, $currentAvatarId);

        $this->recordState($userId, ADPhotoSync::STATUS_SYNCED, $avatarId, $photoHash, '');

        $result->updated++;
    }

    /**
     * Strips a secondary account suffix, so every account of one person
     * resolves to the same base login.
     *
     *   jane.doe@example.com_mdt                      -> jane.doe@example.com
     *   john.doe@example.com_tl_0975b4d9a4-461c0018c7 -> john.doe@example.com
     *   max_mustermann@example.com                        -> unchanged
     *
     * The first underscore after the "@" wins: a suffix may itself contain
     * underscores, and a domain name cannot, so everything from there on is
     * suffix. An underscore before the "@" belongs to the mail address and is
     * left alone.
     *
     * Public and static because it is pure, and worth testing directly.
     */
    public static function baseLoginOf(string $login): string
    {
        $at = strpos($login, '@');

        if (false === $at) {
            return $login;
        }

        $separator = strpos($login, ADPhotoSync::SECONDARY_ACCOUNT_SEPARATOR, $at);

        return false === $separator ? $login : substr($login, 0, $separator);
    }

    /**
     * The accounts this plugin is responsible for at all.
     *
     * Every counter and every list has to agree on this, or the page shows
     * numbers that contradict each other: a total counting accounts the plugin
     * never looks at sits above per-status counts that only cover the ones it
     * does, and the difference has no visible explanation.
     *
     * @return array<int, string>
     */
    private function populationConditions(): array
    {
        $conditions = [
            self::LIVE_USER_CONDITION,
            "`u`.`login` LIKE '%@%'",
            // Secondary accounts are excluded: any underscore after the "@"
            // marks one. They are never looked up on their own — they receive
            // the base account's picture — so counting them as missing a
            // picture would be misleading.
            sprintf(
                "LOCATE(%s, SUBSTRING_INDEX(`u`.`login`, '@', -1)) = 0",
                $this->db->Quote(ADPhotoSync::SECONDARY_ACCOUNT_SEPARATOR)
            ),
        ];

        $exclude = ADPhotoSync::buildExclusionRegex((string) $this->settings->login_exclude);

        if ('' !== $exclude) {
            $conditions[] = sprintf('`u`.`login` NOT REGEXP %s', $this->db->Quote($exclude));
        }

        return $conditions;
    }

    /**
     * The users this run should look at, never-tried accounts first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCandidates(): array
    {
        $conditions = $this->populationConditions();

        if (!$this->settings->overwrite_manual) {
            // Users who have no picture, a placeholder picture, or a picture we
            // put there ourselves.
            $conditions[] = sprintf(
                '(`u`.`avatars_ID` IS NULL OR `u`.`avatars_ID` = 0 '
                . 'OR `a`.`type` IN (%s) OR `s`.`avatar_id` = `u`.`avatars_ID`)',
                implode(',', array_map('intval', self::REPLACEABLE_AVATAR_TYPES))
            );
        }

        // Do not re-read the directory for accounts that recently had no photo
        // or failed outright.
        $conditions[] = sprintf(
            '(`s`.`last_attempt` IS NULL OR `s`.`status` = %s OR `s`.`last_attempt` < %d)',
            $this->db->Quote(ADPhotoSync::STATUS_SYNCED),
            time() - ($this->settings->getRetryAfterHours() * 3600)
        );

        return $this->db->getTableData(
            $this->candidateFrom(),
            $this->candidateFields(),
            implode(' AND ', $conditions),
            '`s`.`last_attempt` IS NULL DESC, `s`.`last_attempt` ASC, `u`.`login` ASC',
            '',
            (string) $this->settings->getBatchLimit()
        );
    }

    /**
     * The join shared by the batch query and the single-user lookup.
     */
    private function candidateFrom(): string
    {
        return sprintf(
            '`users` `u`
                LEFT JOIN `%s` `a` ON `a`.`id` = `u`.`avatars_ID`
                LEFT JOIN `%s` `s` ON `s`.`user_id` = `u`.`id`',
            Avatar::DATABASE_TABLE,
            ADPhotoSync::STATE_TABLE
        );
    }

    private function candidateFields(): string
    {
        return '`u`.`id`, `u`.`login`, `u`.`email`, `u`.`active`, `u`.`archive`, `u`.`avatars_ID`, '
            . '`a`.`type` AS `avatar_type`, '
            . '`s`.`photo_hash`, `s`.`status`, `s`.`last_attempt`, `s`.`avatar_id`';
    }

    /**
     * @throws LdapException
     */
    private function requireConfiguration(): void
    {
        if (!$this->settings->isConfigured()) {
            throw new LdapException(
                'The plugin is not configured: server, bind user, password and search base are required.'
            );
        }
    }

    /**
     * Inserts or updates the per-user state row.
     */
    private function recordState(
        int $userId,
        string $status,
        ?int $avatarId,
        ?string $photoHash,
        string $message
    ): void {
        $fields = [
            'user_id'      => $userId,
            'status'       => $status,
            'last_attempt' => time(),
            'message'      => mb_substr($message, 0, 500),
        ];

        if (null !== $avatarId) {
            $fields['avatar_id'] = $avatarId;
        }

        if (null !== $photoHash) {
            $fields['photo_hash'] = $photoHash;
        }

        $existing = $this->db->countTableData(
            sprintf('`%s`', ADPhotoSync::STATE_TABLE),
            '*',
            sprintf('`user_id` = %d', $userId)
        );

        if ($existing > 0) {
            $this->db->updateTableData(
                sprintf('`%s`', ADPhotoSync::STATE_TABLE),
                $fields,
                sprintf('`user_id` = %d', $userId)
            );

            return;
        }

        $this->db->insertTableData(sprintf('`%s`', ADPhotoSync::STATE_TABLE), $fields);
    }

    /**
     * Counts for the settings page.
     *
     * @return array<string, int>
     */
    public function getStatistics(): array
    {
        return [
            'synced'    => $this->countState(ADPhotoSync::STATUS_SYNCED),
            'no_photo'  => $this->countState(ADPhotoSync::STATUS_NO_PHOTO),
            'not_in_ad' => $this->countState(ADPhotoSync::STATUS_NOT_IN_DIRECTORY),
            'no_mail'   => $this->countState(ADPhotoSync::STATUS_NO_MAIL),
            'failed'    => $this->countState(ADPhotoSync::STATUS_ERROR),
            // Same population as the candidates, so this total and the
            // per-status counts above it describe the same set of accounts.
            'no_avatar' => $this->db->countTableData(
                '`users` `u`',
                '*',
                implode(' AND ', array_merge(
                    $this->populationConditions(),
                    ['(`u`.`avatars_ID` IS NULL OR `u`.`avatars_ID` = 0)']
                ))
            ),
            // Secondary accounts are reported separately rather than folded into
            // the total: they inherit their base account's picture, so they are
            // not missing one in any sense the administrator can act on.
            'secondary' => $this->db->countTableData(
                '`users` `u`',
                '*',
                sprintf(
                    "%s AND `u`.`login` LIKE '%%@%%' "
                    . "AND LOCATE(%s, SUBSTRING_INDEX(`u`.`login`, '@', -1)) > 0",
                    self::LIVE_USER_CONDITION,
                    $this->db->Quote(ADPhotoSync::SECONDARY_ACCOUNT_SEPARATOR)
                )
            ),
        ];
    }

    /**
     * The accounts recorded under one status, most recently checked first.
     *
     * This is what answers "who has no photo in the directory?" — the state
     * table already holds the answer once a run or a check has been done.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUsersByStatus(string $status, int $limit = 500): array
    {
        return $this->db->getTableData(
            sprintf(
                '`%s` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`user_id`',
                ADPhotoSync::STATE_TABLE
            ),
            '`u`.`id`, `u`.`login`, `u`.`name`, `u`.`surname`, `s`.`last_attempt`, `s`.`message`',
            sprintf('`s`.`status` = %s AND %s', $this->db->Quote($status), self::LIVE_USER_CONDITION),
            '`u`.`login` ASC',
            '',
            (string) max(1, $limit)
        );
    }

    /**
     * Counts have to apply the same filter as the lists, or a counter and the
     * table under it disagree and neither can be trusted.
     */
    private function countState(string $status): int
    {
        return $this->db->countTableData(
            sprintf('`%s` `s` INNER JOIN `users` `u` ON `u`.`id` = `s`.`user_id`', ADPhotoSync::STATE_TABLE),
            '*',
            sprintf('`s`.`status` = %s AND %s', $this->db->Quote($status), self::LIVE_USER_CONDITION)
        );
    }
}
