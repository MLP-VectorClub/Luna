<?php
// Regenerates the swatch file goldens (Illustrator JSON and Inkscape GPL) in tests/fixtures/winterchilla/swatches from Winterchilla's own
// CGUtils::getSwatchesAI() / getSwatchesInkscape() for real appearances of the prod_copy database (read only). The export time in the files is
// replaced with the placeholder {TIME}.
//   php scripts/generate-winterchilla-swatch-goldens.php <appearance id>...
if (($argv[1] ?? '') === '--child') {
  [, , $id, $kind] = $argv;
  require '/home/went/git/MLP-VectorClub/Winterchilla/config/init/minimal.php';
  $a = App\Models\Appearance::find((int) $id);
  $kind === 'json' ? App\CGUtils::getSwatchesAI($a) : App\CGUtils::getSwatchesInkscape($a);
  exit;
}
$dir = dirname(__DIR__).'/tests/fixtures/winterchilla/swatches';
@mkdir($dir, 0775, true);
foreach (array_slice($argv, 1) as $id) {
  foreach (['json', 'gpl'] as $kind) {
    $proc = proc_open([PHP_BINARY, __FILE__, '--child', $id, $kind], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['DB_NAME' => 'prod_copy'] + getenv());
    $out = stream_get_contents($pipes[1]);
    proc_close($proc);
    $out = $kind === 'json'
      ? preg_replace('/"Exported at":"[^"]+"/', '"Exported at":"{TIME}"', $out)
      : preg_replace('/# Exported at: .*/', '# Exported at: {TIME}', $out);
    file_put_contents("$dir/$id.$kind", $out);
  }
  echo "$id ok\n";
}
