<?php
/**
 * ICANN MoSAPI Registrar Monitor for FOSSBilling
 *
 * Written in 2026 by Taras Kondratyuk (https://namingo.org)
 * Based on example modules and inspired by existing modules of FOSSBilling
 * (https://www.fossbilling.org) and BoxBilling.
 *
 * @license Apache-2.0
 * @see https://www.apache.org/licenses/LICENSE-2.0
 */

namespace Box\Mod\Mosapimonitor;

use FOSSBilling\InformationException;

class Service
{
    protected $di;
    private const VERSION = 'v2';

    public function setDi(\Pimple\Container|null $di): void
    {
        $this->di = $di;
    }

    public function install(): bool
    {
        $db = $this->di['db'];
        $db->exec('SELECT NOW()');

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    public function update(array $manifest): bool
    {
        return true;
    }

    public function getConfig(): array
    {
        $saved = [];
        try {
            $saved = (array) $this->di()['mod_config']('mosapimonitor');
        } catch (\Throwable $e) {
            $saved = [];
        }

        $defaults = [
            'base_url'     => '',
            'username'     => '',
            'password'     => '',
            'timeout'      => 10,
            'cache_ttl'    => 290,
            'show_domains' => false,
            'source_ip'    => '',
        ];

        $cfg = array_merge($defaults, $saved);

        $cfg['base_url']     = trim((string)$cfg['base_url']);
        $cfg['username']     = trim((string)$cfg['username']);
        $cfg['password']     = (string)$cfg['password'];
        $cfg['timeout']      = (int)$cfg['timeout'];
        $cfg['cache_ttl']    = (int)$cfg['cache_ttl'];
        $cfg['show_domains'] = !empty($cfg['show_domains']) && ((string)$cfg['show_domains'] !== '0');
        $cfg['source_ip']    = trim((string)$cfg['source_ip']);

        return $cfg;
    }

    public function saveConfig(array $payload): void
    {
        $this->di()['service_extension']->setConfig('mosapimonitor', $payload);
    }

    public function isConfigured(array $cfg): bool
    {
        return $cfg['base_url'] !== '' && $cfg['username'] !== '' && $cfg['password'] !== '';
    }

    public function getData(array $cfg, bool $forceRefresh = false): array
    {
        $cacheKey = $this->cacheKey($cfg);

        if (!$forceRefresh) {
            $cached = $this->cacheGetNative($cacheKey, (int)$cfg['cache_ttl']);
            if ($cached !== null) {
                $cached['meta']['cache'] = 'HIT (native cache)';
                return $cached;
            }

            // fallback to file cache
            $cached = $this->cacheGet($cacheKey, (int)$cfg['cache_ttl']);
            if ($cached !== null) {
                $cached['meta']['cache'] = 'HIT (file cache)';
                return $cached;
            }
        }

        $base = rtrim($cfg['base_url'], '/');
        $stateUrl   = $base . '/' . self::VERSION . '/monitoring/state';
        $metricaUrl = $base . '/' . self::VERSION . '/metrica/domainList/latest';

        $cookieFile = $this->tempFile('mosapimonitor_cookie_', '.txt');

        try {
            $this->login($cfg, $cookieFile);

            $state   = $this->fetchJson($stateUrl, $cfg, $cookieFile);
            $metrica = $this->fetchJson($metricaUrl, $cfg, $cookieFile);

            $this->logout($cfg, $cookieFile);

            $payload = [
                'state'   => $state,
                'metrica' => $metrica,
                'meta'    => [
                    'cache'      => 'MISS (fresh)',
                    'fetched_at' => date('Y-m-d H:i:s'),
                ],
            ];

            // Try native cache first, then file cache fallback
            $this->cacheSetNative($cacheKey, $payload, (int)$cfg['cache_ttl']);
            $this->cacheSet($cacheKey, $payload);

            return $payload;
        } finally {
            if (is_file($cookieFile)) {
                @unlink($cookieFile);
            }
        }
    }

    private function login(array $cfg, string $cookieFile): void
    {
        $url = rtrim($cfg['base_url'], '/') . '/login';

        $ch = curl_init($url);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $cfg['username'] . ':' . $cfg['password'],
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_TIMEOUT        => (int)$cfg['timeout'],
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ];

        if (!empty($cfg['source_ip'])) {
            $opts[CURLOPT_INTERFACE] = $cfg['source_ip'];
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = (string)curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw new \Exception('Login request failed: ' . $err);
        }
        if ($status !== 200) {
            throw new \Exception('Login failed (HTTP ' . $status . '): ' . $this->safeSnippet((string)$response));
        }
    }

