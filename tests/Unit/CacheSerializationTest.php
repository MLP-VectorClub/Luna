<?php

namespace Tests\Unit;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CacheSerializationTest extends TestCase
{
    /** The settings package caches `pluck()`, and cache.serializable_classes must let it come back as a Collection */
    public function testSettingsCollectionSurvivesASerializingCacheStore(): void
    {
        config(['cache.stores.file.path' => sys_get_temp_dir().'/luna-cache-test-'.uniqid()]);
        $store = Cache::store('file');

        $store->put('app_settings.default', collect(['site_name' => 'Luna']));
        $cached = $store->get('app_settings.default');

        $this->assertInstanceOf(Collection::class, $cached);
        $this->assertSame('Luna', $cached->get('site_name'));
    }
}
