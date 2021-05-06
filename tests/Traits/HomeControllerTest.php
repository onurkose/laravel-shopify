<?php

namespace Osiset\ShopifyApp\Test\Traits;

use Illuminate\Auth\AuthManager;
use Osiset\ShopifyApp\Test\TestCase;
use Osiset\ShopifyApp\Util;

class HomeControllerTest extends TestCase
{
    /**
     * @var AuthManager
     */
    protected $auth;

    public function setUp(): void
    {
        parent::setUp();

        $this->auth = $this->app->make(AuthManager::class);
    }

    public function testHomeRouteWithAppBridge(): void
    {
        $shop = factory($this->model)->create();
        $this->auth->login($shop);

        $this->call('get', '/', [], ['itp' => true])
            ->assertOk()
            ->assertSee("apiKey: '".Util::getShopifyConfig('api_key')."'", false)
            ->assertSee("shopOrigin: '{$shop->name}'", false);
    }

    public function testHomeRouteWithNoAppBridge(): void
    {
        $shop = factory($this->model)->create();
        $this->auth->login($shop);

        $this->app['config']->set('shopify-app.appbridge_enabled', false);

        $this->call('get', '/', [], ['itp' => true])
            ->assertOk()
            ->assertDontSee('@shopify');
    }
}
