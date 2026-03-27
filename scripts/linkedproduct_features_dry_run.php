<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$configPath = $root . '/config/config.inc.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "Nie znaleziono config.inc.php. Ustaw poprawne położenie instalacji PrestaShop.\n");
    exit(1);
}

require_once $configPath;
require_once __DIR__ . '/../src/Service/LinkedProductGroupService.php';

use PoLinkedProductFeatures\Service\LinkedProductGroupService;

$options = getopt('', ['prefix:', 'features:']);
$prefix = isset($options['prefix']) ? strtoupper(trim((string) $options['prefix'])) : '';
$featuresCsv = isset($options['features']) ? (string) $options['features'] : '';
$featureIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $featuresCsv)))));

if ($prefix === '' || !$featureIds) {
    fwrite(STDERR, "Użycie: php scripts/linkedproduct_features_dry_run.php --prefix=SM-PL1 --features=1,2\n");
    exit(1);
}

$module = Module::getInstanceByName('po_linkedproduct_features');
if (!$module) {
    fwrite(STDERR, "Nie znaleziono modułu po_linkedproduct_features.\n");
    exit(1);
}

$service = new LinkedProductGroupService($module, Context::getContext());
$result = $service->previewMatch($prefix, $featureIds, [], 10);

fwrite(STDOUT, "Liczba produktów: " . $result['count'] . "\n");
foreach ($result['rows'] as $row) {
    fwrite(STDOUT, sprintf("#%d %s [%s]\n", $row['id_product'], $row['name'], $row['reference']));
}
