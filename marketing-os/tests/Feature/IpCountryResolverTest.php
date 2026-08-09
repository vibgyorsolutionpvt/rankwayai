<?php

namespace Tests\Feature;

use App\Services\Billing\IpCountryResolver;
use App\Services\Billing\PlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IpCountryResolverTest extends TestCase
{
    public function test_uses_cloudflare_header_when_present(): void
    {
        $request = Request::create('/billing', 'GET', server: [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_CF_IPCOUNTRY' => 'DE',
        ]);
        $request->setLaravelSession($this->app['session']->driver());

        $resolver = new IpCountryResolver;
        $this->assertSame('DE', $resolver->countryCode($request));
        $this->assertSame(PlanCatalog::MARKET_GLOBAL, $resolver->marketFor($request));
    }

    public function test_looks_up_geojs_and_caches(): void
    {
        Cache::flush();
        Http::fake([
            'get.geojs.io/*' => Http::response(['country' => 'US', 'name' => 'United States', 'ip' => '1.1.1.1'], 200),
        ]);

        $request = Request::create('/billing', 'GET', server: [
            'REMOTE_ADDR' => '1.1.1.1',
        ]);
        $request->setLaravelSession($this->app['session']->driver());

        $resolver = new IpCountryResolver;
        $this->assertSame('US', $resolver->countryCode($request));
        $this->assertSame('US', Cache::get('geo:ip:1.1.1.1'));
        Http::assertSentCount(1);

        // Second call should hit cache / session — no more HTTP.
        $this->assertSame('US', $resolver->countryCode($request));
        Http::assertSentCount(1);
    }

    public function test_private_ip_defaults_to_india(): void
    {
        $request = Request::create('/billing', 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        $request->setLaravelSession($this->app['session']->driver());

        $resolver = new IpCountryResolver;
        $this->assertSame('IN', $resolver->countryCode($request));
        $this->assertSame(PlanCatalog::MARKET_IN, $resolver->marketFor($request));
    }

    public function test_failover_to_ip_api_when_geojs_fails(): void
    {
        Cache::flush();
        Http::fake([
            'get.geojs.io/*' => Http::response('down', 500),
            'ip-api.com/*' => Http::response(['status' => 'success', 'countryCode' => 'GB'], 200),
        ]);

        $request = Request::create('/billing', 'GET', server: [
            'REMOTE_ADDR' => '9.9.9.9',
        ]);
        $request->setLaravelSession($this->app['session']->driver());

        $this->assertSame('GB', (new IpCountryResolver)->countryCode($request));
    }
}
