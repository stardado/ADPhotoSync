<?php

namespace Efront\Plugin\ADPhotoSync\Model;

/**
 * The outcome of looking one account up in the directory.
 *
 * "No picture" covers two very different situations, and an administrator needs
 * to tell them apart: an account that does not exist in the directory at all is
 * a data problem worth chasing, while an account that exists but has no photo
 * simply needs someone to upload one.
 *
 * @package Efront\Plugin\ADPhotoSync\Model
 */
class DirectoryLookup
{
    private bool $found;
    private ?string $photo;

    private function __construct(bool $found, ?string $photo)
    {
        $this->found = $found;
        $this->photo = $photo;
    }

    /** No entry with that mail address exists in the search base. */
    public static function notFound(): self
    {
        return new self(false, null);
    }

    /** The account exists, but carries no picture. */
    public static function withoutPhoto(): self
    {
        return new self(true, null);
    }

    public static function withPhoto(string $photo): self
    {
        return new self(true, $photo);
    }

    public function wasFound(): bool
    {
        return $this->found;
    }

    public function hasPhoto(): bool
    {
        return null !== $this->photo && '' !== $this->photo;
    }

    public function getPhoto(): string
    {
        if (!$this->hasPhoto()) {
            throw new LdapException('No photo was returned for this account.');
        }

        return (string) $this->photo;
    }
}
