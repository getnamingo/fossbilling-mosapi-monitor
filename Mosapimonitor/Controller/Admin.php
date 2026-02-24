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

namespace Box\Mod\Mosapimonitor\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
{
    protected $di;

    public function setDi(\Pimple\Container|null $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                [
                    'location' => 'extensions',
                    'label' => __trans('MoSAPI Monitor'),
                    'index' => 2000,
                    'uri' => $this->di['url']->adminLink('mosapimonitor'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/mosapimonitor', 'get_index', [], static::class);
        $app->post('/mosapimonitor', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->checkAccess();

        $service = $this->di['mod_service']('mosapimonitor');
        $cfg     = $service->getConfig();

        $forceRefresh = (bool)$this->di['request']->get('refresh', false);

        $data = null;
        $error = null;

        if ($service->isConfigured($cfg)) {
            try {
                $data = $service->getData($cfg, $forceRefresh);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $app->render('mod_mosapimonitor_index', [
            'cfg'        => $cfg,
            'configured' => $service->isConfigured($cfg),
            'data'       => $data,
            'error'      => $error,
        ]);
    }

    public function post_index(\Box_App $app): void
    {
        $this->checkAccess();

        $service = $this->di['mod_service']('mosapimonitor');

        $payload = [
            'base_url'     => trim((string)$this->di['request']->get('base_url', '')),
            'username'     => trim((string)$this->di['request']->get('username', '')),
            'password'     => (string)$this->di['request']->get('password', ''),
            'timeout'      => trim((string)$this->di['request']->get('timeout', '10')),
            'cache_ttl'    => trim((string)$this->di['request']->get('cache_ttl', '290')),
            'show_domains' => $this->di['request']->get('show_domains', null) ? '1' : '0',
            'source_ip'    => trim((string)$this->di['request']->get('source_ip', '')),
        ];

        $service->saveConfig($payload);

        $this->di['tools']->redirect('/mosapimonitor');
    }

    private function checkAccess(): void
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('mosapimonitor', 'access');
    }

}