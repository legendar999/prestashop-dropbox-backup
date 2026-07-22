<?php
/**
 * akvabackup — cron entrypoint.
 * GET /index.php?fc=module&module=akvabackup&controller=cron&token=<32hex>
 *
 * Legacy ModuleFrontController by design (proven on PS9.1): JSON-only, no theme bootstrap, constant-time token check. Advances the resumable backup
 * state machine ONE bounded tick per call (default 60 s work budget, hard cap 90 s) so it always
 * finishes well under Cloudflare's ~100 s edge timeout. Fired every few minutes in the backup window
 * by any external scheduler that can call a URL (cron service, Cloudflare Worker, monitor).
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'akvabackup/src/autoload.php';

use Akvabackup\Dropbox\Client;
use Akvabackup\Dropbox\TokenStore;
use Akvabackup\Job\Lock;
use Akvabackup\Job\RunManager;
use Akvabackup\Support\Config;

class AkvabackupCronModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ssl = false;

    public function init(): void
    {
        // No parent::init(): skips Smarty/theme bootstrap; this endpoint is plain-text/JSON only.
        $this->respond($this->execute());
    }

    /** @return array{code:int, body:array<string,mixed>} */
    private function execute(): array
    {
        try {
            // Gate order: https first, then a constant-time token check, uniformly for
            // every caller. A token-less request never learns whether the module is enabled.
            if (!Tools::usingSecureMode() && (bool) Configuration::get('PS_SSL_ENABLED')) {
                return ['code' => 403, 'body' => ['error' => 'https required']];
            }

            $token = (string) Tools::getValue('token');
            $expected = Config::cronToken();
            if ($expected === '' || !hash_equals($expected, $token)) {
                return ['code' => 403, 'body' => ['error' => 'forbidden']];
            }

            // Disabled short-circuits AFTER the token gate — but a pending/active MANUAL run is still
            // carried forward even when the master switch is off (owner can back up on demand).
            if (!Config::enabled() && !RunManager::hasActiveManualRun()) {
                return ['code' => 200, 'body' => ['skipped' => true, 'reason' => 'disabled']];
            }

            // Single-flight advisory lock (fail-open): a cron overrun or double trigger skips instead of
            // running two ticks against the same run.
            $lock = new Lock();
            if (!$lock->acquire('tick')) {
                return ['code' => 200, 'body' => ['skipped' => true, 'reason' => 'locked']];
            }
            try {
                $manager = new RunManager(new Client(new TokenStore()));

                return ['code' => 200, 'body' => $manager->tick()];
            } finally {
                $lock->release();
            }
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[akvabackup] cron fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 3);

            return ['code' => 500, 'body' => ['error' => 'internal']];
        }
    }

    /** @param array{code:int, body:array<string,mixed>} $result */
    private function respond(array $result): void
    {
        if (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code($result['code']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
