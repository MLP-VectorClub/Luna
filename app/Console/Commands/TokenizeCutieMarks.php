<?php

namespace App\Console\Commands;

use App\Models\CutieMark;
use App\Utils\SvgHelper;
use Illuminate\Console\Command;

/**
 * Cutie marks stored before color tokens existed (or imported by `fs:migrate`) keep their colors as they are. This
 * gives the ones whose appearance has a Cutie Mark color group their tokens, so they follow the guide from now on.
 * The served files are not changed.
 */
class TokenizeCutieMarks extends Command
{
    protected $signature = 'cutiemarks:tokenize {--dry-run : Only report what would be done}';

    protected $description = 'Link the colors of stored cutie marks to the Cutie Mark color group of their appearance';

    public function handle(): int
    {
        $tokenized = $skipped = 0;
        foreach (CutieMark::with('appearance')->orderBy('id')->cursor() as $cutie_mark) {
            $media = $cutie_mark->vectorFile();
            if ($media === null || $media->getCustomProperty(CutieMark::TOKENIZED_PROPERTY) !== null) {
                continue;
            }
            $colors = CutieMark::guideColors($cutie_mark->appearance_id);
            if ($colors === []) {
                $skipped++;
                continue;
            }

            if (!$this->option('dry-run')) {
                $media->setCustomProperty(CutieMark::TOKENIZED_PROPERTY, SvgHelper::tokenize(file_get_contents($media->getPath()), $colors));
                $media->save();
            }
            $tokenized++;
        }

        $this->info(($this->option('dry-run') ? 'Would tokenize' : 'Tokenized')." $tokenized cutie mark(s), $skipped skipped (no Cutie Mark color group)");

        return self::SUCCESS;
    }
}
