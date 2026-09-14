<?php

namespace Efront\Plugin\ADPhotoSync\Controller;

use Efront\Controller\BaseController;
use Efront\Controller\TemplateController;
use Efront\Controller\UrlhelperController;
use Efront\Model\Form;
use Efront\Model\UserType;
use Efront\Plugin\ADPhotoSync\Model\ADPhotoSync;
use Efront\Plugin\ADPhotoSync\Model\ADPhotoSyncPlugin;
use Efront\Plugin\ADPhotoSync\Model\ADPhotoSyncSettings;
use Efront\Plugin\ADPhotoSync\Model\LdapPhotoSource;
use Efront\Plugin\ADPhotoSync\Model\PhotoSynchronizer;
use Efront\Plugin\ADPhotoSync\Model\SyncResult;

/**
 * Admin page: directory credentials, schedule, a connection test, a full run and
 * a single-user import.
 *
 * Every action sets a message and then redirects back to the bare plugin URL.
 * That is what makes the outcome visible, and it keeps "action=sync" out of the
 * address bar: left there, a refresh would silently start another full run.
 *
 * @package Efront\Plugin\ADPhotoSync\Controller
 */
class ADPhotoSyncSettingsController extends BaseController
{
    public $plugin;

    /**
     * How many accounts each list shows. Enough to work through by hand; the
     * counters above the lists always give the full picture.
     */
    private const LIST_LIMIT = 300;

    /**
     * The lists shown below the counters, as template key => recorded status.
     *
     * Kept apart deliberately: an account missing from the directory is a data
     * problem to chase, while an account without a picture just needs one
     * uploading. Lumping them together hides which is which.
     */
    private const LIST_STATUSES = [
        'not_in_ad' => ADPhotoSync::STATUS_NOT_IN_DIRECTORY,
        'no_photo'  => ADPhotoSync::STATUS_NO_PHOTO,
        'no_mail'   => ADPhotoSync::STATUS_NO_MAIL,
        'failed'    => ADPhotoSync::STATUS_ERROR,
    ];

    protected function _requestPermissionFor()
    {
        return [ADPhotoSyncPlugin::PLUGIN_NAME, UserType::USER_TYPE_ADMINISTRATOR];
    }

    public function index()
    {
        $smarty = self::getSmartyInstance();

        $settings = new ADPhotoSyncSettings(1);
        $url = UrlhelperController::url($_GET);

        $action = isset($_GET['action']) ? (string) $_GET['action'] : '';

        if ('test' === $action) {
            $this->handleTest($settings);
            $this->returnToPage();
        } elseif ('sync' === $action) {
            $this->handleFullRun($settings, false);
            $this->returnToPage();
        } elseif ('check' === $action) {
            $this->handleFullRun($settings, true);
            $this->returnToPage();
        }

        // Two independent forms on one page. eFront's Form tracks submission per
        // form name, so each one only reacts to its own post.
        $singleForm = $this->buildSingleUserForm($settings, $url);
        $form = $settings->form($url);

        if ($form->processed && $form->success) {
            TemplateController::setSuccessMessage();
            $this->returnToPage();
        }

        $statistics = $this->collectStatistics($settings);
        $lists = $this->collectLists($settings);
        $lastRun = (int) $settings->last_run;

        $smarty->assign('T_FORM', $form->toArray())
            ->assign('T_SINGLE_FORM', $singleForm->toArray())
            ->assign('T_PLUGIN_NAME', ADPhotoSync::PLUGIN_NAME)
            ->assign('T_TXT', $this->labels())
            ->assign('T_STALE_CATALOGUE', $this->staleCatalogueWarning())
            ->assign('T_CONFIGURED', $settings->isConfigured())
            ->assign('T_PASSWORD_FROM_ENV', $settings->isPasswordFromEnvironment())
            ->assign('T_PASSWORD_ENV_VAR', ADPhotoSync::PASSWORD_ENV_VAR)
            ->assign('T_LAST_RUN', $lastRun ? date('d.m.Y H:i', $lastRun) : '')
            ->assign('T_LAST_RESULT', SyncResult::describeStored((string) $settings->last_result))
            ->assign('T_STATISTICS', $statistics)
            ->assign('T_NOT_IN_AD_USERS', $lists['not_in_ad'])
            ->assign('T_NO_PHOTO_USERS', $lists['no_photo'])
            ->assign('T_NO_MAIL_USERS', $lists['no_mail'])
            ->assign('T_FAILED_USERS', $lists['failed'])
            ->assign('T_LIST_LIMIT', self::LIST_LIMIT)
            ->assign('T_TEST_URL', UrlhelperController::url(['ctg' => ADPhotoSync::PLUGIN_NAME, 'action' => 'test']))
            ->assign('T_CHECK_URL', UrlhelperController::url(['ctg' => ADPhotoSync::PLUGIN_NAME, 'action' => 'check']))
            ->assign('T_SYNC_URL', UrlhelperController::url(['ctg' => ADPhotoSync::PLUGIN_NAME, 'action' => 'sync']));
    }

