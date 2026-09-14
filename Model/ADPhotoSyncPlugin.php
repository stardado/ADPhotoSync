<?php

namespace Efront\Plugin\ADPhotoSync\Model;

use Efront\Controller\BaseController;
use Efront\Controller\UrlhelperController;
use Efront\Model\AbstractPlugin;
use Efront\Model\Database;
use Efront\Model\User;
use Efront\Model\UserType;
use Efront\Plugin\ADPhotoSync\Controller\ADPhotoSyncSettingsController;

/**
 * Keeps eFront user avatars in sync with the photos stored in Active Directory.
 *
 * The plugin is self-contained: it holds its own directory credentials and does
 * not depend on any other synchronisation or sign-on configuration in the host
 * application.
 *
 * @package Efront\Plugin\ADPhotoSync\Model
 */
class ADPhotoSyncPlugin extends AbstractPlugin
{
    public const VERSION = '2.12.0'; // @phpstan-ignore-line
    public const PLUGIN_NAME = ADPhotoSync::PLUGIN_NAME;

    /**
     * Creates the settings and state tables and seeds a default configuration.
     */
    public function installPlugin()
    {
        $settingsTable = ADPhotoSync::SETTINGS_TABLE;
        $stateTable = ADPhotoSync::STATE_TABLE;

        $createSettings = "CREATE TABLE IF NOT EXISTS `{$settingsTable}` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `ldap_uri` VARCHAR(255) NOT NULL DEFAULT '',
            `bind_dn` VARCHAR(255) NOT NULL DEFAULT '',
            `bind_password` VARCHAR(255) NOT NULL DEFAULT '',
            `base_dn` VARCHAR(512) NOT NULL DEFAULT '',
            `mail_attribute` VARCHAR(255) NOT NULL DEFAULT 'mail, proxyAddresses',
            `photo_attribute` VARCHAR(64) NOT NULL DEFAULT 'thumbnailPhoto',
            `verify_certificate` TINYINT(1) NOT NULL DEFAULT 0,
            `login_exclude` VARCHAR(255) NOT NULL DEFAULT '',
            `sync_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `batch_limit` INT(11) NOT NULL DEFAULT 100,
            `interval_hours` INT(11) NOT NULL DEFAULT 24,
            `retry_after_hours` INT(11) NOT NULL DEFAULT 168,
            `overwrite_manual` TINYINT(1) NOT NULL DEFAULT 0,
            `last_run` INT(11) NOT NULL DEFAULT 0,
            `last_result` VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`) USING BTREE
        ) DEFAULT CHARSET=utf8mb4;";

        $createState = "CREATE TABLE IF NOT EXISTS `{$stateTable}` (
            `user_id` INT(11) NOT NULL,
            `avatar_id` INT(11) NULL DEFAULT NULL,
            `photo_hash` VARCHAR(32) NULL DEFAULT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT '',
            `last_attempt` INT(11) NOT NULL DEFAULT 0,
            `message` VARCHAR(500) NULL DEFAULT NULL,
            PRIMARY KEY (`user_id`) USING BTREE,
            INDEX `idx_status_attempt` (`status`, `last_attempt`)
        ) DEFAULT CHARSET=utf8mb4;";

        $seedSettings = "INSERT IGNORE INTO `{$settingsTable}` (`id`) VALUES (1);";

        try {
            $db = Database::getInstance();
            $db->execute($createSettings);
            $db->execute($createState);
            $db->execute($seedSettings);
        } catch (\Exception $e) {
            $this->uninstallPlugin();

            throw $e;
        }
    }

    /**
     * Drops the plugin's own tables.
     *
     * Avatars already written to users are intentionally left in place: they are
     * ordinary eFront avatars, and removing them would blank out profiles.
     */
    public function uninstallPlugin()
    {
        $db = Database::getInstance();

        foreach ([ADPhotoSync::STATE_TABLE, ADPhotoSync::SETTINGS_TABLE] as $table) {
            try {
                $db->execute(sprintf('DROP TABLE IF EXISTS `%s`;', $table));
            } catch (\Exception $e) {
                error_log(sprintf('[ADPhotoSync] could not drop %s: %s', $table, $e->getMessage()));
            }
        }
    }

    /**
     * The hook for future schema changes. Nothing to migrate yet.
     */
    public function upgradePlugin()
    {
    }

    /**
     * Runs the synchronisation from eFront's own cron job, so no separate system
     * cron entry is needed.
     */
    public function onCronJobRun()
    {
        try {
            $settings = new ADPhotoSyncSettings(1);

            if (!$settings->isConfigured() || !$settings->isDueForRun()) {
                return;
            }

            $result = (new PhotoSynchronizer($settings))->run();

            error_log(sprintf('[ADPhotoSync] cron run: %s', $result->summary()));
        } catch (\Exception $e) {
            // A failing photo sync must never take the whole cron run down.
            error_log('[ADPhotoSync] cron run failed: ' . $e->getMessage());
        }
    }

    /**
     * Adds a tile to the administrator dashboard. Entries without an explicit
     * group land in the last group of the grid, where the other plugins sit.
     *
     * @param  string                           $list_name
     * @param  array<int, array<string, mixed>> $options
     * @return array<int, array<string, mixed>>|void
     */
    public function onLoadIconList($list_name, &$options)
    {
        if ('dashboard' !== $list_name || !$this->currentUserMayUse()) {
            return;
        }

        $options[] = [
            'text'   => ADPhotoSync::t('AD photo synchronisation'),
            'image'  => $this->plugin_url . '/assets/images/ad-photo-sync.svg',
            'class'  => 'medium',
            'href'   => UrlhelperController::url(['ctg' => $this->plugin->name]),
            'plugin' => true,
        ];

        return $options;
    }

    /**
     * Adds the plugin to the "Plugin Links" drawer in the side navigation.
     *
     * The host passes the plugin-links array as the first argument, so appending
     * here is what makes the entry appear.
     *
     * @param array<int, array<string, mixed>> $options
     */
    public function onNavbarList(&$options)
    {
        if (!$this->currentUserMayUse()) {
            return;
        }

        $options[] = [
            'text'   => $this->plugin->title,
            'href'   => UrlhelperController::url(['ctg' => $this->plugin->name]),
            'plugin' => true,
        ];
    }

    /**
     * Routes the plugin's admin page.
     *
     * @param  string $ctg
     * @return BaseController|null
     */
    public function onCtg($ctg)
    {
        if (ADPhotoSync::PLUGIN_NAME !== $ctg || !$this->currentUserMayUse()) {
            return null;
        }

        $this->refreshRegistration();

        BaseController::getSmartyInstance()
            ->assign('T_CTG', 'plugin')
            ->assign('T_PLUGIN_FILE', $this->plugin_dir . '/View/ADPhotoSyncSettings.tpl');

        $controller = new ADPhotoSyncSettingsController();
        $controller->plugin = $this->plugin;

        return $controller;
    }

    /**
     * Brings the registered title, description, version and author back in line
     * with plugin.ini.
     *
     * eFront copies those into the plugins table once, when the plugin is first
     * installed, and never looks at the file again. Replacing the files during an
     * update therefore leaves the plugin list showing the version and the
     * description of whatever was installed originally — and reinstalling to fix
     * that would drop this plugin's settings along with its tables.
     *
     * Runs when the administrator opens the plugin's own page, which is often
     * enough and costs one comparison. It writes only when something actually
     * differs.
     */
    private function refreshRegistration(): void
    {
        try {
            $iniPath = $this->plugin_dir . DIRECTORY_SEPARATOR . 'plugin.ini';

            if (!is_readable($iniPath)) {
                return;
            }

            $ini = @parse_ini_file($iniPath);

            if (!is_array($ini)) {
                return;
            }

            $changed = [];

            foreach (['title', 'description', 'version', 'author'] as $field) {
                if (!isset($ini[$field])) {
                    continue;
                }

                if ((string) $ini[$field] !== (string) ($this->plugin->{$field} ?? '')) {
                    $changed[$field] = (string) $ini[$field];
                }
            }

            if ([] === $changed) {
                return;
            }

            $this->plugin->setFields($changed)->save();

            // The row is cached like any other model row; without this the list
            // would go on showing the values we just replaced.
            $this->plugin->deleteCache();
        } catch (\Throwable $e) {
            // Cosmetic metadata is never worth failing a page load over.
            error_log('[ADPhotoSync] could not refresh the plugin registration: ' . $e->getMessage());
        }
    }

    /**
     * Administrators whose user type grants access to this plugin.
     */
    private function currentUserMayUse(): bool
    {
        $currentUser = User::getCurrentUser();

        if (!$currentUser->id || !$currentUser->isAdministator()) {
            return false;
        }

        $accessLevel = (new UserType($currentUser->user_types_ID))->getAccessLevel(ADPhotoSync::PLUGIN_NAME);

        return $accessLevel > UserType::USER_TYPE_LEVEL_NONE;
    }
}
