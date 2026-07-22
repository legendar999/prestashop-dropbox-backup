<?php

declare(strict_types=1);

namespace Akvabackup\Dropbox;

/**
 * Minimal Dropbox v2 cURL client (no SDK, no composer dependencies).
 * - RPC calls: api.dropboxapi.com, arguments in the JSON body.
 * - Content calls: content.dropboxapi.com, arguments in the ASCII-safe Dropbox-API-Arg
 *   header, body is application/octet-stream.
 * Resilience: on 401 force a token refresh once and retry; on 429 wait once for
 * min(Retry-After, MAX_SLEEP) and retry; incorrect_offset on append returns the
 * correct offset instead of throwing.
 * Tokens are never logged.
 */
final class Client
{
    private const API_HOST = 'https://api.dropboxapi.com';
    private const CONTENT_HOST = 'https://content.dropboxapi.com';
    private const RPC_TIMEOUT = 30;
    private const CONTENT_TIMEOUT = 90;
    private const MAX_SLEEP = 15;

    public function __construct(private readonly TokenStore $tokens)
    {
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public function rpc(string $endpoint, array $args): array
    {
        $body = empty($args) ? 'null' : $this->encodeArgs($args);
        $r = $this->authedRequest(
            self::API_HOST . $endpoint,
            ['Content-Type: application/json'],
            $body,
            self::RPC_TIMEOUT
        );

        return $this->decodeOrThrow($r);
    }

    /**
     * @return array<string,mixed>
     */
    public function uploadSmall(string $localPath, string $remotePath): array
    {
        $bytes = @file_get_contents($localPath);
        if ($bytes === false) {
            throw new DropboxException('Unable to read file for upload: ' . $localPath);
        }

        $args = [
            'path' => $remotePath,
            'mode' => 'overwrite',
            'autorename' => false,
            'mute' => true,
        ];
        $r = $this->contentRequest('/2/files/upload', $args, $bytes, self::CONTENT_TIMEOUT);

        return $this->decodeOrThrow($r);
    }

    public function sessionStart(): string
    {
        $r = $this->contentRequest('/2/files/upload_session/start', ['close' => false], '', self::CONTENT_TIMEOUT);
        $data = $this->decodeOrThrow($r);
        if (empty($data['session_id'])) {
            throw new DropboxException('Dropbox upload session did not return a session_id.', $r['code'], $r['body']);
        }

        return (string) $data['session_id'];
    }

    /**
     * Appends $bytes at $offset. On incorrect_offset returns the correct server-side
     * offset (instead of throwing), otherwise returns $offset + strlen($bytes).
     */
    public function sessionAppend(string $sessionId, string $bytes, int $offset): int
    {
        $args = [
            'cursor' => ['session_id' => $sessionId, 'offset' => $offset],
            'close' => false,
        ];
        $r = $this->contentRequest('/2/files/upload_session/append_v2', $args, $bytes, self::CONTENT_TIMEOUT);
        $code = $r['code'];

        if ($code >= 200 && $code < 300) {
            return $offset + strlen($bytes);
        }

        $correct = $this->extractCorrectOffset($r['body']);
        if ($correct !== null) {
            return $correct;
        }

        throw new DropboxException('Dropbox append error HTTP ' . $code, $code, $r['body']);
    }

    /**
     * @return array<string,mixed> metadata of the uploaded file (including content_hash)
     */
    public function sessionFinish(string $sessionId, int $offset, string $remotePath): array
    {
        $args = [
            'cursor' => ['session_id' => $sessionId, 'offset' => $offset],
            'commit' => [
                'path' => $remotePath,
                'mode' => 'overwrite',
                'autorename' => false,
                'mute' => true,
            ],
        ];
        $r = $this->contentRequest('/2/files/upload_session/finish', $args, '', self::CONTENT_TIMEOUT);

        return $this->decodeOrThrow($r);
    }

    /**
     * @return array<int,array<string,mixed>> all entries (has_more is followed automatically)
     */
    public function listFolder(string $path): array
    {
        $entries = [];
        $r = $this->rpc('/2/files/list_folder', [
            'path' => $path,
            'recursive' => false,
            'limit' => 2000,
        ]);
        if (isset($r['entries']) && is_array($r['entries'])) {
            $entries = array_merge($entries, $r['entries']);
        }

        while (!empty($r['has_more']) && isset($r['cursor'])) {
            $r = $this->rpc('/2/files/list_folder/continue', ['cursor' => (string) $r['cursor']]);
            if (isset($r['entries']) && is_array($r['entries'])) {
                $entries = array_merge($entries, $r['entries']);
            }
        }

        return $entries;
    }

    /**
     * @return array<string,mixed>
     */
    public function delete(string $path): array
    {
        return $this->rpc('/2/files/delete_v2', ['path' => $path]);
    }

    /**
     * @return array{used:int,allocated:int}
     */
    public function spaceUsage(): array
    {
        $r = $this->rpc('/2/users/get_space_usage', []);

        return [
            'used' => isset($r['used']) ? (int) $r['used'] : 0,
            'allocated' => isset($r['allocation']['allocated']) ? (int) $r['allocation']['allocated'] : 0,
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array{code:int,body:string,headers:array<string,string>}
     */
    private function contentRequest(string $endpoint, array $args, string $body, int $timeout): array
    {
        return $this->authedRequest(
            self::CONTENT_HOST . $endpoint,
            [
                'Dropbox-API-Arg: ' . $this->encodeArgs($args),
                'Content-Type: application/octet-stream',
            ],
            $body,
            $timeout
        );
    }

    /**
     * Adds Bearer authorization and handles 401 (a single forced refresh) and
     * 429 (a single Retry-After sleep).
     *
     * @param array<int,string> $extraHeaders
     *
     * @return array{code:int,body:string,headers:array<string,string>}
     */
    private function authedRequest(string $url, array $extraHeaders, string $body, int $timeout): array
    {
        $retried401 = false;
        $retried429 = false;

        while (true) {
            $token = $this->tokens->getAccessToken();
            $headers = array_merge(['Authorization: Bearer ' . $token], $extraHeaders);
            $r = $this->execute($url, $headers, $body, $timeout);
            $code = $r['code'];

            if ($code === 401 && !$retried401) {
                $retried401 = true;
                // Force a refresh: invalidate the cached access token.
                \Configuration::updateGlobalValue('AKVABACKUP_ACCESS_EXPIRES', '0');
                continue;
            }

            if ($code === 429 && !$retried429) {
                $retried429 = true;
                $retryAfter = isset($r['headers']['retry-after']) ? (int) $r['headers']['retry-after'] : 1;
                if ($retryAfter < 1) {
                    $retryAfter = 1;
                }
                sleep(min($retryAfter, self::MAX_SLEEP));
                continue;
            }

            return $r;
        }
    }

    /**
     * @param array<int,string> $headers
     *
     * @return array{code:int,body:string,headers:array<string,string>}
     */
    private function execute(string $url, array $headers, string $body, int $timeout): array
    {
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new DropboxException('Dropbox HTTP error: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $code, 'body' => (string) $resp, 'headers' => $respHeaders];
    }

    /**
     * @param array{code:int,body:string,headers:array<string,string>} $r
     *
     * @return array<string,mixed>
     */
    private function decodeOrThrow(array $r): array
    {
        $code = $r['code'];
        $body = $r['body'];

        if ($code < 200 || $code >= 300) {
            throw new DropboxException('Dropbox API HTTP ' . $code, $code, $body);
        }
        if ($body === '') {
            return [];
        }
        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }

    private function extractCorrectOffset(string $body): ?int
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !is_array($data['error'] ?? null)) {
            return null;
        }
        $err = $data['error'];
        // append_v2 nests the offset error one level down: error.lookup_failed.incorrect_offset
        // (UploadSessionAppendError -> lookup_failed -> UploadSessionLookupError).
        if (($err['.tag'] ?? '') === 'lookup_failed' && is_array($err['lookup_failed'] ?? null)) {
            $err = $err['lookup_failed'];
        }
        if (($err['.tag'] ?? '') === 'incorrect_offset' && isset($err['correct_offset'])) {
            return (int) $err['correct_offset'];
        }

        return null;
    }

    /**
     * ASCII-safe argument encoding (non-ASCII -> \uXXXX), slashes stay
     * readable. Usable both for the JSON body and the Dropbox-API-Arg header.
     *
     * @param array<string,mixed> $args
     */
    private function encodeArgs(array $args): string
    {
        return (string) json_encode($args, JSON_UNESCAPED_SLASHES);
    }
}
