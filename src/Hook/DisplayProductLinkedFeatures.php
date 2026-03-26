<?php
declare(strict_types=1);

namespace PoLinkedProductFeatures\Hook;

use Db;
use Tools;
use Configuration;

class DisplayProductLinkedFeatures extends AbstractDisplayHook
{
    private const TEMPLATE_FILE = 'display_product_linked.tpl';

    protected function getTemplate(): string
    {
        return self::TEMPLATE_FILE;
    }

    protected function assignTemplateVariables(array $params): bool
    {
        $productId = (int) Tools::getValue('id_product');

        if (!$productId) {
            return false;
        }

        if (!$this->assignFeatureLinkedPositions($productId)) {
            return false;
        }

        return true;
    }

    private function assignFeatureLinkedPositions(int $productId): bool
    {
        $db = Db::getInstance();
        $assignment = $db->getRow('
            SELECT id_profile, family_key
            FROM ' . _DB_PREFIX_ . 'po_link_product_family
            WHERE id_product=' . (int) $productId
        );

        if (!$assignment) {
            return false;
        }

        $profile = $db->getRow('
            SELECT id_profile, options_csv, hidden_options_csv, show_muted
            FROM ' . _DB_PREFIX_ . 'po_link_profile
            WHERE id_profile=' . (int) $assignment['id_profile'] . ' AND active=1'
        );

        if (!$profile) {
            return false;
        }

        $optionIds = $this->parseCsvIds((string) ($profile['options_csv'] ?? ''));
        $hiddenOptionIds = $this->parseCsvIds((string) ($profile['hidden_options_csv'] ?? ''));
        $showMuted = (int) ($profile['show_muted'] ?? 1) === 1;
        if (!$optionIds) {
            return false;
        }

        $indexRows = $db->executeS(
            'SELECT i.id_product, i.options_json
             FROM ' . _DB_PREFIX_ . 'po_link_index i
             INNER JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = i.id_product
             WHERE i.id_profile=' . (int) $profile['id_profile'] . '
               AND i.family_key=\'' . pSQL((string) $assignment['family_key']) . '\'
               AND p.active=1'
        ) ?: [];

        if (!$indexRows) {
            return false;
        }

        $productOptions = [];
        $featureValues = [];
        foreach ($indexRows as $row) {
            $decoded = json_decode((string) $row['options_json'], true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $options = [];
            foreach ($decoded as $featureId => $valueId) {
                $featureId = (int) $featureId;
                if (!in_array($featureId, $optionIds, true)) {
                    continue;
                }
                $valueId = (int) $valueId;
                $options[$featureId] = $valueId;
                $featureValues[$featureId][$valueId] = true;
            }
            $productIdRow = (int) $row['id_product'];
            $productOptions[$productIdRow] = $options;
        }

        if (!isset($productOptions[$productId])) {
            return false;
        }

        $valueIds = [];
        foreach ($featureValues as $values) {
            $valueIds = array_merge($valueIds, array_keys($values));
        }
        $valueIds = array_values(array_unique(array_filter($valueIds)));

        $valueNameMap = [];
        if ($valueIds) {
            $valueRows = $db->executeS('
                SELECT fv.id_feature, fvl.id_feature_value, fvl.value
                FROM ' . _DB_PREFIX_ . 'feature_value fv
                INNER JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                    ON fvl.id_feature_value = fv.id_feature_value
                    AND fvl.id_lang=' . (int) $this->context->language->id . '
                WHERE fv.id_feature IN (' . implode(',', array_map('intval', $optionIds)) . ')
                  AND fv.id_feature_value IN (' . implode(',', array_map('intval', $valueIds)) . ')
            ') ?: [];
            foreach ($valueRows as $row) {
                $valueNameMap[(int) $row['id_feature']][(int) $row['id_feature_value']] = $row['value'];
            }
        }

        $featureNameRows = $db->executeS('
            SELECT id_feature, name
            FROM ' . _DB_PREFIX_ . 'feature_lang
            WHERE id_lang=' . (int) $this->context->language->id . '
              AND id_feature IN (' . implode(',', array_map('intval', $optionIds)) . ')
        ') ?: [];
        $featureNames = [];
        foreach ($featureNameRows as $row) {
            $featureNames[(int) $row['id_feature']] = $row['name'];
        }

        $labelRows = $db->executeS('
            SELECT id_feature, label
            FROM ' . _DB_PREFIX_ . 'po_link_profile_label
            WHERE id_profile=' . (int) $profile['id_profile'] . '
              AND id_lang=' . (int) $this->context->language->id . '
        ') ?: [];
        $labelMap = [];
        foreach ($labelRows as $row) {
            $labelMap[(int) $row['id_feature']] = $row['label'];
        }

        $currentOptions = $productOptions[$productId] ?? [];
        $featurePositions = [];
        $sizeFeatureIds = $this->getConfiguredSizeFeatureIds();

        $missingLabel = $this->module->l('Brak', 'displayproductlinkedfeatures');

        foreach ($optionIds as $featureId) {
            $values = array_keys($featureValues[$featureId] ?? []);
            $hasMissing = false;
            foreach ($productOptions as $candidateOptions) {
                if (!array_key_exists($featureId, $candidateOptions)) {
                    $hasMissing = true;
                    break;
                }
            }
            if ($hasMissing) {
                $values[] = 0;
            }
            $valueEntries = [];
            foreach ($values as $valueId) {
                $expected = $currentOptions;
                if ($valueId === 0) {
                    unset($expected[$featureId]);
                } else {
                    $expected[$featureId] = $valueId;
                }

                $targetProductId = null;
                $isExact = false;
                foreach ($productOptions as $candidateId => $candidateOptions) {
                    $match = true;
                    foreach ($optionIds as $checkFeatureId) {
                        if (array_key_exists($checkFeatureId, $expected)) {
                            if (!isset($candidateOptions[$checkFeatureId]) || $candidateOptions[$checkFeatureId] !== $expected[$checkFeatureId]) {
                                $match = false;
                                break;
                            }
                        } else {
                            if (array_key_exists($checkFeatureId, $candidateOptions)) {
                                $match = false;
                                break;
                            }
                        }
                    }
                    if ($match) {
                        $targetProductId = $candidateId;
                        $isExact = true;
                        if ($candidateId === $productId) {
                            break;
                        }
                    }
                }

                if ($targetProductId === null && $showMuted) {
                    $bestScore = -1;
                    $maxScore = max(0, count($currentOptions) - 1);
                    foreach ($productOptions as $candidateId => $candidateOptions) {
                        if ($valueId === 0) {
                            if (array_key_exists($featureId, $candidateOptions)) {
                                continue;
                            }
                        } else {
                            if (!isset($candidateOptions[$featureId]) || $candidateOptions[$featureId] !== $valueId) {
                                continue;
                            }
                        }
                        $score = 0;
                        foreach ($currentOptions as $currentFeatureId => $currentValueId) {
                            if ($currentFeatureId === $featureId) {
                                continue;
                            }
                            if (isset($candidateOptions[$currentFeatureId]) && $candidateOptions[$currentFeatureId] === $currentValueId) {
                                $score++;
                            }
                        }
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $targetProductId = $candidateId;
                            if ($score >= $maxScore) {
                                break;
                            }
                        }
                    }
                }

                if (!$showMuted && !$isExact) {
                    continue;
                }

                $valueEntries[] = [
                    'value_id' => $valueId,
                    'label' => $valueId === 0 ? $missingLabel : ($valueNameMap[$featureId][$valueId] ?? (string) $valueId),
                    'product_id' => $targetProductId,
                    'active' => $targetProductId === $productId,
                    'disabled' => $valueId === 0 || $targetProductId === null,
                    'muted' => $targetProductId !== null && !$isExact,
                    'link' => ($valueId !== 0 && $targetProductId) ? $this->context->link->getProductLink($targetProductId) : null,
                ];
            }

            usort($valueEntries, function ($a, $b) use ($featureId, $sizeFeatureIds) {
                if (in_array($featureId, $sizeFeatureIds, true)) {
                    return $this->compareSizeLabels((string) $a['label'], (string) $b['label']);
                }

                return strcmp((string) $a['label'], (string) $b['label']);
            });

            $featurePositions[] = [
                'feature_id' => $featureId,
                'title' => $labelMap[$featureId] ?? ($featureNames[$featureId] ?? ('#' . $featureId)),
                'values' => $valueEntries,
            ];
        }

        if ($hiddenOptionIds) {
            $featurePositions = array_values(array_filter($featurePositions, static function (array $position) use ($hiddenOptionIds): bool {
                return !in_array((int) ($position['feature_id'] ?? 0), $hiddenOptionIds, true);
            }));
        }

        if (!$featurePositions) {
            return false;
        }

        $this->context->smarty->assign([
            'feature_positions' => $featurePositions,
            'positions' => [],
            'id_lang' => (int) $this->context->language->id,
        ]);

        return true;
    }

    private function parseCsvIds(string $csv): array
    {
        if ($csv === '') {
            return [];
        }

        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $csv))));
        $ids = array_values(array_unique(array_filter($ids, static function ($id) {
            return $id > 0;
        })));