    /**
     * Back to the plugin page without any action parameter, so the message is
     * rendered once and a refresh repeats nothing.
     */
    private function returnToPage(): void
    {
        UrlhelperController::redirect(UrlhelperController::url(['ctg' => ADPhotoSync::PLUGIN_NAME]));
    }

    /**
     * Warns when PHP is serving translations from an older version of the
     * plugin, or an empty string when everything is current.
     *
     * gettext loads a catalogue once per process and never re-reads it, while
     * OPcache does pick up changed PHP. After an update the two therefore
     * disagree: the current code asks for strings the loaded catalogue has
     * never heard of, and those fall back to English. It looks like a broken
     * translation rather than a process that needs restarting, which is exactly
     * the confusion this sentence is here to end.
     */
    private function staleCatalogueWarning(): string
    {
        $catalogue = ADPhotoSync::catalogueVersion();

        // No catalogue at all is normal — an untranslated language falls back to
        // English by design, and there is nothing to restart for.
        if ('' === $catalogue || $catalogue === ADPhotoSyncPlugin::VERSION) {
            return '';
        }

        return ADPhotoSync::t(
            'The translations loaded by PHP are from version %s, but the plugin is version %s. '
            . 'Restart PHP (systemctl restart php-fpm) to pick up the current translations; '
            . 'until then some labels stay in English.',
            $catalogue,
            ADPhotoSyncPlugin::VERSION
        );
    }

