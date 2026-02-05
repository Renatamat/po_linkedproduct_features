<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/../../src/Service/LinkedProductGroupService.php';

use PoLinkedProductFeatures\Service\LinkedProductGroupService;
use Throwable;

class AdminPoLinkedProductGroupsController extends ModuleAdminController
{
    private const PAGE_SIZE = 20;

    private ?array $dryRunData = null;
    private array $dryRunInput = [];

    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = true;
    }

    public function postProcess()
    {
        $action = (string) Tools::getValue('lp_action');
        if ($action && Tools::getValue('token') !== $this->token) {
            $this->errors[] = $this->l('Nieprawidłowy token.');
            return parent::postProcess();
        }

        switch ($action) {
            case 'dry_run':
                $this->processDryRun();
                break;
            case 'create_group':
                $this->processCreateGroup();
                break;
            case 'delete_group':
                $this->processDeleteGroup();
                break;
            case 'bulk_delete':
                $this->processBulkDelete();
                break;
            case 'rebuild_group':
                $this->processRebuildGroup();
                break;
            case 'remove_product':
                $this->processRemoveProduct();
                break;
        }

        return parent::postProcess();
    }

    public function initContent()
    {
        parent::initContent();

        $view = (bool) Tools::getValue('view');
        $groupId = (int) Tools::getValue('id_group');

        if ($view && $groupId > 0) {
            $this->content .= $this->renderGroupView($groupId);
        } else {
            $this->content .= $this->renderGroupList();
        }

        $this->context->smarty->assign('content', $this->content);
    }

    private function renderGroupList(): string
    {
        $filters = $this->getListFilters();
        $page = max(1, (int) Tools::getValue('page'));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $groupsData = $this->getGroups($filters, $offset, self::PAGE_SIZE);
        $features = $this->getFeatureOptions();
        $filterQuery = $this->buildQuery($filters);

        $this->context->smarty->assign([
            'groups' => $groupsData['rows'],
            'total' => $groupsData['total'],
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'filters' => $filters,
            'features' => $features,
            'dry_run' => $this->dryRunData,
            'dry_run_input' => $this->dryRunInput,
            'current_url' => $this->context->link->getAdminLink('AdminPoLinkedProductGroups'),
            'filter_query' => $filterQuery,
            'token' => $this->token,
        ]);

        return $this->module->fetch('module:po_linkedproduct_features/views/templates/admin/groups.tpl');
    }

    private function renderGroupView(int $groupId): string
    {
        $group = $this->getGroup($groupId);
        if (!$group) {
            $this->errors[] = $this->l('Nie znaleziono grupy.');
            return $this->renderGroupList();
        }

        $filters = [
            'product_id' => (int) Tools::getValue('filter_product_id'),
            'sku' => trim((string) Tools::getValue('filter_sku')),
        ];

        $page = max(1, (int) Tools::getValue('page'));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $service = $this->getGroupService();
        $productsData = $service->findGroupProducts($groupId, $filters, $offset, self::PAGE_SIZE);
        $filterQuery = $this->buildQuery($filters);

        $this->context->smarty->assign([
            'group' => $group,
            'products' => $productsData['rows'],
            'total' => $productsData['total'],
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'filters' => $filters,
            'current_url' => $this->context->link->getAdminLink('AdminPoLinkedProductGroups'),
            'filter_query' => $filterQuery,
            'token' => $this->token,
        ]);

        return $this->module->fetch('module:po_linkedproduct_features/views/templates/admin/group_view.tpl');
    }

    private function processDryRun(): void
    {
        if (!$this->access('add')) {
            $this->errors[] = $this->l('Brak uprawnień do podglądu.');
            return;
        }

        [$prefix, $featureIds] = $this->validateGroupInput();
        if (!$prefix || !$featureIds) {
            return;
        }

        $service = $this->getGroupService();
        $this->dryRunData = $service->previewMatch($prefix, $featureIds, [], 10);
        $this->dryRunInput = [
            'prefix' => $prefix,
            'feature_ids' => $featureIds,
        ];
    }

    private function processCreateGroup(): void
    {
        if (!$this->access('add')) {
            $this->errors[] = $this->l('Brak uprawnień do dodania.');
            return;
        }

        [$prefix, $featureIds] = $this->validateGroupInput();
        if (!$prefix || !$featureIds) {
            return;
        }

        $profileName = trim((string) Tools::getValue('profile_name'));
        if ($profileName === '') {
            $profileName = $this->l('Grupa ') . $prefix;
        }

        $db = Db::getInstance();
        $exists = (int) $db->getValue('SELECT id_group FROM ' . _DB_PREFIX_ . 'po_link_group WHERE sku_prefix=\'' . pSQL($prefix) . '\'');
        if ($exists > 0) {
            $this->errors[] = $this->l('Grupa z tym prefiksem już istnieje.');
            return;
        }

        $db->execute('START TRANSACTION');
        try {
            $db->insert('po_link_profile', [
                'name' => pSQL($profileName),
                'options_csv' => pSQL(implode(',', $featureIds)),
                'family_csv' => null,
                'active' => 1,
            ]);
            $profileId = (int) $db->Insert_ID();

            $db->insert('po_link_group', [
                'id_profile' => (int) $profileId,
                'sku_prefix' => pSQL($prefix),
                'feature_values_json' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $groupId = (int) $db->Insert_ID();

            $db->execute('COMMIT');
        } catch (Throwable $e) {
            $db->execute('ROLLBACK');
            $this->errors[] = $this->l('Nie udało się utworzyć grupy.');
            return;
        }

        $service = $this->getGroupService();
        $count = $service->rebuildGroup($groupId);
        $this->confirmations[] = sprintf($this->l('Grupa utworzona, powiązano produktów: %d.'), $count);
    }

    private function processDeleteGroup(): void
    {
        if (!$this->access('delete')) {
            $this->errors[] = $this->l('Brak uprawnień do usuwania.');
            return;
        }

        $groupId = (int) Tools::getValue('id_group');
        if ($groupId <= 0) {
            return;
        }

        $service = $this->getGroupService();
        $service->deleteGroup($groupId);
        $this->confirmations[] = $this->l('Grupa została usunięta.');
    }

    private function processBulkDelete(): void
    {
        if (!$this->access('delete')) {
            $this->errors[] = $this->l('Brak uprawnień do usuwania.');
            return;
        }

        $ids = Tools::getValue('group_ids', []);
        if (!is_array($ids)) {
            return;
        }

        $service = $this->getGroupService();
        $deleted = 0;
        foreach ($ids as $id) {
            $groupId = (int) $id;
            if ($groupId <= 0) {
                continue;
            }
            $service->deleteGroup($groupId);
            $deleted++;
        }

        if ($deleted > 0) {
            $this->confirmations[] = sprintf($this->l('Usunięto grupy: %d.'), $deleted);
        }
    }

    private function processRebuildGroup(): void
    {
        if (!$this->access('edit')) {
            $this->errors[] = $this->l('Brak uprawnień do przebudowy.');
            return;
        }

        $groupId = (int) Tools::getValue('id_group');
        if ($groupId <= 0) {
            return;
        }

        $service = $this->getGroupService();
        $count = $service->rebuildGroup($groupId);
        $this->confirmations[] = sprintf($this->l('Przebudowano grupę, powiązano produktów: %d.'), $count);
    }

    private function processRemoveProduct(): void
    {
        if (!$this->access('edit')) {
            $this->errors[] = $this->l('Brak uprawnień do edycji.');
            return;
        }

        $groupId = (int) Tools::getValue('id_group');
        $productId = (int) Tools::getValue('id_product');
        if ($groupId <= 0 || $productId <= 0) {
            return;
        }

        $service = $this->getGroupService();
        if ($service->removeProductFromGroup($groupId, $productId)) {
            $this->confirmations[] = $this->l('Produkt został usunięty z grupy.');
        }
    }

    private function validateGroupInput(): array
    {
        $prefix = strtoupper(trim((string) Tools::getValue('sku_prefix')));
        $featureIds = Tools::getValue('feature_ids', []);
        if (!is_array($featureIds)) {
            $featureIds = [];
        }
        $featureIds = array_values(array_unique(array_filter(array_map('intval', $featureIds), static function ($id) {
            return $id > 0;
        })));

        if ($prefix === '') {
            $this->errors[] = $this->l('Prefiks SKU jest wymagany.');
        } elseif (Tools::strlen($prefix) > 64) {
            $this->errors[] = $this->l('Prefiks SKU jest zbyt długi.');
        } elseif (!preg_match('/^[A-Z0-9\-_]+$/', $prefix)) {
            $this->errors[] = $this->l('Prefiks SKU ma niedozwolone znaki.');
        }

        if (count($featureIds) < 1 || count($featureIds) > 3) {
            $this->errors[] = $this->l('Wybierz od 1 do 3 cech.');
        }

        if ($this->errors) {
            $this->dryRunInput = [
                'prefix' => $prefix,
                'feature_ids' => $featureIds,
            ];
            return [null, null];
        }

        return [$prefix, $featureIds];
    }

    private function getGroups(array $filters, int $offset, int $limit): array
    {
        $where = [];
        $joins = ' INNER JOIN ' . _DB_PREFIX_ . 'po_link_profile p ON p.id_profile = g.id_profile';
        if (!empty($filters['prefix'])) {
            $where[] = 'g.sku_prefix LIKE \'%' . pSQL($filters['prefix']) . '%\'';
        }

        if (!empty($filters['feature_id'])) {
            $where[] = 'FIND_IN_SET(' . (int) $filters['feature_id'] . ', p.options_csv)';
        }

        if (!empty($filters['product_id']) || !empty($filters['sku'])) {
            $productId = (int) $filters['product_id'];
            if ($productId <= 0 && !empty($filters['sku'])) {
                $productId = (int) Db::getInstance()->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE reference=\'' . pSQL($filters['sku']) . '\'');
            }
            if ($productId > 0) {
                $assignment = Db::getInstance()->getRow('SELECT id_profile, family_key FROM ' . _DB_PREFIX_ . 'po_link_product_family WHERE id_product=' . (int) $productId);
                if ($assignment) {
                    $where[] = 'g.id_profile=' . (int) $assignment['id_profile'] . ' AND g.sku_prefix=\'' . pSQL((string) $assignment['family_key']) . '\'';
                } else {
                    $where[] = '1=0';
                }
            } else {
                $where[] = '1=0';
            }
        }

        if (!empty($filters['feature_value_id']) && !empty($filters['feature_id'])) {
            $joins .= ' INNER JOIN ' . _DB_PREFIX_ . 'po_link_product_family pf_filter ON pf_filter.id_profile = g.id_profile AND pf_filter.family_key = g.sku_prefix';
            $joins .= ' INNER JOIN ' . _DB_PREFIX_ . 'feature_product fp ON fp.id_product = pf_filter.id_product';
            $where[] = 'fp.id_feature=' . (int) $filters['feature_id'] . ' AND fp.id_feature_value=' . (int) $filters['feature_value_id'];
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) Db::getInstance()->getValue('SELECT COUNT(DISTINCT g.id_group)
            FROM ' . _DB_PREFIX_ . 'po_link_group g' . $joins . $whereSql);

        $rows = Db::getInstance()->executeS('SELECT g.id_group, g.id_profile, g.sku_prefix, g.updated_at, p.options_csv,
            COUNT(DISTINCT pf2.id_product) AS product_count
            FROM ' . _DB_PREFIX_ . 'po_link_group g' . $joins . '
            LEFT JOIN ' . _DB_PREFIX_ . 'po_link_product_family pf2 ON pf2.id_profile = g.id_profile AND pf2.family_key = g.sku_prefix' .
            $whereSql .
            ' GROUP BY g.id_group
              ORDER BY g.updated_at DESC
              LIMIT ' . (int) $offset . ', ' . (int) $limit) ?: [];

        $featureNames = $this->getFeatureOptions();
        foreach ($rows as &$row) {
            $ids = array_filter(array_map('intval', array_map('trim', explode(',', (string) $row['options_csv']))));
            $labels = [];
            foreach ($ids as $id) {
                $labels[] = $featureNames[$id] ?? ('#' . $id);
            }
            $row['features_label'] = $labels ? implode(', ', $labels) : '-';
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total];
    }

    private function getGroup(int $groupId): ?array
    {
        $row = Db::getInstance()->getRow('SELECT g.id_group, g.id_profile, g.sku_prefix, g.updated_at, p.options_csv
            FROM ' . _DB_PREFIX_ . 'po_link_group g
            INNER JOIN ' . _DB_PREFIX_ . 'po_link_profile p ON p.id_profile = g.id_profile
            WHERE g.id_group=' . (int) $groupId);

        if (!$row) {
            return null;
        }

        $features = $this->getFeatureOptions();
        $ids = array_filter(array_map('intval', array_map('trim', explode(',', (string) $row['options_csv']))));
        $labels = [];
        foreach ($ids as $id) {
            $labels[] = $features[$id] ?? ('#' . $id);
        }
        $row['features_label'] = $labels ? implode(', ', $labels) : '-';

        return $row;
    }

    private function getListFilters(): array
    {
        return [
            'prefix' => trim((string) Tools::getValue('filter_prefix')),
            'feature_id' => (int) Tools::getValue('filter_feature_id'),
            'feature_value_id' => (int) Tools::getValue('filter_feature_value_id'),
            'sku' => trim((string) Tools::getValue('filter_sku')),
            'product_id' => (int) Tools::getValue('filter_product_id'),
        ];
    }

    private function getFeatureOptions(): array
    {
        $options = [];
        foreach (Feature::getFeatures((int) $this->context->language->id) as $feature) {
            $id = (int) ($feature['id_feature'] ?? 0);
            $name = (string) ($feature['name'] ?? '');
            if ($id > 0 && $name !== '') {
                $options[$id] = $name;
            }
        }

        return $options;
    }

    private function getGroupService(): LinkedProductGroupService
    {
        return new LinkedProductGroupService($this->module, $this->context);
    }

    private function buildQuery(array $filters): string
    {
        $data = [];
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || $value === 0) {
                continue;
            }
            $data['filter_' . $key] = $value;
        }

        return $data ? '&' . http_build_query($data) : '';
    }
}
