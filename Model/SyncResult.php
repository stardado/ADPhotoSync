<?php

namespace Efront\Plugin\ADPhotoSync\Model;

/**
 * Outcome of a single synchronisation run.
 *
 * @package Efront\Plugin\ADPhotoSync\Model
 */
class SyncResult
{
    public int $examined = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    /** Found in the directory, but carrying no picture. */
    public int $withoutPhoto = 0;

    /** No entry with that mail address exists in the search base. */
    public int $notInDirectory = 0;

    /** The eFront account has no mail address to look up. */
    public int $withoutMail = 0;

    public int $failed = 0;

    /** Set for a check that reports without writing. */
    public bool $dryRun = false;

    /** Accounts a real run would write, counted only during a dry run. */
    public int $wouldUpdate = 0;

    /** @var array<int, string> */
    public array $errors = [];

    public function addError(string $message): void
    {
        // Keep the list bounded; a broken directory would otherwise produce one
        // entry per user and bloat the settings row.
        if (count($this->errors) < 20) {
            $this->errors[] = $message;
        }
    }

    /** Marks a stored result as the parseable form rather than free text. */
    private const STORAGE_PREFIX = 'v2|';

    /**
     * The counters in a form that can be stored and translated later.
     *
     * The summary is written to the settings row during a run and shown on the
     * page afterwards — possibly to somebody else, in another language, and for
     * a cron run to nobody in particular. Storing the numbers rather than a
     * finished sentence is what lets the page render it in the reader's
     * language instead of the language of whoever happened to start the run.
     */
    public function toStorage(): string
    {
        return self::STORAGE_PREFIX . http_build_query([
            'examined'  => $this->examined,
            'updated'   => $this->dryRun ? $this->wouldUpdate : $this->updated,
            'unchanged' => $this->unchanged,
            'no_photo'  => $this->withoutPhoto,
            'not_in_ad' => $this->notInDirectory,
            'no_mail'   => $this->withoutMail,
            'failed'    => $this->failed,
            'dry'       => $this->dryRun ? 1 : 0,
        ], '', '&');
    }

    /**
     * Renders a stored result in the reader's language.
     *
     * Anything that is not in the stored format is handed back untouched: a
     * result written by an earlier version is an English sentence, and showing
     * it as it stands beats showing nothing.
     */
    public static function describeStored(string $stored): string
    {
        $stored = trim($stored);

        if ('' === $stored || 0 !== strpos($stored, self::STORAGE_PREFIX)) {
            return $stored;
        }

        parse_str(substr($stored, strlen(self::STORAGE_PREFIX)), $values);

        $number = static fn (string $key): int => (int) ($values[$key] ?? 0);

        $pattern = empty($values['dry'])
            ? 'examined %1$d, updated %2$d, unchanged %3$d, no photo %4$d, '
                . 'not in Active Directory %5$d, without a mail address %6$d, failed %7$d'
            : 'examined %1$d, would update %2$d, unchanged %3$d, no photo %4$d, '
                . 'not in Active Directory %5$d, without a mail address %6$d, failed %7$d';

        return ADPhotoSync::t(
            $pattern,
            $number('examined'),
            $number('updated'),
            $number('unchanged'),
            $number('no_photo'),
            $number('not_in_ad'),
            $number('no_mail'),
            $number('failed')
        );
    }

    /**
     * One-line summary in English, for the log and for messages shown straight
     * after a run. What goes into the database is toStorage().
     */
    public function summary(): string
    {
        $parts = [sprintf('examined %d', $this->examined)];

        $parts[] = $this->dryRun
            ? sprintf('would update %d', $this->wouldUpdate)
            : sprintf('updated %d', $this->updated);

        if (!$this->dryRun) {
            $parts[] = sprintf('unchanged %d', $this->unchanged);
        }

        $parts[] = sprintf('no photo %d', $this->withoutPhoto);
        $parts[] = sprintf('not in AD %d', $this->notInDirectory);

        if ($this->withoutMail > 0) {
            $parts[] = sprintf('no mail %d', $this->withoutMail);
        }

        $parts[] = sprintf('failed %d', $this->failed);

        return implode(', ', $parts);
    }
}
