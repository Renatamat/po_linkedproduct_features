<?php

declare(strict_types=1);

namespace PoLinkedProductFeatures\Service;

use Context;
use Db;
use Module;

class LinkedProductGroupService
{
    private Db $db;
    private Context $context;
    private Module $module;

    public function __construct(Module $module, Context $context)
    {
        $this->db = Db::getInstance();
        $this->context = $context;
        $this->module = $module;
    }

    public function previewMatch(string $prefix, array $featureIds, array $featureValueFilters = [], int $limit = 10): array
    {
        $featureIds = $this->sanitizeFeatureIds($featureIds);
        if (!$featureIds) {
            return ['count' => 0, 'rows' => []];
        }

        $baseSql = $this->buildProductMatchSql($prefix, $featureIds, $featureValueFilters);
        $count = (int) $this->db->getValue('SELECT COUNT(DISTINCT p.id_product) ' . $baseSql);

        $rows = $this->db->executeS(
            'SELECT p.id_product, p.reference, p.active, pl.name ' . $baseSql .
            ' INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON pl.id_product = p.id_product AND pl.id_lang=' . (int) $this->context->language->id .
            ' ORDER BY p.id_product DESC
              LIMIT ' . (int) $limit
        ) ?: [];

        return ['count' => $count, 'rows' => $rows];
    }