    /**
     * Every piece of text the template shows, translated here rather than there.
     *
     * The Smarty modifier eF_dtranslate calls dtranslate() directly, and that
     * function does not guard its vsprintf the way translate() does. Anything
     * leaving a stray placeholder in the final text throws a ValueError that
     * replaces the whole page with "a serious error has occurred" — including
     * the language override table, which dtranslate() applies *after* the
     * translation and which this plugin has no control over. Going through
     * ADPhotoSync::t() here keeps that from ever reaching the renderer.
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        return [
            'overview'       => ADPhotoSync::t('Overview'),
            'last_run'       => ADPhotoSync::t('Last run'),
            'never'          => ADPhotoSync::t('never'),
            'synced'         => ADPhotoSync::t('Photo taken from Active Directory'),
            'no_photo'       => ADPhotoSync::t('In Active Directory, but no photo stored'),
            'not_in_ad'      => ADPhotoSync::t('Not found in Active Directory'),
            'no_mail'        => ADPhotoSync::t('No mail address in the LMS'),
            'errors'         => ADPhotoSync::t('Errors'),
            'no_avatar'      => ADPhotoSync::t('Base accounts still without any picture'),
            'secondary'      => ADPhotoSync::t('Secondary accounts (they inherit the base account picture)'),
            'bind_password'  => ADPhotoSync::t('Bind password'),
            'test'           => ADPhotoSync::t('Test connection'),
            'check'          => ADPhotoSync::t('Check only, write nothing'),
            'sync'           => ADPhotoSync::t('Run synchronisation now'),
            'login'          => ADPhotoSync::t('Login'),
            'name'           => ADPhotoSync::t('Name'),
            'checked'        => ADPhotoSync::t('Checked'),
            'reason'         => ADPhotoSync::t('Reason'),
            'truncated'      => ADPhotoSync::t(
                'Only the first entries are listed; the counter above gives the total.'
            ),
            'nothing'        => ADPhotoSync::t('Nothing recorded.'),
            'nothing_yet'    => ADPhotoSync::t(
                "Nothing recorded yet. Use 'Check only, write nothing' to find out which accounts "
                . 'the directory has no photo for.'
            ),
            'not_in_ad_hint' => ADPhotoSync::t(
                'No entry with this mail address exists in the search base. Usually a leaver, a typo '
                . 'in the LMS address, or an account outside the configured organisational unit.'
            ),
            'no_photo_hint'  => ADPhotoSync::t(
                'These accounts exist in Active Directory, but no picture is stored in the photo '
                . 'attribute. Someone has to upload one there; the plugin will pick it up on the next run.'
            ),
            'no_mail_hint'   => ADPhotoSync::t(
                'These LMS accounts carry no mail address, so there is nothing to look the person up by.'
            ),
            'errors_hint'    => ADPhotoSync::t(
                'Something went wrong for these accounts. The reason is shown next to each one.'
            ),
            'single_title'   => ADPhotoSync::t('Synchronise a single user'),
            'single_hint'    => ADPhotoSync::t(
                'Fetches the photo for one account immediately, even if it is unchanged.'
            ),
            'settings_title' => ADPhotoSync::t('Settings'),
        ];
    }

    /**
     * Statistics must never take the page down; an empty state table or a
     * half-finished install would otherwise leave the administrator with no way
     * to reach the settings at all.
     *
     * @return array<string, int>
     */
    private function collectStatistics(ADPhotoSyncSettings $settings): array
    {
        try {
            return (new PhotoSynchronizer($settings))->getStatistics();
        } catch (\Throwable $e) {
            error_log('[ADPhotoSync] could not read statistics: ' . $e->getMessage());

            return [
                'synced' => 0, 'no_photo' => 0, 'not_in_ad' => 0, 'no_mail' => 0,
                'failed' => 0, 'no_avatar' => 0, 'secondary' => 0,
            ];
        }
    }

    /**
     * The accounts to list by name: those the directory has no photo for, and
     * those that failed. Both come from the state table, which is filled by a
     * run or by a check.
     *
     * @return array{no_photo: array<int, array<string, mixed>>, failed: array<int, array<string, mixed>>}
     */
    private function collectLists(ADPhotoSyncSettings $settings): array
    {
        try {
            $synchronizer = new PhotoSynchronizer($settings);

            $lists = [];

            foreach (self::LIST_STATUSES as $key => $status) {
                $lists[$key] = $this->formatEntries($synchronizer->getUsersByStatus($status, self::LIST_LIMIT));
            }

            return $lists;
        } catch (\Throwable $e) {
            error_log('[ADPhotoSync] could not read the user lists: ' . $e->getMessage());

            return array_fill_keys(array_keys(self::LIST_STATUSES), []);
        }
    }

