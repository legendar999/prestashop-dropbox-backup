<?php
/**
 * akvabackup — Akva Backup (Dropbox)
 *
 * Single-page BO controller (Package F). Legacy ModuleAdminController (house lesson:
 * no modern Symfony BO stack for infrastructure modules — container-compile crashes).
 * Renders configure.tpl with five sections (Status / Dropbox connection / Settings /
 * Encryption key / Restore) and handles every POST action. Consumes the pinned sibling
 * classes only (TokenStore/Client/RunManager/KeyManager) — never re-implements them.
 *
 * All config keys are GLOBAL (Configuration::get/updateGlobalValue). Secrets are encrypted
 * at rest via PhpEncryption and NEVER echoed or logged.
 *
 * @author  Akva Modules
 * @license AFL-3.0
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Akvabackup\Crypto\KeyManager;
use Akvabackup\Dropbox\Client;
use Akvabackup\Dropbox\DropboxException;
use Akvabackup\Dropbox\TokenStore;
use Akvabackup\Job\RunManager;

require_once _PS_MODULE_DIR_ . 'akvabackup/src/autoload.php';

final class AdminAkvabackupController extends ModuleAdminController
{
    /** Component toggles (v1.1.0): what the backup includes. Missing key = ON. */
    private const COMPONENT_KEYS = [
        'AKVABACKUP_INC_DB',
        'AKVABACKUP_INC_IMG',
        'AKVABACKUP_INC_MODULES',
        'AKVABACKUP_INC_THEMES',
        'AKVABACKUP_INC_DOWNLOAD',
        'AKVABACKUP_INC_MAILS',
    ];

    /** Set only right after a successful one-time key reveal; passed to the template once. */
    private ?string $revealedHex = null;

    public function __construct()
    {
        $this->bootstrap = true;
        $this->display = 'view';

        parent::__construct();

        if (!$this->module) {
            $this->module = Module::getInstanceByName('akvabackup');
        }
    }

    public function initPageHeaderToolbar()
    {
        parent::initPageHeaderToolbar();
        $this->page_header_toolbar_title = $this->t('Akva Backup (Dropbox)');
    }

    public function initContent()
    {
        $this->display = 'view';
        $this->consumeFlash();
        parent::initContent();
    }

    /**
     * Post/Redirect/Get: every POST action ends in a redirect back to the plain GET page,
     * with its outcome stashed in the employee cookie — an F5 must NEVER re-submit the
     * action (a refresh must never re-fire the last action).
     * Exceptions: key reveal (one-time value must render in the same response) and key
     * download (streams a file).
     */
    private function redirectBack(string $type, string $message): void
    {
        $this->context->cookie->akvabackup_msg = (string) json_encode([$type, $message], JSON_UNESCAPED_UNICODE);
        $this->context->cookie->write();
        Tools::redirectAdmin(self::$currentIndex . '&token=' . $this->token);
    }

    private function consumeFlash(): void
    {
        $raw = (string) $this->context->cookie->akvabackup_msg;
        if ($raw === '') {
            return;
        }
        unset($this->context->cookie->akvabackup_msg);
        $this->context->cookie->write();
        $data = json_decode($raw, true);
        if (!is_array($data) || count($data) !== 2) {
            return;
        }
        if ($data[0] === 'conf') {
            $this->confirmations[] = (string) $data[1];
        } else {
            $this->errors[] = (string) $data[1];
        }
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        // ?v= cache-bust: Cloudflare caches /modules/* statics for a year regardless of PS
        // flushes; Media::getMediaPath strips the query for its file check but keeps it in
        // the URL, so every version bump lands a fresh edge URL.
        $v = '?v=' . urlencode((string) $this->module->version);
        $this->addCSS($this->module->getPathUri() . 'views/css/admin.css' . $v);
        $this->addJS($this->module->getPathUri() . 'views/js/admin.js' . $v);
    }

    // --------------------------------------------------------------- POST actions

    public function postProcess()
    {
        // Streamed key download must run before any page output.
        if (Tools::isSubmit('submitAkvabackupKeyDownload')) {
            $this->downloadKey();
            // downloadKey() exits; unreachable below.
        }

        if (Tools::isSubmit('submitAkvabackupSettings')) {
            $this->saveSettings();
        } elseif (Tools::isSubmit('submitAkvabackupApp')) {
            $this->saveApp();
        } elseif (Tools::isSubmit('submitAkvabackupConnect')) {
            $this->connect();
        } elseif (Tools::isSubmit('submitAkvabackupDisconnect')) {
            $this->disconnect();
        } elseif (Tools::isSubmit('submitAkvabackupRunNow')) {
            $this->runNow();
        } elseif (Tools::isSubmit('submitAkvabackupReveal')) {
            $this->revealKey();
        }

        return parent::postProcess();
    }

    private function saveSettings(): void
    {
        Configuration::updateGlobalValue('AKVABACKUP_ENABLED', (int) Tools::getValue('AKVABACKUP_ENABLED') === 1 ? '1' : '0');

        $hour = (int) Tools::getValue('AKVABACKUP_HOUR');
        Configuration::updateGlobalValue('AKVABACKUP_HOUR', (string) max(0, min(23, $hour)));

        Configuration::updateGlobalValue('AKVABACKUP_RET_DAILY', (string) max(0, (int) Tools::getValue('AKVABACKUP_RET_DAILY')));
        Configuration::updateGlobalValue('AKVABACKUP_RET_WEEKLY', (string) max(0, (int) Tools::getValue('AKVABACKUP_RET_WEEKLY')));
        Configuration::updateGlobalValue('AKVABACKUP_RET_MONTHLY', (string) max(0, (int) Tools::getValue('AKVABACKUP_RET_MONTHLY')));

        Configuration::updateGlobalValue('AKVABACKUP_EXCLUDES', $this->normalizeLines((string) Tools::getValue('AKVABACKUP_EXCLUDES')));
        Configuration::updateGlobalValue('AKVABACKUP_DB_EXCLUDES', $this->normalizeLines((string) Tools::getValue('AKVABACKUP_DB_EXCLUDES')));

        foreach (self::COMPONENT_KEYS as $key) {
            Configuration::updateGlobalValue($key, (int) Tools::getValue($key) === 1 ? '1' : '0');
        }

        $email = trim((string) Tools::getValue('AKVABACKUP_ALERT_EMAIL'));
        if ($email !== '' && !Validate::isEmail($email)) {
            $this->errors[] = $this->t('Invalid alert email address.');
        } else {
            Configuration::updateGlobalValue('AKVABACKUP_ALERT_EMAIL', $email);
        }

        // CF 524 guard: budget is capped at 90 s by the contract; floor at 10 s to stay useful.
        $budget = (int) Tools::getValue('AKVABACKUP_TICK_BUDGET');
        Configuration::updateGlobalValue('AKVABACKUP_TICK_BUDGET', (string) max(10, min(90, $budget)));

        if ($this->errors) {
            $this->redirectBack('err', (string) reset($this->errors));
        }
        $this->redirectBack('conf', $this->t('Settings saved.'));
    }

    private function saveApp(): void
    {
        $appKey = trim((string) Tools::getValue('AKVABACKUP_APP_KEY'));
        Configuration::updateGlobalValue('AKVABACKUP_APP_KEY', $appKey);

        // Only overwrite the stored secret when a new one is actually typed (blank = keep existing).
        $secret = (string) Tools::getValue('AKVABACKUP_APP_SECRET');
        if ($secret !== '') {
            Configuration::updateGlobalValue('AKVABACKUP_APP_SECRET_ENC', $this->encrypt($secret));
        }

        $this->redirectBack('conf', $this->t('Dropbox app saved.'));
    }

    private function connect(): void
    {
        $appKey = trim((string) Configuration::getGlobalValue('AKVABACKUP_APP_KEY'));
        $appSecret = $this->decrypt((string) Configuration::getGlobalValue('AKVABACKUP_APP_SECRET_ENC'));
        $code = trim((string) Tools::getValue('AKVABACKUP_AUTH_CODE'));

        if ($appKey === '' || $appSecret === '') {
            $this->redirectBack('err', $this->t('Save the Dropbox app key and app secret first.'));
        }
        if ($code === '') {
            $this->redirectBack('err', $this->t('Enter the authorization code.'));
        }

        try {
            (new TokenStore())->connect($appKey, $appSecret, $code);
            Configuration::deleteByName('AKVABACKUP_SPACE_CACHE');
            $this->redirectBack('conf', $this->t('Dropbox connection established.'));
        } catch (DropboxException $e) {
            $this->redirectBack('err', $this->t('Connection failed:') . ' ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->redirectBack('err', $this->t('Connection failed.'));
        }
    }

    private function disconnect(): void
    {
        try {
            (new TokenStore())->disconnect();
            Configuration::deleteByName('AKVABACKUP_SPACE_CACHE');
            $this->redirectBack('conf', $this->t('Dropbox connection removed.'));
        } catch (\Throwable $e) {
            $this->redirectBack('err', $this->t('Could not disconnect.'));
        }
    }

    private function runNow(): void
    {
        try {
            $runManager = new RunManager(new Client(new TokenStore()));
            $idRun = $runManager->startManual();
            $this->redirectBack('conf', sprintf(
                $this->t('Manual run created (job #%d). Scheduled cron ticks will carry it to completion.'),
                $idRun
            ));
        } catch (\Throwable $e) {
            $this->redirectBack('err', $this->t('Could not create the run:') . ' ' . $e->getMessage());
        }
    }

    private function revealKey(): void
    {
        if ((int) Configuration::getGlobalValue('AKVABACKUP_KEY_SHOWN') !== 0) {
            $this->errors[] = $this->t('The key was already revealed once. Only the file download remains available.');

            return;
        }

        try {
            $this->revealedHex = KeyManager::keyHex();
            Configuration::updateGlobalValue('AKVABACKUP_KEY_SHOWN', '1');
        } catch (\Throwable $e) {
            $this->errors[] = $this->t('Could not read the encryption key.');
        }
    }

    private function downloadKey(): void
    {
        try {
            $hex = KeyManager::keyHex();
        } catch (\Throwable $e) {
            $this->errors[] = $this->t('Could not read the encryption key.');

            return;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="akvabackup-' . date('Ymd-His') . '.key"');
        header('Content-Length: ' . strlen($hex));
        header('Cache-Control: no-store');
        echo $hex;
        exit;
    }

    // --------------------------------------------------------------- render

    public function renderView()
    {
        $connected = $this->isConnected();
        $space = $this->spaceUsage($connected);

        $tpl = $this->context->smarty->createTemplate(
            _PS_MODULE_DIR_ . 'akvabackup/views/templates/admin/configure.tpl',
            $this->context->smarty
        );

        $tpl->assign([
            'akvabackup_form_url' => $this->context->link->getAdminLink('AdminAkvabackup'),
            // Section 1 — Status
            'connected' => $connected,
            'space' => $space,
            'runs' => $this->recentRuns(),
            'cron_url' => $this->cronUrl(),
            'last_ok' => (string) Configuration::getGlobalValue('AKVABACKUP_LAST_OK'),
            'enabled' => (int) Configuration::getGlobalValue('AKVABACKUP_ENABLED') === 1,
            // Section 2 — Dropbox connection
            'app_key' => (string) Configuration::getGlobalValue('AKVABACKUP_APP_KEY'),
            'app_secret_set' => (string) Configuration::getGlobalValue('AKVABACKUP_APP_SECRET_ENC') !== '',
            'authorize_url' => $this->authorizeUrl(),
            // Section 3 — Settings
            'hour' => (int) Configuration::getGlobalValue('AKVABACKUP_HOUR'),
            'ret_daily' => (int) Configuration::getGlobalValue('AKVABACKUP_RET_DAILY'),
            'ret_weekly' => (int) Configuration::getGlobalValue('AKVABACKUP_RET_WEEKLY'),
            'ret_monthly' => (int) Configuration::getGlobalValue('AKVABACKUP_RET_MONTHLY'),
            'excludes' => (string) Configuration::getGlobalValue('AKVABACKUP_EXCLUDES'),
            'db_excludes' => (string) Configuration::getGlobalValue('AKVABACKUP_DB_EXCLUDES'),
            'components' => $this->componentToggles(),
            'alert_email' => (string) Configuration::getGlobalValue('AKVABACKUP_ALERT_EMAIL'),
            'tick_budget' => (int) Configuration::getGlobalValue('AKVABACKUP_TICK_BUDGET'),
            // Section 4 — Encryption key
            'key_shown' => (int) Configuration::getGlobalValue('AKVABACKUP_KEY_SHOWN') === 1,
            'revealed_hex' => $this->revealedHex,
        ]);

        return $tpl->fetch();
    }

    // --------------------------------------------------------------- helpers

    /**
     * Component toggles for the "Kaj se shranjuje" section: key, current state, label, help.
     *
     * @return array<int,array{key:string,on:bool,label:string,help:string}>
     */
    private function componentToggles(): array
    {
        $meta = [
            'AKVABACKUP_INC_DB' => [
                $this->t('Database'),
                $this->t('The full database (all multistore shops share one database): products, orders, customers, settings. Without it a store restore is impossible — disable only if the database is backed up elsewhere.'),
            ],
            'AKVABACKUP_INC_IMG' => [
                $this->t('Images (img/)'),
                $this->t('Product, category and store images. Usually the largest part of the backup.'),
            ],
            'AKVABACKUP_INC_MODULES' => [
                $this->t('Modules (modules/)'),
                $this->t('All installed modules with their customizations.'),
            ],
            'AKVABACKUP_INC_THEMES' => [
                $this->t('Themes (themes/)'),
                $this->t('The store theme with all its customizations.'),
            ],
            'AKVABACKUP_INC_DOWNLOAD' => [
                $this->t('Product files (download/, upload/)'),
                $this->t('Downloadable virtual products and files uploaded by customers.'),
            ],
            'AKVABACKUP_INC_MAILS' => [
                $this->t('Email templates (mails/)'),
                $this->t('Customized system email templates.'),
            ],
        ];

        $out = [];
        foreach ($meta as $key => [$label, $help]) {
            $raw = Configuration::getGlobalValue($key);
            $out[] = [
                'key' => $key,
                'on' => $raw === false ? true : (bool) (int) $raw,
                'label' => $label,
                'help' => $help,
            ];
        }

        return $out;
    }

    private function isConnected(): bool
    {
        try {
            return (new TokenStore())->isConnected();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function authorizeUrl(): string
    {
        $appKey = trim((string) Configuration::getGlobalValue('AKVABACKUP_APP_KEY'));
        if ($appKey === '') {
            return '';
        }

        try {
            return TokenStore::authorizeUrl($appKey);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Dropbox space usage, cached 10 minutes in a config key (survives across BO page loads),
     * failure tolerated: on API error fall back to any stale cache, else report unavailable.
     *
     * @return array{ok:bool,used:int,allocated:int,used_h:string,allocated_h:string,pct:int}
     */
    private function spaceUsage(bool $connected): array
    {
        $unavailable = ['ok' => false, 'used' => 0, 'allocated' => 0, 'used_h' => '', 'allocated_h' => '', 'pct' => 0];
        if (!$connected) {
            return $unavailable;
        }

        $cached = null;
        $raw = (string) Configuration::getGlobalValue('AKVABACKUP_SPACE_CACHE');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['ts'])) {
                $cached = $decoded;
                if ((time() - (int) $decoded['ts']) < 600) {
                    return $this->formatSpace((int) $decoded['used'], (int) $decoded['allocated']);
                }
            }
        }

        try {
            $usage = (new Client(new TokenStore()))->spaceUsage();
            $used = (int) ($usage['used'] ?? 0);
            $allocated = (int) ($usage['allocated'] ?? 0);
            Configuration::updateGlobalValue(
                'AKVABACKUP_SPACE_CACHE',
                (string) json_encode(['used' => $used, 'allocated' => $allocated, 'ts' => time()])
            );

            return $this->formatSpace($used, $allocated);
        } catch (\Throwable $e) {
            if (is_array($cached)) {
                return $this->formatSpace((int) $cached['used'], (int) $cached['allocated']);
            }

            return $unavailable;
        }
    }

    /**
     * @return array{ok:bool,used:int,allocated:int,used_h:string,allocated_h:string,pct:int}
     */
    private function formatSpace(int $used, int $allocated): array
    {
        $pct = $allocated > 0 ? (int) round($used / $allocated * 100) : 0;

        return [
            'ok' => true,
            'used' => $used,
            'allocated' => $allocated,
            'used_h' => $this->humanBytes($used),
            'allocated_h' => $allocated > 0 ? $this->humanBytes($allocated) : '',
            'pct' => max(0, min(100, $pct)),
        ];
    }

    /**
     * Last 15 runs for the status table.
     *
     * @return array<int,array<string,mixed>>
     */
    private function recentRuns(): array
    {
        try {
            $rows = Db::getInstance()->executeS(
                'SELECT id_run, type, state, stats, error, date_add, finished_at
                 FROM `' . _DB_PREFIX_ . 'akvabackup_run`
                 ORDER BY id_run DESC LIMIT 15'
            );
        } catch (\Throwable $e) {
            return [];
        }

        $runs = [];
        foreach ((array) $rows as $r) {
            $stats = json_decode((string) ($r['stats'] ?? ''), true);
            $stats = is_array($stats) ? $stats : [];

            $size = 0;
            if (isset($stats['db_bytes']) || isset($stats['files_bytes'])) {
                $size = (int) ($stats['db_bytes'] ?? 0) + (int) ($stats['files_bytes'] ?? 0);
            } elseif (isset($stats['uploaded_bytes'])) {
                $size = (int) $stats['uploaded_bytes'];
            }

            $error = trim((string) ($r['error'] ?? ''));

            $runs[] = [
                'id' => (int) $r['id_run'],
                'type' => (string) $r['type'],
                'state' => (string) $r['state'],
                'size_h' => $size > 0 ? $this->humanBytes($size) : '-',
                'duration_h' => $this->humanDuration((int) ($stats['duration_s'] ?? 0)),
                'error' => $error !== '' ? Tools::substr($error, 0, 160) : '',
                'date' => (string) ($r['date_add'] ?? ''),
                'finished' => (string) ($r['finished_at'] ?? ''),
            ];
        }

        return $runs;
    }

    private function cronUrl(): string
    {
        $token = (string) Configuration::getGlobalValue('AKVABACKUP_CRON_TOKEN');
        $base = Tools::getShopDomainSsl(true) . __PS_BASE_URI__;

        return $base . 'index.php?fc=module&module=akvabackup&controller=cron&token=' . $token;
    }

    private function normalizeLines(string $raw): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    private function humanBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            ++$i;
        }

        return sprintf($i === 0 ? '%d %s' : '%.1f %s', $bytes, $units[$i]);
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '-';
        }
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;
        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            $minutes %= 60;

            return $hours . 'h ' . $minutes . 'm';
        }
        if ($minutes > 0) {
            return $minutes . 'm ' . $rest . 's';
        }

        return $rest . 's';
    }

    private function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        return (string) (new PhpEncryption(_NEW_COOKIE_KEY_))->encrypt($plain);
    }

    private function decrypt(string $cipher): string
    {
        if ($cipher === '') {
            return '';
        }

        try {
            return (string) (new PhpEncryption(_NEW_COOKIE_KEY_))->decrypt($cipher);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Slovenian-first source strings routed through the module translator. */
    private function t(string $string): string
    {
        return $this->module->l($string, 'adminakvabackupcontroller');
    }
}
