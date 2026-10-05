<?php

namespace Tests\Feature;

use App\Enums\GuideName;
use App\Enums\Role;
use App\Models\Appearance;
use App\Models\MajorChange;
use App\Models\PinnedAppearance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppearanceLastMajorChangeTest extends TestCase
{
    use RefreshDatabase;

    public function testListItemsCarryTheTimeOfTheirLastMajorChange(): void
    {
        $user = User::factory()->create(['role' => Role::Staff]);
        $changed = Appearance::create(['label' => 'Changed', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => null, 'order' => 1]);
        $untouched = Appearance::create(['label' => 'Untouched', 'guide' => GuideName::FriendshipIsMagic, 'notes_src' => null, 'order' => 2]);
        MajorChange::create(['appearance_id' => $changed->id, 'reason' => 'Older', 'user_id' => $user->id])->forceFill(['created_at' => '2020-01-01 10:00:00'])->save();
        MajorChange::create(['appearance_id' => $changed->id, 'reason' => 'Newer', 'user_id' => $user->id])->forceFill(['created_at' => '2021-02-03 04:05:06'])->save();
        PinnedAppearance::create(['guide' => 'pony', 'appearance_id' => $changed->id]);
        PinnedAppearance::create(['guide' => 'pony', 'appearance_id' => $untouched->id]);

        $items = collect($this->getJson('/appearances/pinned?guide=pony')->assertOk()->json())->keyBy('label');

        $this->assertStringStartsWith('2021-02-03T04:05:06', $items['Changed']['lastMajorChange']);
        $this->assertNull($items['Untouched']['lastMajorChange']);
    }
}