    public function rebuildGroup(int $groupId): int
    {
        $group = $this->db->getRow('SELECT g.id_group, g.id_profile, g.sku_prefix, g.feature_values_json, p.options_csv
            FROM ' . _DB_PREFIX_ . 'po_link_group g
            INNER JOIN ' . _DB_PREFIX_ . 'po_link_profile p ON p.id_profile = g.id_profile
            WHERE g.id_group=' . (int) $groupId);

        if (!$group) {
            return 0;
        }

        $featureIds = $this->parseCsvIds($group['options_csv'] ?? '');
        if (!$featureIds) {
            return 0;
        }

        $filters = $this->parseFeatureValueFilters($group['feature_values_json'] ?? null);
        $productIds = $this->findProductIds($group['sku_prefix'], $featureIds, $filters);

        $this->db->execute('START TRANSACTION');
        try {
            $this->db->delete('po_link_product_family', 'id_profile=' . (int) $group['id_profile'] . ' AND family_key=\'' . pSQL((string) $group['sku_prefix']) . '\'');
            $this->db->execute('DELETE FROM ' . _DB_PREFIX_ . 'po_link_index
                WHERE id_profile=' . (int) $group['id_profile'] . ' AND family_key=\'' . pSQL((string) $group['sku_prefix']) . '\'');

            if ($productIds) {
                $values = [];
                foreach ($productIds as $productId) {
                    $values[] = '(' . (int) $productId . ', ' . (int) $group['id_profile'] . ', \'
                        . pSQL((string) $group['sku_prefix']) . '\', NOW())';
                }

                foreach (array_chunk($values, 200) as $chunk) {
                    $this->db->execute('REPLACE INTO ' . _DB_PREFIX_ . 'po_link_product_family (id_product, id_profile, family_key, updated_at)
                        VALUES ' . implode(',', $chunk));
                }

                foreach ($productIds as $productId) {
                    $this->module->updateFeatureIndexForProduct((int) $productId);
                }
            }

            $this->db->execute('UPDATE ' . _DB_PREFIX_ . 'po_link_group
                SET updated_at = NOW()
                WHERE id_group=' . (int) $groupId);

            $this->db->execute('COMMIT');
        } catch (\Throwable $e) {
            $this->db->execute('ROLLBACK');
            throw $e;
        }

        return count($productIds);
    }

    public function deleteGroup(int $groupId): void
    {
        $group = $this->db->getRow('SELECT id_profile, sku_prefix FROM ' . _DB_PREFIX_ . 'po_link_group WHERE id_group=' . (int) $groupId);
        if (!$group) {
            return;
        }

        $this->db->execute('START TRANSACTION');
        try {
            $this->db->delete('po_link_group', 'id_group=' . (int) $groupId);
            $this->db->delete('po_link_product_family', 'id_profile=' . (int) $group['id_profile'] . ' AND family_key=\'' . pSQL((string) $group['sku_prefix']) . '\'');
            $this->db->execute('DELETE FROM ' . _DB_PREFIX_ . 'po_link_index
                WHERE id_profile=' . (int) $group['id_profile'] . ' AND family_key=\'' . pSQL((string) $group['sku_prefix']) . '\'');
            $this->db->execute('COMMIT');
        } catch (\Throwable $e) {
            $this->db->execute('ROLLBACK');
            throw $e;
        }
    }

    public function removeProductFromGroup(int $groupId, int $productId): bool
    {
        $group = $this->db->getRow('SELECT id_profile, sku_prefix FROM ' . _DB_PREFIX_ . 'po_link_group WHERE id_group=' . (int) $groupId);
        if (!$group) {
            return false;
        }

        $this->db->execute('START TRANSACTION');
        try {
            $this->db->delete('po_link_product_family', 'id_product=' . (int) $productId . ' AND id_profile=' . (int) $group['id_profile'] . ' AND family_key=\'' . pSQL((string) $group['sku_prefix']) . '\'');
            $this->db->delete('po_link_index', 'id_product=' . (int) $productId);
            $this->db->execute('UPDATE ' . _DB_PREFIX_ . 'po_link_group
                SET updated_at = NOW()
                WHERE id_group=' . (int) $groupId);
            $this->db->execute('COMMIT');
        } catch (\Throwable $e) {
            $this->db->execute('ROLLBACK');
            throw $e;
        }

        return true;
    }

    public function findGroupProducts(int $groupId, array $filters, int $offset, int $limit): array
    {
        $group = $this->db->getRow('SELECT id_profile, sku_prefix FROM ' . _DB_PREFIX_ . 'po_link_group WHERE id_group=' . (int) $groupId);
        if (!$group) {
            return ['rows' => [], 'total' => 0];
        }

        $where = ' WHERE pf.id_profile=' . (int) $group['id_profile'] . ' AND pf.family_key=\'' . pSQL((string) $group['sku_prefix']) . '\'';
        if (!empty($filters['product_id'])) {
            $where .= ' AND p.id_product=' . (int) $filters['product_id'];
        }
        if (!empty($filters['sku'])) {
            $where .= ' AND p.reference LIKE \'%' . pSQL((string) $filters['sku']) . '%\'';
        }

        $total = (int) $this->db->getValue('SELECT COUNT(*)
            FROM ' . _DB_PREFIX_ . 'po_link_product_family pf
            INNER JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = pf.id_product' . $where);

        $rows = $this->db->executeS('SELECT p.id_product, p.reference, p.active, pl.name
            FROM ' . _DB_PREFIX_ . 'po_link_product_family pf
            INNER JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = pf.id_product
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON pl.id_product = p.id_product AND pl.id_lang=' . (int) $this->context->language->id .
            $where .
            ' ORDER BY p.id_product DESC
              LIMIT ' . (int) $offset . ', ' . (int) $limit) ?: [];

        return ['rows' => $rows, 'total' => $total];
    }

    public function findProductIds(string $prefix, array $featureIds, array $featureValueFilters = []): array
    {
        $featureIds = $this->sanitizeFeatureIds($featureIds);
        if (!$featureIds) {
            return [];
        }

        $baseSql = $this->buildProductMatchSql($prefix, $featureIds, $featureValueFilters);
        $rows = $this->db->executeS('SELECT DISTINCT p.id_product ' . $baseSql) ?: [];

        return array_values(array_map('intval', array_column($rows, 'id_product')));
    }

    private function sanitizeFeatureIds(array $featureIds): array
    {
        $clean = array_values(array_unique(array_filter(array_map('intval', $featureIds), static function ($id) {
            return $id > 0;
        })));

        return $clean;
    }

    private function parseCsvIds(?string $csv): array
    {
        if (!$csv) {
            return [];
        }

        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $csv))));
        $ids = array_values(array_unique(array_filter($ids, static function ($id) {
            return $id > 0;
        })));

        return $ids;
    }

    private function parseFeatureValueFilters(?string $json): array
    {
        if (!$json) {
            return [];
        }

        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $filters = [];
        foreach ($decoded as $featureId => $valueId) {
            $featureId = (int) $featureId;
            $valueId = (int) $valueId;
            if ($featureId > 0 && $valueId > 0) {
                $filters[$featureId] = $valueId;
            }
        }

        return $filters;
    }

    private function buildProductMatchSql(string $prefix, array $featureIds, array $featureValueFilters = []): string
    {
        $likePrefix = pSQL(addcslashes($prefix, '%_'));
        $sql = ' FROM ' . _DB_PREFIX_ . 'product p';
        $index = 1;
        foreach ($featureIds as $featureId) {
            $alias = 'fp' . $index;
            $sql .= ' INNER JOIN ' . _DB_PREFIX_ . 'feature_product ' . $alias . '
                ON ' . $alias . '.id_product = p.id_product
                AND ' . $alias . '.id_feature = ' . (int) $featureId;
            if (isset($featureValueFilters[$featureId])) {
                $sql .= ' AND ' . $alias . '.id_feature_value = ' . (int) $featureValueFilters[$featureId];
            }
            $index++;
        }

        $sql .= ' WHERE p.reference LIKE "' . $likePrefix . '%"';

        return $sql;
    }
}
