<?php
/**
 * Akva Backup (akvabackup) - daily full-store backup (DB + files) to Dropbox,
 * resumable HTTP cron ticks, client-side encryption.
 *
 * @author    Akva Modules
 * @copyright 2026 Akva Modules
 * @license   AFL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

use Akvabackup\Crypto\KeyManager;
use Akvabackup\Support\Staging;

class Akvabackup extends Module
{
    public function __construct()
    {
        $this->name = 'akvabackup';
        $this->tab = 'administration';
        $this->version = '1.2.0';
        $this->author = 'Akva Modules';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Akva Backup (Dropbox)');
        $this->description = $this->l('Daily full store backup (database + files) to Dropbox, using resumable HTTP cron ticks and client-side encryption before upload.');
        $this->confirmUninstall = $this->l('Uninstall the Akva Backup module? Settings and the BO tab will be removed. Backup history (tables) is KEPT.');
    }

    public function install()
    {
        return parent::install()
            && $this->installTables()
            && $this->installConfig()
            && $this->installTab();
    }

    /**
     * Tables are KEPT on uninstall (backup history is precious). Only the BO tab,
     * config keys and the staging area are removed. To drop the tables, run manual SQL
     * (DROP TABLE <prefix>akvabackup_item, <prefix>akvabackup_run).
     */
    public function uninstall()
    {
        $this->uninstallTab();
        $this->uninstallConfig();
        Staging::purgeAll();

        return parent::uninstall();
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminAkvabackup'));
    }

    // ---- install helpers ---------------------------------------------------

    private function installTables(): bool
    {
        $p = _DB_PREFIX_;
        $engine = _MYSQL_ENGINE_;

        $sql = [];

        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . $p . 'akvabackup_run` (
            `id_run` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `type` VARCHAR(8) NOT NULL DEFAULT \'daily\',
            `state` VARCHAR(12) NOT NULL DEFAULT \'pending\',
            `cursor` MEDIUMTEXT NULL,
            `stats` MEDIUMTEXT NULL,
            `error` TEXT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            `finished_at` DATETIME NULL,
            PRIMARY KEY (`id_run`),
            KEY `state` (`state`)
        ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4';

        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . $p . 'akvabackup_item` (
            `id_item` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_run` INT UNSIGNED NOT NULL,
            `kind` VARCHAR(8) NOT NULL,
            `seq` INT UNSIGNED NOT NULL DEFAULT 0,
            `local_path` VARCHAR(512) NOT NULL DEFAULT \'\',
            `remote_path` VARCHAR(512) NOT NULL,
            `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `content_hash` CHAR(64) NULL,
            `state` VARCHAR(12) NOT NULL DEFAULT \'pending\',
            `session_id` VARCHAR(191) NULL,
            `upload_offset` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_item`),
            KEY `run_state` (`id_run`, `state`)
        ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4';

        $db = Db::getInstance();
        foreach ($sql as $q) {
            if (!$db->execute($q)) {
                return false;
            }
        }

        return true;
    }

    private function installConfig(): bool
    {
        // Client-side encryption key: generated once, only if not already present.
        KeyManager::generate();

        $defaults = [
            'AKVABACKUP_ENABLED' => '0',
            'AKVABACKUP_APP_KEY' => '',
            'AKVABACKUP_APP_SECRET_ENC' => '',
            'AKVABACKUP_REFRESH_TOKEN_ENC' => '',
            'AKVABACKUP_ACCESS_TOKEN' => '',
            'AKVABACKUP_ACCESS_EXPIRES' => '0',
            'AKVABACKUP_KEY_SHOWN' => '0',
            'AKVABACKUP_CRON_TOKEN' => bin2hex(random_bytes(16)),
            'AKVABACKUP_HOUR' => '2',
            'AKVABACKUP_RET_DAILY' => '14',
            'AKVABACKUP_RET_WEEKLY' => '8',
            'AKVABACKUP_RET_MONTHLY' => '6',
            'AKVABACKUP_EXCLUDES' => $this->defaultExcludes(),
            'AKVABACKUP_DB_EXCLUDES' => "connections\nconnections_page\nconnections_source\nguest\nstatssearch",
            'AKVABACKUP_INC_DB' => '1',
            'AKVABACKUP_INC_IMG' => '1',
            'AKVABACKUP_INC_MODULES' => '1',
            'AKVABACKUP_INC_THEMES' => '1',
            'AKVABACKUP_INC_DOWNLOAD' => '1',
            'AKVABACKUP_INC_MAILS' => '1',
            'AKVABACKUP_ALERT_EMAIL' => '',
            'AKVABACKUP_TICK_BUDGET' => '60',
            'AKVABACKUP_LAST_OK' => '',
        ];
        foreach ($defaults as $k => $v) {
            Configuration::updateGlobalValue($k, $v);
        }

        return true;
    }

    /**
     * Default filesystem excludes (globs relative to PS root). The admin folder name is
     * resolved from _PS_ADMIN_DIR_ (defined in BO/install context); if it is missing we
     * fall back to scanning the root for a directory that contains a backups/ child.
     */
    private function defaultExcludes(): string
    {
        $admin = $this->resolveAdminDir();
        $lines = [
            'var/cache/*',
            'var/akvabackup/*',
            'img/tmp/*',
            $admin . '/backups/*',
            $admin . '/autoupgrade/*',
            $admin . '/import/*',
            $admin . '/export/*',
        ];

        return implode("\n", $lines);
    }

    private function resolveAdminDir(): string
    {
        if (defined('_PS_ADMIN_DIR_')) {
            return basename(rtrim((string) _PS_ADMIN_DIR_, '/\\'));
        }

        // Fallback: a PS admin folder is the root child that owns a backups/ directory.
        $root = rtrim(_PS_ROOT_DIR_, '/\\');
        foreach ((array) @scandir($root) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($root . '/' . $entry . '/backups')) {
                return $entry;
            }
        }

        return 'admin';
    }

    // ---- BO tab ------------------------------------------------------------

    private function installTab(): bool
    {
        if (Tab::getIdFromClassName('AdminAkvabackup')) {
            return true;
        }
        $tab = new Tab();
        $tab->class_name = 'AdminAkvabackup';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminAdvancedParameters');
        $tab->icon = 'backup';
        $tab->active = 1;
        $names = [];
        foreach (Language::getLanguages(false) as $l) {
            $names[(int) $l['id_lang']] = 'Akva Backup Dropbox';
        }
        $tab->name = $names;

        return (bool) $tab->add();
    }

    private function uninstallTab(): bool
    {
        $id = (int) Tab::getIdFromClassName('AdminAkvabackup');
        if ($id) {
            $tab = new Tab($id);

            return (bool) $tab->delete();
        }

        return true;
    }

    private function uninstallConfig(): void
    {
        $keys = [
            'AKVABACKUP_ENABLED',
            'AKVABACKUP_APP_KEY',
            'AKVABACKUP_APP_SECRET_ENC',
            'AKVABACKUP_REFRESH_TOKEN_ENC',
            'AKVABACKUP_ACCESS_TOKEN',
            'AKVABACKUP_ACCESS_EXPIRES',
            'AKVABACKUP_ENC_KEY_ENC',
            'AKVABACKUP_KEY_SHOWN',
            'AKVABACKUP_CRON_TOKEN',
            'AKVABACKUP_HOUR',
            'AKVABACKUP_RET_DAILY',
            'AKVABACKUP_RET_WEEKLY',
            'AKVABACKUP_RET_MONTHLY',
            'AKVABACKUP_EXCLUDES',
            'AKVABACKUP_DB_EXCLUDES',
            'AKVABACKUP_INC_DB',
            'AKVABACKUP_INC_IMG',
            'AKVABACKUP_INC_MODULES',
            'AKVABACKUP_INC_THEMES',
            'AKVABACKUP_INC_DOWNLOAD',
            'AKVABACKUP_INC_MAILS',
            'AKVABACKUP_ALERT_EMAIL',
            'AKVABACKUP_TICK_BUDGET',
            'AKVABACKUP_LAST_OK',
            'AKVABACKUP_SPACE_CACHE',
        ];
        foreach ($keys as $k) {
            Configuration::deleteByName($k);
        }
    }
}
