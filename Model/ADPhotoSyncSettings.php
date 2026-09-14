<?php

namespace Efront\Plugin\ADPhotoSync\Model;

use Efront\Model\BaseModel;
use Efront\Model\Form;

/**
 * Plugin configuration, stored as a single row in the settings table.
 *
 * @package Efront\Plugin\ADPhotoSync\Model
 */
class ADPhotoSyncSettings extends BaseModel
{
    public const DATABASE_TABLE = ADPhotoSync::SETTINGS_TABLE;

    protected $_fields = [
        'id'                 => 'id',
        'ldap_uri'           => '',
        'bind_dn'            => '',
        'bind_password'      => '',
        'base_dn'            => '',
        'mail_attribute'     => '',
        'photo_attribute'    => '',
        'verify_certificate' => '',
        'login_exclude'      => '',
        'sync_enabled'       => '',
        'batch_limit'        => '',
        'interval_hours'     => '',
        'retry_after_hours'  => '',
        'overwrite_manual'   => '',
        'last_run'           => '',
        'last_result'        => '',
    ];

    public $id;
    public $ldap_uri;
    public $bind_dn;
    public $bind_password;
    public $base_dn;
    public $mail_attribute;
    public $photo_attribute;
    public $verify_certificate;
    public $login_exclude;
    public $sync_enabled;
    public $batch_limit;
    public $interval_hours;
    public $retry_after_hours;
    public $overwrite_manual;
    public $last_run;
    public $last_result;

    /**
     * The bind password, preferring the environment variable over the database
     * so the credential can be kept out of SQL dumps.
     */
    public function getBindPassword(): string
    {
        $fromEnv = getenv(ADPhotoSync::PASSWORD_ENV_VAR);

        if (is_string($fromEnv) && '' !== trim($fromEnv)) {
            return trim($fromEnv);
        }

        return (string) $this->bind_password;
    }

    public function isPasswordFromEnvironment(): bool
    {
        $fromEnv = getenv(ADPhotoSync::PASSWORD_ENV_VAR);

        return is_string($fromEnv) && '' !== trim($fromEnv);
    }

    public function isConfigured(): bool
    {
        return '' !== trim((string) $this->ldap_uri)
            && '' !== trim((string) $this->bind_dn)
            && '' !== trim((string) $this->base_dn)
            && '' !== trim($this->getBindPassword());
    }

    /**
     * Whether enough time has passed since the last run for the cron hook to do
     * any work. Manual runs from the settings page bypass this.
     */
    public function isDueForRun(): bool
    {
        if (!$this->sync_enabled) {
            return false;
        }

        $interval = max(1, (int) $this->interval_hours);

        return (int) $this->last_run + ($interval * 3600) <= time();
    }

    public function getMailAttribute(): string
    {
        return trim((string) $this->mail_attribute) ?: ADPhotoSync::DEFAULT_MAIL_ATTRIBUTE;
    }

    /**
     * The mail attributes to search, in order.
     *
     * Stored as one comma separated string so the setting needs no schema
     * change. Listing `proxyAddresses` alongside `mail` is what finds people
     * whose address changed — after a rename the former address survives only
     * as a secondary entry there.
     *
     * @return array<int, string>
     */
    public function getMailAttributes(): array
    {
        return LdapPhotoSource::normaliseAttributes($this->getMailAttribute());
    }

    public function getPhotoAttribute(): string
    {
        return trim((string) $this->photo_attribute) ?: ADPhotoSync::DEFAULT_PHOTO_ATTRIBUTE;
    }

    public function getBatchLimit(): int
    {
        return max(1, min(5000, (int) $this->batch_limit ?: 100));
    }

    public function getRetryAfterHours(): int
    {
        return max(1, (int) $this->retry_after_hours ?: 168);
    }

