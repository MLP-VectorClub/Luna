<?php
// Regenerates the preview SVG goldens in tests/fixtures/winterchilla from Winterchilla's own CGUtils::renderPreviewSVG (needs ../Winterchilla with composer install):
//   php scripts/generate-winterchilla-preview-goldens.php   (prints JSON, split into tests/fixtures/winterchilla/preview-<name>.svg/.hexes.json)
// Winterchilla's absolute path is hard coded below.
require '/home/went/git/MLP-VectorClub/Winterchilla/config/init/minimal.php';
use App\CGUtils;
use App\Models\Appearance;
use App\Models\Color;

$sets = [
  'none' => [],
  'one' => ['#112233'],
  'two' => ['#FFAA00', '#112233'],
  'three' => ['#FFAA00', '#445566', '#112233'],
  'four' => ['#FFEEDD', '#FFAA00', '#445566', '#112233'],
];
$out = [];
foreach ($sets as $name => $hexes) {
  $a = new class($hexes) extends Appearance {
    public array $hx;
    public function __construct($hx) { $this->hx = $hx; }
    public function getPreviewColors() { return array_map(function ($h) { $c = new Color(); $c->hex = $h; return $c; }, $this->hx); }
  };
  $a->id = 1;
  $hexes = CGUtils::colorsToHexes($a->getPreviewColors());
  $path = str_replace('#', CGUtils::hexesToFilename($hexes), CGUtils::PREVIEW_SVG_PATH);
  $existed = file_exists($path);
  if (!$existed) CGUtils::renderPreviewSVG($a, false);
  $out[$name] = ['hexes' => $hexes, 'svg' => file_get_contents($path), 'existed' => $existed];
  if (!$existed) unlink($path);
}
echo json_encode($out, JSON_PRETTY_PRINT);
