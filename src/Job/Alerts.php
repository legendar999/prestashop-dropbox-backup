<?php

declare(strict_types=1);

namespace Akvabackup\Job;

use Akvabackup\Support\Config;

/**
 * Failure notification. ALWAYS logs to PrestaShopLogger (severity 3). Additionally e-mails the
 * configured recipient (fallback PS_SHOP_EMAIL) IF the module ships a 'backup_alert' mail template;
 * a missing template degrades gracefully to log-only (never a fatal). Secrets are never included.
 */
final class Alerts
{
    public static function failure(int $idRun, string $error): void
    {
        \PrestaShopLogger::addLog('[akvabackup] run #' . $idRun . ' failed: ' . $error, 3);

        try {
            $email = Config::alertEmail();
            if ($email === '') {
                $email = (string) \Configuration::get('PS_SHOP_EMAIL');
            }
            if ($email === '' || !\Validate::isEmail($email)) {
                return;
            }

            $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
            $iso = strtolower(\Language::getIsoById($idLang) ?: 'en');
            $tplDir = _PS_MODULE_DIR_ . 'akvabackup/mails/';

            // Only sl + en templates ship; any other default language falls back to en so a
            // failure alert is NEVER silently suppressed by the shop's language choice.
            if (!is_file($tplDir . $iso . '/backup_alert.html') && !is_file($tplDir . $iso . '/backup_alert.txt')) {
                $en = (int) \Language::getIdByIso('en');
                if ($en > 0 && (is_file($tplDir . 'en/backup_alert.html') || is_file($tplDir . 'en/backup_alert.txt'))) {
                    $idLang = $en;
                } else {
                    return; // nothing usable shipped -> log-only
                }
            }

            $shop = (string) \Configuration::get('PS_SHOP_NAME');
            // Subject follows the language of the template actually used ($idLang after fallback);
            // 'si' is a nonstandard-but-real Slovenian iso variant seen in the wild.
            $subjIso = strtolower(\Language::getIsoById($idLang) ?: 'en');
            $subjects = ['en' => 'Backup failure', 'sl' => 'Napaka varnostne kopije', 'si' => 'Napaka varnostne kopije'];
            $subject = $subjects[$subjIso] ?? $subjects['en'];
            \Mail::Send(
                $idLang,
                'backup_alert',
                '[' . $shop . '] ' . $subject . ' #' . $idRun,
                [
                    '{run_id}' => (string) $idRun,
                    '{error}' => $error,
                    '{shop_name}' => $shop,
                    '{date}' => date('Y-m-d H:i:s'),
                ],
                $email,
                null,
                null,
                null,
                null,
                null,
                $tplDir
            );
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[akvabackup] alert mail failed: ' . $e->getMessage(), 2);
        }
    }
}