        return $ids;
    }

    private function getConfiguredSizeFeatureIds(): array
    {
        $csv = (string) Configuration::get('PO_LINKEDPRODUCT_SIZE_FEATURE_IDS', '4');
        if ($csv === '') {
            return [];
        }

        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $csv))));

        return array_values(array_unique(array_filter($ids, static function ($id) {
            return $id > 0;
        })));
    }

    private function compareSizeLabels(string $leftLabel, string $rightLabel): int
    {
        $orderMap = [
            'xxs' => 0,
            'xs' => 1,
            's' => 2,
            'm' => 3,
            'l' => 4,
            'xl' => 5,
            'xxl' => 6,
            'xxxl' => 7,
        ];

        $leftKey = Tools::strtolower(trim($leftLabel));
        $rightKey = Tools::strtolower(trim($rightLabel));
        $leftOrder = $orderMap[$leftKey] ?? null;
        $rightOrder = $orderMap[$rightKey] ?? null;

        if ($leftOrder !== null && $rightOrder !== null) {
            return $leftOrder <=> $rightOrder;
        }

        if ($leftOrder !== null) {
            return -1;
        }

        if ($rightOrder !== null) {
            return 1;
        }

        return strcmp($leftKey, $rightKey);
    }

    protected function shouldBlockBeDisplayed(array $params)
    {
        return (int) Tools::getValue('id_product') > 0;
    }
}
