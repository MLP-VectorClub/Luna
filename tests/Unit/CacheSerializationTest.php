<?php

namespace Tests\Unit;

use hisorange\BrowserDetect\Result;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CacheSerializationTest extends TestCase
{
    /** Packages cache objects of their own, cache.serializable_classes must not turn them into incomplete objects */
    public function testSettingsCollectionSurvivesASerializingCacheStore(): void
    {
        config(['cache.stores.file.path' => sys_get_temp_dir().'/luna-cache-test-'.uniqid()]);
        $store = Cache::store('file');

        $store->put('app_settings.default', collect(['site_name' => 'Luna']));
        $cached = $store->get('app_settings.default');

        $this->assertInstanceOf(Collection::class, $cached);
        $this->assertSame('Luna', $cached->get('site_name'));
    }

    public function testBrowserDetectResultSurvivesASerializingCacheStore(): void
    {
        config(['cache.stores.file.path' => sys_get_temp_dir().'/luna-cache-test-'.uniqid()]);
        $store = Cache::store('file');

        $store->put('browser-detect', new Result([]));

        $this->assertInstanceOf(Result::class, $store->get('browser-detect'));
    }
}
