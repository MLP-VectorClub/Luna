<?php
// Regenerates the sprite tracing goldens in tests/fixtures/winterchilla from Winterchilla's own CGUtils::getSpriteImageMap() (the traced line data)
// and the SVG assembly of CGUtils::renderSpriteSVG(). The fixture sprite is created here. Needs ../Winterchilla with composer install, files created
// under its fs/ are removed again.
//   php scripts/generate-winterchilla-sprite-goldens.php
require '/home/went/git/MLP-VectorClub/Winterchilla/config/init/minimal.php';
use App\CGUtils;

$dir = dirname(__DIR__).'/tests/fixtures/winterchilla';
$img = imagecreatetruecolor(7, 4);
imagealphablending($img, false);
imagesavealpha($img, true);
imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
$red = imagecolorallocatealpha($img, 255, 0, 0, 0);
$half = imagecolorallocatealpha($img, 255, 0, 0, 64);
$blue = imagecolorallocatealpha($img, 0, 17, 255, 0);
$green = imagecolorallocatealpha($img, 0, 255, 0, 0);
foreach ([[0, 0], [1, 0], [2, 0], [4, 0], [0, 1], [1, 1]] as [$x, $y]) imagesetpixel($img, $x, $y, $red);
foreach ([[2, 1], [3, 1], [6, 1]] as [$x, $y]) imagesetpixel($img, $x, $y, $half);
foreach ([[3, 2], [4, 2], [5, 2], [6, 2], [0, 3]] as [$x, $y]) imagesetpixel($img, $x, $y, $blue);
foreach ([[1, 3], [2, 3]] as [$x, $y]) imagesetpixel($img, $x, $y, $green);
imagepng($img, "$dir/sprite.png");

$id = 900999001;
$png_path = CGUtils::getSpriteFilePath($id, false);
copy("$dir/sprite.png", $png_path);
$map = CGUtils::getSpriteImageMap($id, false);

$strokes = [];
foreach ($map['linedata'] as $line) {
  $hex = $map['colors'][$line['colorid']];
  if ($line['opacity'] !== 0) {
    $opacity = (float)number_format((127 - $line['opacity']) / 127, 2, '.', '');
    $hex .= "' opacity='{$opacity}";
  }
  $strokes[$hex][] = "M{$line['x']} {$line['y']} l{$line['width']} 0Z";
}
$w = $map['width']; $h = $map['height'];
$svg = <<<XML
			<svg version='1.1' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 $w $h' enable-background='new 0 0 $w $h' xml:space='preserve'>
			XML;
foreach ($strokes as $hex => $defs) {
  $d = '';
  foreach ($defs as $def) $d .= "$def ";
  $svg .= "<path stroke='$hex' d='".rtrim($d)."'/>";
}
$svg .= '</svg>';
file_put_contents("$dir/sprite.svg", $svg);
file_put_contents("$dir/sprite-map.json", json_encode($map));

unlink($png_path);
$cache = FSPATH."cg_render/appearance/$id";
foreach (glob("$cache/*") as $f) unlink($f);
@rmdir($cache);
echo "ok\n";
