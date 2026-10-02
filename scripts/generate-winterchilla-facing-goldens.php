<?php
// Regenerates the facing SVG goldens in tests/fixtures/winterchilla from Winterchilla's own color mapping (Appearance::getColorMapping)
// and its cm_facing assets, the replacement loop is the one in CGUtils::renderCMFacingSVG(). Needs ../Winterchilla with composer install.
//   php scripts/generate-winterchilla-facing-goldens.php
require '/home/went/git/MLP-VectorClub/Winterchilla/config/init/minimal.php';
use App\DB;
use App\Models\Appearance;

$cases = [
  'full' => [
    ['cglabel' => 'Coat', 'clabel' => 'Outline', 'hex' => '#112233'],
    ['cglabel' => 'Coat', 'clabel' => 'Shadow Outline', 'hex' => '#223344'],
    ['cglabel' => 'Coat', 'clabel' => 'Fill', 'hex' => '#445566'],
    ['cglabel' => 'Coat', 'clabel' => 'Shadow Fill', 'hex' => '#556677'],
    ['cglabel' => 'Mane & Tail', 'clabel' => 'Outline', 'hex' => '#778899'],
    ['cglabel' => 'Mane & Tail', 'clabel' => 'Fill', 'hex' => '#8899AA'],
  ],
  'derived' => [
    ['cglabel' => 'Coat', 'clabel' => 'Outline', 'hex' => '#112233'],
    ['cglabel' => 'Coat', 'clabel' => 'Fill', 'hex' => '#445566'],
  ],
  'labels' => [
    ['cglabel' => 'Dress', 'clabel' => 'Main Outline', 'hex' => '#010203'],
    ['cglabel' => 'Costume', 'clabel' => 'Normal Fill 2/3', 'hex' => '#040506'],
    ['cglabel' => 'Coat (Alt)', 'clabel' => 'First Shadow Fill', 'hex' => '#070809'],
    ['cglabel' => 'Mane & Tail (Alt)', 'clabel' => 'Purple Main Outline', 'hex' => '#0A0B0C'],
    ['cglabel' => 'Mane & Tail (Alt)', 'clabel' => 'Fill 12', 'hex' => '#0D0E0F'],
    ['cglabel' => 'Iris', 'clabel' => 'Gradient Top', 'hex' => '#101112'],
  ],
  'empty' => [],
];
$out = [];
foreach ($cases as $name => $rows) {
  DB::$instance = new class($rows) { public function __construct(public array $rows) {} public function query(...$a) { return $this->rows; } };
  $a = new Appearance();
  $a->id = 1;
  $mapping = $a->getColorMapping(Appearance::DEFAULT_COLOR_MAPPING);
  foreach (['left', 'right'] as $facing) {
    $img = file_get_contents('/home/went/git/MLP-VectorClub/Winterchilla/public/img/cm_facing/'.$facing.'.svg');
    foreach (Appearance::DEFAULT_COLOR_MAPPING as $label => $defhex)
      $img = str_replace($label, $mapping[$label] ?? $defhex, $img);
    $out["$name-$facing"] = $img;
  }
  $out["$name-rows"] = $rows;
}
echo json_encode($out);
