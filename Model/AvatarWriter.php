<?php

namespace Efront\Plugin\ADPhotoSync\Model;

use Efront\Model\Avatar;
use Efront\Model\Database;
use Efront\Model\User;

/**
 * Turns raw directory photo bytes into the three PNG renditions eFront stores,
 * and writes them to the avatars table.
 *
 * Two hard-won rules from the shell scripts this plugin replaces:
 *
 *  - eFront keeps avatars as base64 PNG data URIs in TEXT columns, not as
 *    binary blobs, and not as JPEG.
 *  - An existing avatar row must be updated in place. Creating a new row and
 *    repointing users.avatars_ID leaves the old picture visible, because the
 *    interface caches per avatar and the stale entry survives.
 *
 * Resizing uses GD, which eFront already requires, so no ImageMagick binary is
 * needed.
 *
 * @package Efront\Plugin\ADPhotoSync\Model
 */
class AvatarWriter
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    /**
     * Writes the photo for one base account and every secondary account that
     * belongs to it.
     *
     * @param  string $baseLogin
     *          - The login of the primary account, used as the prefix that
     *            identifies the secondary accounts.
     * @return int
     *          - The avatar id now referenced by all of those accounts.
     * @throws LdapException
     */
    public function write(string $baseLogin, string $photoBytes, int $existingAvatarId): int
    {
        $large = self::renderDataUri($photoBytes, ADPhotoSync::SIZE_LARGE);
        $medium = self::renderDataUri($photoBytes, ADPhotoSync::SIZE_MEDIUM);
        $small = self::renderDataUri($photoBytes, ADPhotoSync::SIZE_SMALL);

        // The hash is what the interface uses to bust its avatar cache, so it
        // has to change whenever the image does.
        $hash = md5($photoBytes . '|' . $baseLogin);

        $fields = [
            'data'         => $large,
            'data_medium'  => $medium,
            'data_small'   => $small,
            'hash'         => $hash,
            'last_updated' => time(),
        ];

        if ($existingAvatarId > 0) {
            $this->db->updateTableData(
                sprintf('`%s`', Avatar::DATABASE_TABLE),
                $fields,
                sprintf('`id` = %d', $existingAvatarId)
            );

            $avatarId = $existingAvatarId;
        } else {
            $fields['data_original'] = null;
            $fields['type'] = Avatar::TYPE_DEFAULT;

            $inserted = $this->db->insertTableData(sprintf('`%s`', Avatar::DATABASE_TABLE), $fields);

            if (!is_int($inserted) || $inserted <= 0) {
                throw new LdapException('Creating the avatar row did not return an id.');
            }

            $avatarId = $inserted;
        }

        $this->ensureAccountsPointAt($baseLogin, $avatarId);

        return $avatarId;
    }

    /**
     * Points the base account and its secondary accounts at the same avatar, so
     * a person recognises themselves whichever account they signed in with.
     *
     * Called after writing a picture, and again on every run where the picture
     * turned out unchanged: the image may be the same as last time while the set
     * of accounts is not. A secondary account created since the last run would
     * otherwise have to wait for that person's photo to change before it ever
     * received one.
     *
     * Writes nothing when every account already points at this avatar.
     */
    public function ensureAccountsPointAt(string $baseLogin, int $avatarId): void
    {
        $condition = $this->accountsCondition($baseLogin);

        $accounts = $this->db->getTableData('`users`', '`id`', $condition);

        $this->db->execute(sprintf(
            'UPDATE `users` SET `avatars_ID` = %d WHERE %s '
            . 'AND (`avatars_ID` IS NULL OR `avatars_ID` != %1$d)',
            $avatarId,
            $condition
        ));

        $this->invalidateCaches($accounts, $avatarId);
    }

    /**
     * Matches the base account and its secondary accounts, and nothing else.
     *
     * A prefix LIKE would also catch an unrelated login that merely starts with
     * the same text, so the suffix form is spelled out: the login is either the
     * base itself, or the base followed by the separator. No LIKE wildcards are
     * involved, which also avoids "_" being read as one.
     */
    private function accountsCondition(string $baseLogin): string
    {
        $quoted = $this->db->Quote($baseLogin);
        $withSeparator = $this->db->Quote($baseLogin . ADPhotoSync::SECONDARY_ACCOUNT_SEPARATOR);

        return sprintf(
            '(`login` = %s OR LEFT(`login`, CHAR_LENGTH(%s)) = %2$s)',
            $quoted,
            $withSeparator
        );
    }

    /**
     * Drops the cached copies of everything this write touched.
     *
     * eFront caches model rows, and the cached user record carries avatars_ID.
     * Writing the tables directly leaves those copies pointing at the previous
     * picture, so the interface keeps showing the old image or the placeholder
     * even though the database is correct. The regular upload path avoids this
     * by calling deleteCache() on the related model.
     *
     * @param array<int, array<string, mixed>> $accounts
     */
    private function invalidateCaches(array $accounts, int $avatarId): void
    {
        foreach ($accounts as $account) {
            try {
                (new User((int) $account['id']))->deleteCache();
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[ADPhotoSync] could not clear the cache for user %s: %s',
                    (string) $account['id'],
                    $e->getMessage()
                ));
            }
        }

        try {
            (new Avatar($avatarId))->deleteCache();
        } catch (\Throwable $e) {
            error_log(sprintf('[ADPhotoSync] could not clear the cache for avatar %d: %s', $avatarId, $e->getMessage()));
        }
    }

    /**
     * Scales the image to cover the target box, centre-crops it and returns a
     * PNG data URI. Mirrors ImageMagick's "-resize WxH^ -gravity center
     * -extent WxH".
     *
     * @param  array{0: int, 1: int} $size
     * @throws LdapException
     */
    public static function renderDataUri(string $photoBytes, array $size): string
    {
        [$targetWidth, $targetHeight] = $size;

        $source = @imagecreatefromstring($photoBytes);

        if (false === $source) {
            throw new LdapException('The directory returned data that is not a readable image.');
        }

        try {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);

            if ($sourceWidth < 1 || $sourceHeight < 1) {
                throw new LdapException('The directory returned an image with no dimensions.');
            }

            // Cover: scale by the larger ratio so neither side falls short.
            $scale = max($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
            $scaledWidth = (int) ceil($sourceWidth * $scale);
            $scaledHeight = (int) ceil($sourceHeight * $scale);

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

            if (false === $canvas) {
                throw new LdapException('Could not allocate the target image.');
            }

            try {
                // Keep transparency intact for sources that have it.
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);

                $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);

                $offsetX = (int) round(($scaledWidth - $targetWidth) / 2);
                $offsetY = (int) round(($scaledHeight - $targetHeight) / 2);

                imagecopyresampled(
                    $canvas,
                    $source,
                    -$offsetX,
                    -$offsetY,
                    0,
                    0,
                    $scaledWidth,
                    $scaledHeight,
                    $sourceWidth,
                    $sourceHeight
                );

                ob_start();
                imagepng($canvas, null, 9);
                $png = (string) ob_get_clean();
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($source);
        }

        if ('' === $png) {
            throw new LdapException('Encoding the avatar as PNG produced no data.');
        }

        return sprintf('data:%s;base64,%s', ADPhotoSync::IMAGE_MIME, base64_encode($png));
    }
}
