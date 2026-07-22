<?php
/**
 * akvabackup v1.1.0 upgrade:
 *  - BO tab renamed "Akva Backup" -> "Akva Backup Dropbox" (all languages)
 *  - component toggles seeded ON (AKVABACKUP_INC_*) so existing installs keep full backups
 *
 * A deployment script may apply the same steps inline (PS only fires upgrade files on
 * BO Module Manager loads); both paths are idempotent.
 *
 * @author  Akva Modules
 * @license AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0($module)
{
    $idTab = (int) Tab::getIdFromClassName('AdminAkvabackup');
    if ($idTab) {
        $tab = new Tab($idTab);
        foreach (Language::getLanguages(false) as $l) {
            $tab->name[(int) $l['id_lang']] = 'Akva Backup Dropbox';
        }
        $tab->save();
    }

    $toggles = [
        'AKVABACKUP_INC_DB',
        'AKVABACKUP_INC_IMG',
        'AKVABACKUP_INC_MODULES',
        'AKVABACKUP_INC_THEMES',
        'AKVABACKUP_INC_DOWNLOAD',
        'AKVABACKUP_INC_MAILS',
    ];
    foreach ($toggles as $key) {
        if (Configuration::getGlobalValue($key) === false) {
            Configuration::updateGlobalValue($key, '1');
        }
    }

    return true;
}
