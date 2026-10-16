<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A color can be drawn semi-transparent (`opacity`, 1 to 99 percent, NULL = fully opaque) and combined with what is under it in a
 * `blend_mode` (NULL = normal). Colors used to say so in their names, "Lens Fill (40% Opacity)": this moves it into the columns where the name
 * has the plain "(N% opacity)" form (also "op." and "Op."), and takes it out of the name unless that would leave a name that is too short
 * (the API wants 3 to 30 characters) or that the color group already has. Ranges ("80% to 25%") and 100% are left as they are. A color with an
 * opacity and no blend mode is blended normally.
 */
return new class extends Migration
{
    private const SUFFIX = '/\s*\(\s*~?(\d{1,2})\s*%\s*(?:opacity|op\.?)\s*\)\s*$|\s+(\d{1,2})\s*%\s*opacity\s*$/i';

    public function up(): void
    {
        Schema::table('colors', function (Blueprint $table) {
            if (!Schema::hasColumn('colors', 'opacity')) {
                $table->unsignedSmallInteger('opacity')->nullable();
            }
            if (!Schema::hasColumn('colors', 'blend_mode')) {
                $table->string('blend_mode', 16)->nullable();
            }
        });

        $colors = DB::table('colors')->whereNull('opacity')->get(['id', 'group_id', 'label']);
        $labels = $colors->groupBy('group_id')->map(fn($group) => $group->pluck('label')->all())->all();
        foreach ($colors as $color) {
            if (!preg_match(self::SUFFIX, $color->label, $match)) {
                continue;
            }
            $opacity = (int) ($match[1] !== '' ? $match[1] : $match[2]);
            if ($opacity < 1 || $opacity > 99) {
                continue;
            }
            $stripped = trim(preg_replace(self::SUFFIX, '', $color->label));
            $update = ['opacity' => $opacity];
            if (mb_strlen($stripped) >= 3 && !in_array($stripped, $labels[$color->group_id], true)) {
                $update['label'] = $stripped;
                $labels[$color->group_id][] = $stripped;
            }
            DB::table('colors')->where('id', $color->id)->update($update);
        }

        // The one appearance whose notes spell out how its colors blend: the goggle lenses of the Wonderbolt Trainee Uniform ("The fill uses 67%
        // normal blending and the highlight uses 50% overlay blending"). Everything else is normal when nothing says otherwise
        DB::table('colors')->where('id', 1266)->where('label', 'Lens Fill (See Notes)')->update(['opacity' => 67]);
        DB::table('colors')->where('id', 1267)->where('label', 'Lens Highlight (See Notes)')->update(['opacity' => 50, 'blend_mode' => 'overlay']);
    }

    public function down(): void
    {
        // Put the percentage back into the names where it fits (names are 30 characters at most)
        foreach (DB::table('colors')->whereNotNull('opacity')->get(['id', 'label', 'opacity']) as $color) {
            $suffix = " ({$color->opacity}% opacity)";
            if (!preg_match('/\d\s*%/', $color->label) && mb_strlen($color->label.$suffix) <= 30) {
                DB::table('colors')->where('id', $color->id)->update(['label' => $color->label.$suffix]);
            }
        }
        Schema::table('colors', function (Blueprint $table) {
            $table->dropColumn(['opacity', 'blend_mode']);
        });
    }
};