    private function fetchJson(string $url, array $cfg, string $cookieFile): array
    {
        $ch = curl_init($url);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_TIMEOUT        => (int)$cfg['timeout'],
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Accept-Encoding: gzip',
            ],
        ];

        if (!empty($cfg['source_ip'])) {
            $opts[CURLOPT_INTERFACE] = $cfg['source_ip'];
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = (string)curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw new \Exception('Fetch failed: ' . $err);
        }

        if ($status !== 200) {
            throw new \Exception(
                'Failed to fetch data (HTTP ' . $status . ') from ' . $url . ': ' . $this->safeSnippet((string)$response)
            );
        }

        $json = json_decode((string)$response, true);
        if (!is_array($json)) {
            throw new \Exception('Invalid JSON received from: ' . $url);
        }

        return $json;
    }

    private function logout(array $cfg, string $cookieFile): void
    {
        $url = rtrim($cfg['base_url'], '/') . '/logout';

        $ch = curl_init($url);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_TIMEOUT        => (int)$cfg['timeout'],
        ];

        if (!empty($cfg['source_ip'])) {
            $opts[CURLOPT_INTERFACE] = $cfg['source_ip'];
        }

        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        curl_close($ch);
    }

    private function cacheKey(array $cfg): string
    {
        return 'mosapimonitor_' . hash('sha256', $cfg['base_url'] . '|' . self::VERSION . '|' . $cfg['username']);
    }

    private function storageDir(): string
    {
        $base = sys_get_temp_dir();
        try {
            $di = $this->di();
            if (isset($di['path_data']) && is_string($di['path_data']) && $di['path_data'] !== '') {
                $base = $di['path_data'];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $dir = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mosapimonitor';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }

    private function cacheFilePath(string $cacheKey): string
    {
        return $this->storageDir() . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    }

    private function cacheGet(string $cacheKey, int $ttlSeconds): ?array
    {
        $path = $this->cacheFilePath($cacheKey);
        if (!is_file($path)) {
            return null;
        }

        $mtime = @filemtime($path);
        if (!$mtime || (time() - $mtime) > $ttlSeconds) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['state']) || empty($data['metrica'])) {
            return null;
        }

        return $data;
    }

    private function cacheSet(string $cacheKey, array $payload): void
    {
        $path = $this->cacheFilePath($cacheKey);
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function tempFile(string $prefix, string $suffix): string
    {
        $dir = $this->storageDir();

        $tmp = tempnam($dir, $prefix);
        if ($tmp === false) {
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8)) . $suffix;
        }

        $target = $tmp . $suffix;
        @rename($tmp, $target);

        return $target;
    }

    private function safeSnippet(string $s): string
    {
        $s = trim($s);
        if (strlen($s) > 1000) {
            return substr($s, 0, 1000) . '...';
        }
        return $s;
    }

    private function di(): \Pimple\Container
    {
        return $this->di;
    }

    private function cacheGetNative(string $key, int $ttlSeconds): ?array
    {
        try {
            if (!isset($this->di['cache'])) {
                return null;
            }

            $cacheKey = 'mosapimonitor.' . $key;

            $val = $this->di['cache']->get($cacheKey);

            if (!$val) {
                return null;
            }

            if (is_string($val)) {
                $val = json_decode($val, true);
            }

            return is_array($val) ? $val : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function cacheSetNative(string $key, array $payload, int $ttlSeconds): void
    {
        try {
            if (!isset($this->di['cache'])) {
                return;
            }

            $cacheKey = 'mosapimonitor.' . $key;

            $this->di['cache']->set($cacheKey, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $ttlSeconds);
        } catch (\Throwable $e) {
            // ignore
        }
    }

}