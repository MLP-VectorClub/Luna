<?php

/**
 * Compares Winterchilla's api.json (the contract) with Luna's generated OpenAPI document and lists the operations
 * Luna lacks. `x-internal` operations are Winterchilla UI details and are skipped.
 *
 *   php artisan l5-swagger:generate
 *   php scripts/diff-contract.php [path/to/winterchilla/public/dist/api.json] [--by-tag]
 *
 * Exits with 1 while operations are missing, so it can be used as a progress meter.
 */

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$by_tag = in_array('--by-tag', $argv, true);
$contract_path = $args[0] ?? __DIR__.'/../../Winterchilla/public/dist/api.json';
$luna_path = __DIR__.'/../storage/api-docs/api-docs.json';

foreach ([$contract_path, $luna_path] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Missing $file\n");
        exit(2);
    }
}

$normalize = fn(string $path) => preg_replace('~\{[^}]+}~', '{}', rtrim($path, '/') ?: '/');
$operations = function (array $doc, bool $skip_internal) use ($normalize) {
    $result = [];
    foreach ($doc['paths'] ?? [] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }
            if ($skip_internal && !empty($operation['x-internal'])) {
                continue;
            }
            $result[strtoupper($method).' '.$normalize($path)] = [
                'label' => strtoupper($method)." $path",
                'tag' => $operation['tags'][0] ?? '',
            ];
        }
    }

    return $result;
};

$contract = $operations(json_decode(file_get_contents($contract_path), true), true);
$luna = $operations(json_decode(file_get_contents($luna_path), true), false);
$missing = array_diff_key($contract, $luna);

printf("Contract operations: %d, implemented: %d, missing: %d\n", count($contract), count($contract) - count($missing), count($missing));
if ($by_tag) {
    $counts = [];
    foreach ($missing as $op) {
        $counts[$op['tag']] = ($counts[$op['tag']] ?? 0) + 1;
    }
    arsort($counts);
    foreach ($counts as $tag => $count) {
        echo "  $tag: $count\n";
    }
} else {
    foreach ($missing as $op) {
        echo "  {$op['label']}\n";
    }
}

exit($missing === [] ? 0 : 1);