    /**
     * Builds the settings form.
     *
     * @param  string $url
     * @return Form
     */
    public function form($url)
    {
        $form = new Form('ad_photo_sync_settings_form', 'post', $url, '', null, true);

        $form->addElement(
            'text',
            'ldap_uri',
            ADPhotoSync::t('Directory server'),
            'class = "form-control" placeholder = "ldaps://dc1.example.com:636"'
        );

        $form->addElement(
            'text',
            'bind_dn',
            ADPhotoSync::t('Bind user'),
            'class = "form-control" placeholder = "DOMAIN\\\\svc_ldap"'
        );

        if ($this->isPasswordFromEnvironment()) {
            $form->addElement(
                'static',
                'bind_password_env',
                ADPhotoSync::t('Bind password'),
                ADPhotoSync::PASSWORD_ENV_VAR
            );
        } else {
            $form->addElement(
                'password',
                'bind_password',
                ADPhotoSync::t('Bind password'),
                'class = "form-control" autocomplete = "new-password" placeholder = "'
                . ADPhotoSync::t('Leave empty to keep the stored password')
                . '"'
            );
        }

        $form->addElement(
            'text',
            'base_dn',
            ADPhotoSync::t('Search base (DN)'),
            'class = "form-control" placeholder = "OU=Users,DC=example,DC=local"'
        );

        $form->addElement(
            'text',
            'mail_attribute',
            ADPhotoSync::t('Mail attributes (comma separated)'),
            'class = "form-control" placeholder = "mail, proxyAddresses"'
        );

        $form->addElement(
            'static',
            'mail_attribute_hint',
            '',
            ADPhotoSync::t(
                'Add proxyAddresses to find people whose address changed: after a rename the former '
                . 'address survives only as a secondary entry there.'
            )
        );

        $form->addElement(
            'text',
            'photo_attribute',
            ADPhotoSync::t('Photo attribute'),
            'class = "form-control" placeholder = "thumbnailPhoto"'
        );

        $form->addElement(
            'checkbox',
            'verify_certificate',
            null,
            ADPhotoSync::t('Verify the TLS certificate of the directory server')
        );

        $form->addElement(
            'text',
            'login_exclude',
            ADPhotoSync::t('Skip logins matching (comma separated, * allowed)'),
            'class = "form-control" placeholder = "*@example.org, muster"'
        );

        $form->addElement(
            'checkbox',
            'sync_enabled',
            null,
            ADPhotoSync::t('Enable automatic synchronisation')
        );

        $form->addElement(
            'text',
            'batch_limit',
            ADPhotoSync::t('Users per run'),
            'class = "form-control" placeholder = "100"'
        );

        $form->addElement(
            'text',
            'interval_hours',
            ADPhotoSync::t('Minimum hours between runs'),
            'class = "form-control" placeholder = "24"'
        );

        $form->addElement(
            'text',
            'retry_after_hours',
            ADPhotoSync::t('Retry users without a photo after (hours)'),
            'class = "form-control" placeholder = "168"'
        );

        $form->addElement(
            'checkbox',
            'overwrite_manual',
            null,
            ADPhotoSync::t('Replace photos that users uploaded themselves')
        );

        $form->addElement(
            'submit',
            'submit',
            translate('Update'),
            'class = "btn btn-primary" data-processing-msg = "' . translate('Updating...') . '"'
        );

        $form->setDefaults([
            'ldap_uri'           => $this->ldap_uri,
            'bind_dn'            => $this->bind_dn,
            'base_dn'            => $this->base_dn,
            'mail_attribute'     => $this->getMailAttribute(),
            'photo_attribute'    => $this->getPhotoAttribute(),
            'verify_certificate' => (int) $this->verify_certificate,
            'login_exclude'      => $this->login_exclude,
            'sync_enabled'       => (int) $this->sync_enabled,
            'batch_limit'        => $this->getBatchLimit(),
            'interval_hours'     => max(1, (int) $this->interval_hours ?: 24),
            'retry_after_hours'  => $this->getRetryAfterHours(),
            'overwrite_manual'   => (int) $this->overwrite_manual,
        ]);

        if ($form->isSubmitted() && $form->validate()) {
            try {
                $values = $form->exportValues();

                $fields = [
                    'ldap_uri'           => trim((string) ($values['ldap_uri'] ?? '')),
                    'bind_dn'            => trim((string) ($values['bind_dn'] ?? '')),
                    'base_dn'            => trim((string) ($values['base_dn'] ?? '')),
                    'mail_attribute'     => trim((string) ($values['mail_attribute'] ?? ''))
                        ?: ADPhotoSync::DEFAULT_MAIL_ATTRIBUTE,
                    'photo_attribute'    => trim((string) ($values['photo_attribute'] ?? ''))
                        ?: ADPhotoSync::DEFAULT_PHOTO_ATTRIBUTE,
                    'verify_certificate' => empty($values['verify_certificate']) ? 0 : 1,
                    'login_exclude'      => trim((string) ($values['login_exclude'] ?? '')),
                    'sync_enabled'       => empty($values['sync_enabled']) ? 0 : 1,
                    'batch_limit'        => max(1, min(5000, (int) ($values['batch_limit'] ?? 100))),
                    'interval_hours'     => max(1, (int) ($values['interval_hours'] ?? 24)),
                    'retry_after_hours'  => max(1, (int) ($values['retry_after_hours'] ?? 168)),
                    'overwrite_manual'   => empty($values['overwrite_manual']) ? 0 : 1,
                ];

                // An empty password field means "keep what is stored", so editing
                // any other setting never wipes the credential by accident.
                $submitted = trim((string) ($values['bind_password'] ?? ''));

                if (!$this->isPasswordFromEnvironment() && '' !== $submitted) {
                    $fields['bind_password'] = $submitted;
                }

                $this->setFields($fields)->save();

                $form->processed = $form->success = true;
            } catch (\Exception $e) {
                handleNormalFlowExceptions($e);
            }
        }

        return $form;
    }
}
