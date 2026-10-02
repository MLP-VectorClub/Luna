<?php
// Regenerates the palette image goldens in tests/fixtures/winterchilla from Winterchilla's own CGUtils::renderAppearancePNG() for real appearances of
// its development database (read only, apart from deleting the cached palette.png of the chosen appearances so that it gets rendered again).
// The header of the image contains the time of the export and the URL of the appearance, both are recorded next to the image.
//   php scripts/generate-winterchilla-palette-goldens.php <appearance id>...   (ids with and without a sprite in ../Winterchilla/fs/sprites)
if (($argv[1] ?? '') === '--child') {
  [, , $id, $uri_t] = $argv;
  require '/home/went/git/MLP-VectorClub/Winterchilla/config/init/minimal.php';
  $a = App\Models\Appearance::find((int) $id);
  $rel = "/cg/v/{$a->id}p.png";
  $_SERVER['REQUEST_URI'] = "$rel?t=$uri_t";
  // renderAppearancePNG() first compares the path alone, then the path with ?t=
  $_SERVER['REQUEST_URI'] = $rel;
  $now = time();
  $meta = [
    'generated_at' => App\Time::format($now, App\Time::FORMAT_FULL),
    'source_url' => rtrim(ABSPATH, '/').$a->toURL(),
    'name' => $a->label,
  ];
  $all = App\CGUtils::getColorsForEach($a->color_groups);
  $meta['groups'] = array_map(fn($cg) => ['label' => $cg->label, 'colors' => array_map(fn($c) => ['label' => $c->label, 'hex' => $c->hex], $all[$cg->id] ?? [])], $a->color_groups);
  fwrite(STDERR, json_encode($meta)."\n");
  $_SERVER['REQUEST_URI'] = "$rel?t=$now";
  App\CGUtils::renderAppearancePNG('/cg', $a);
  exit;
}

$dir = dirname(__DIR__).'/tests/fixtures/winterchilla/palette';
@mkdir($dir, 0775, true);
foreach (array_slice($argv, 1) as $id) {
  $cache = "/home/went/git/MLP-VectorClub/Winterchilla/fs/cg_render/appearance/$id/palette.png";
  for ($attempt = 0; $attempt < 5; $attempt++) {
    if (file_exists($cache)) unlink($cache);
    $sprite = "/home/went/git/MLP-VectorClub/Winterchilla/fs/sprites/$id.png";
    // Older Winterchilla versions looked for the sprite at "<id>.png<id>.png" (getSpriteFilePath() already ends in "<id>.png") and never drew it,
    // a copy at that doubled path makes those render the sprite as well
    $doubled = $sprite.$id.'.png';
    if (file_exists($sprite) && str_contains(file_get_contents('/home/went/git/MLP-VectorClub/Winterchilla/app/CGUtils.php'), 'getSpriteFilePath()."{$Appearance->id}.png"')) copy($sprite, $doubled);
    $proc = proc_open([PHP_BINARY, __FILE__, '--child', $id, '0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['DB_NAME' => 'prod_copy'] + getenv());
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($proc);
    if (file_exists($doubled)) unlink($doubled);
    if (file_exists($cache) && !str_contains($stdout, 'Moved Temporarily')) break;
  }
  if (!file_exists($cache)) { fwrite(STDERR, "No image for $id\n$stdout\n$stderr\n"); continue; }
  $meta = json_decode(trim(explode("\n", trim($stderr))[count(explode("\n", trim($stderr))) - 1]), true);
  $meta['id'] = (int) $id;
  copy($cache, "$dir/$id.png");
  $sprite = "/home/went/git/MLP-VectorClub/Winterchilla/fs/sprites/$id.png";
  $meta['sprite'] = file_exists($sprite);
  if (file_exists($sprite)) copy($sprite, "$dir/$id-sprite.png");
  file_put_contents("$dir/$id.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  echo "$id ok\n";
}