    /**
     * Adds display-ready fields, so the template needs no Smarty modifier that
     * eFront may or may not register.
     *
     * @param  array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function formatEntries(array $entries): array
    {
        foreach ($entries as $index => $entry) {
            $attempt = (int) ($entry['last_attempt'] ?? 0);

            $entries[$index]['checked'] = $attempt ? date('d.m.Y H:i', $attempt) : '';
            $entries[$index]['fullname'] = trim(
                (string) ($entry['name'] ?? '') . ' ' . (string) ($entry['surname'] ?? '')
            );
        }

        return $entries;
    }

    /**
     * The single-user import: enter a login or mail address and fetch just that
     * one photo. Ignores the stored hash and the "leave manual uploads alone"
     * rule, because the administrator named this account explicitly.
     */
    private function buildSingleUserForm(ADPhotoSyncSettings $settings, string $url): Form
    {
        $form = new Form('ad_photo_sync_single_form', 'post', $url, '', null, true);

        $form->addElement(
            'text',
            'single_user',
            ADPhotoSync::t('Login or mail address'),
            'class = "form-control" placeholder = "vorname.nachname@example.com"'
        );

        $form->addElement(
            'submit',
            'submit_single',
            ADPhotoSync::t('Synchronise this user'),
            'class = "btn btn-default" data-processing-msg = "' . translate('Please wait...') . '"'
        );

        if (!$form->isSubmitted() || !$form->validate()) {
            return $form;
        }

        $values = $form->exportValues();
        $identifier = trim((string) ($values['single_user'] ?? ''));

        try {
            $result = (new PhotoSynchronizer($settings))->syncOne($identifier);

            if ($result->updated > 0) {
                TemplateController::setMessage(
                    sprintf(
                        '%s: %s',
                        ADPhotoSync::t('Synchronisation finished'),
                        SyncResult::describeStored($result->toStorage())
                    ),
                    'success'
                );
            } else {
                TemplateController::setMessage($this->explainNoResult($result, $identifier), 'warning');
            }
        } catch (\Throwable $e) {
            // Anything at all, so a directory or image failure surfaces as a
            // message rather than a blank page.
            TemplateController::setMessage($this->describe($e), 'failure');
        }

        $this->returnToPage();

        return $form;
    }

    /**
     * Says why a single-user import produced nothing.
     *
     * "No picture" on its own is not actionable: whether the account is missing
     * from the directory or merely has no photo stored leads to entirely
     * different next steps.
     */
    private function explainNoResult(SyncResult $result, string $identifier): string
    {
        if ([] !== $result->errors) {
            return implode('; ', $result->errors);
        }

        if ($result->notInDirectory > 0) {
            return ADPhotoSync::t('No entry for "%s" was found in Active Directory.', $identifier
            );
        }

        if ($result->withoutMail > 0) {
            return ADPhotoSync::t('"%s" has no mail address in the LMS, so there is nothing to look up.', $identifier
            );
        }

        return ADPhotoSync::t('No photo in Active Directory');
    }

    /**
     * Verifies the stored credentials without touching any user.
     */
    private function handleTest(ADPhotoSyncSettings $settings): void
    {
        if (!$settings->isConfigured()) {
            TemplateController::setMessage(
                ADPhotoSync::t('Connection failed') . ': '
                . ADPhotoSync::t('Server, bind user, password and search base are required.'),
                'failure'
            );

            return;
        }

        try {
            LdapPhotoSource::fromSettings($settings)->testConnection();

            // dtranslate() substitutes the arguments itself; an enclosing
            // sprintf() would leave it with placeholders and nothing to fill
            // them, which throws.
            TemplateController::setMessage(
                ADPhotoSync::t('Connection successful: bound as "%s", search base readable.', (string) $settings->bind_dn
                ),
                'success'
            );
        } catch (\Throwable $e) {
            TemplateController::setMessage(
                ADPhotoSync::t('Connection failed') . ': ' . $this->describe($e),
                'failure'
            );
        }
    }

    /**
     * Runs a batch immediately, ignoring the configured interval.
     */
    private function handleFullRun(ADPhotoSyncSettings $settings, bool $dryRun): void
    {
        try {
            $result = (new PhotoSynchronizer($settings))->run($dryRun);

            $message = ADPhotoSync::t('Synchronisation finished') . ': ' . SyncResult::describeStored($result->toStorage());

            if ([] !== $result->errors) {
                $message .= ' — ' . implode('; ', array_slice($result->errors, 0, 3));
            }

            TemplateController::setMessage($message, [] === $result->errors ? 'success' : 'warning');
        } catch (\Throwable $e) {
            TemplateController::setMessage($this->describe($e), 'failure');
        }
    }

    /**
     * A message an administrator can act on. PHP errors carry no useful text of
     * their own here, so the origin is included.
     */
    private function describe(\Throwable $e): string
    {
        $message = trim($e->getMessage());

        if ('' === $message) {
            $message = get_class($e);
        }

        return sprintf('%s (%s:%d)', $message, basename($e->getFile()), $e->getLine());
    }
}
