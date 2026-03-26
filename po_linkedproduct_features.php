<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/Hook/AbstractDisplayHook.php';
require_once __DIR__ . '/src/Hook/DisplayProductLinkedFeatures.php';
require_once __DIR__ . '/src/Service/LinkedProductGroupService.php';

class Po_linkedproduct_features extends Module
{
    /** @var string */
    protected $_html = '';
    public const CONFIG_SIZE_FEATURE_IDS = 'PO_LINKEDPRODUCT_SIZE_FEATURE_IDS';
    private const CUSTOM_HOOK_PRODUCT_LINKED = 'displayProductLinked';
    private const GROUP_PAGE_SIZE = 20;
    protected ?array $groupDryRunData = null;
    protected array $groupDryRunInput = [];

    public function __construct()
    {
        $this->name = 'po_linkedproduct_features';
        $this->tab = 'administration';
        $this->version = '1.1.2';
        $this->author = 'Przemysław Markiewicz';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Linkowanie po cechach i rodzinie');
        $this->description = $this->l('Moduł do linkowania produktów po cechach i rodzinie.');

        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->languages = \Language::getLanguages(false);
    }

    public function install()
    {
        $installResult = include dirname(__FILE__) . '/sql/features_install.php';
        if ($installResult === false) {
            return false;
        }

        if (!$this->ensureCustomHooksExist()) {
            return false;
        }

        $result = parent::install()
            && $this->registerRequiredHooks()
            && $this->installTab();

        if ($result) {
            \Configuration::updateValue(self::CONFIG_SIZE_FEATURE_IDS, '4');
        }

        return $result;
    }

    public function uninstall()
    {
        $uninstallResult = include dirname(__FILE__) . '/sql/features_uninstall.php';
        if ($uninstallResult === false) {
            return false;
        }

        \Configuration::deleteByName(self::CONFIG_SIZE_FEATURE_IDS);

        $result = parent::uninstall()
            && $this->uninstallTab();

        if (!$result) {
            return false;
        }

        return $this->removeCustomHookIfUnused(self::CUSTOM_HOOK_PRODUCT_LINKED);
    }

    public function registerRequiredHooks(): bool
    {
        foreach ($this->getRequiredHooks() as $hookName) {
            if (!$this->isRegisteredInHook($hookName) && !$this->registerHook($hookName)) {
                return false;
            }
        }

        return true;
    }

    private function getRequiredHooks(): array
    {
        return [
            'displayAdminProductsExtra',
            self::CUSTOM_HOOK_PRODUCT_LINKED,
            'displayHeader',
            'actionProductUpdate',
            'actionObjectProductAddAfter',
            'actionObjectProductUpdateAfter',
        ];
    }

    public function ensureCustomHooksExist(): bool
    {
        $hookId = (int) \Hook::getIdByName(self::CUSTOM_HOOK_PRODUCT_LINKED);
        if ($hookId > 0) {
            return true;
        }

        $hook = new \Hook();
        $hook->name = self::CUSTOM_HOOK_PRODUCT_LINKED;
        $hook->title = self::CUSTOM_HOOK_PRODUCT_LINKED;
        $hook->description = 'Custom hook for rendering linked product features on product page';
        $hook->position = 1;
        $hook->live_edit = 0;

        return (bool) $hook->add();
    }

    private function removeCustomHookIfUnused(string $hookName): bool
    {
        $hookId = (int) \Hook::getIdByName($hookName);
        if ($hookId <= 0) {
            return true;
        }

        $modulesUsingHook = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'hook_module` WHERE id_hook=' . $hookId
        );

        if ($modulesUsingHook > 0) {
            return true;
        }

        $hook = new \Hook($hookId);

        return (bool) $hook->delete();
    }

    public function installTab(): bool
    {
        $tabClass = 'AdminPoLinkedProductGroups';
        if ((int) \Tab::getIdFromClassName($tabClass) > 0) {
            return true;
        }

        $tab = new \Tab();
        $tab->active = 1;
        $tab->class_name = $tabClass;
        $tab->module = $this->name;
        $tab->id_parent = (int) \Tab::getIdFromClassName('AdminParentModulesSf');
        $tab->name = [];
        foreach (\Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = $this->l('Powiązania produktów');
        }

        return (bool) $tab->add();
    }

    public function uninstallTab(): bool
    {
        $tabId = (int) \Tab::getIdFromClassName('AdminPoLinkedProductGroups');
        if ($tabId <= 0) {
            return true;
        }

        $tab = new \Tab($tabId);

        return (bool) $tab->delete();
    }

    public function getContent()
    {
        $this->_html = '';
        $section = (string) Tools::getValue('lp_section', 'profiles');

        if (Tools::isSubmit('lp_action') && $section === 'profiles') {
            $action = (string) Tools::getValue('lp_action');
            try {
                switch ($action) {
                    case 'save_profile':
                        $result = $this->saveProfileFromRequest();
                        $this->_html .= $this->displayConfirmation(
                            $result['is_new'] ? $this->l('Profil został dodany.') : $this->l('Profil został zapisany.')
                        );
                        break;
                    case 'delete_profile':
                        $profileId = (int) Tools::getValue('profile_id');
                        if ($profileId > 0) {
                            $this->deleteProfile($profileId);
                            $this->_html .= $this->displayConfirmation($this->l('Profil został usunięty.'));
                        }
                        break;
                    case 'rebuild_index':
                        $count = $this->rebuildFeatureIndex();
                        $this->_html .= $this->displayConfirmation(
                            $this->l('Indeks został przebudowany dla produktów: ') . (int) $count
                        );
                        break;
                    case 'save_size_feature_ids':
                        $sizeFeatureIds = $this->parseCsvIds((string) Tools::getValue('size_feature_ids', ''));
                        \Configuration::updateValue(self::CONFIG_SIZE_FEATURE_IDS, implode(',', $sizeFeatureIds));
                        $this->_html .= $this->displayConfirmation($this->l('Zapisano ID cech rozmiaru.'));
                        break;
                }
            } catch (\Exception $e) {
                $this->_html .= $this->displayError($this->l('Błąd akcji: ') . $e->getMessage());
            }
        }

        if (Tools::isSubmit('lp_action') && $section === 'groups') {
            $this->processGroupActions();
        }

        $navigation = $this->renderNavigation($section);

        if ($section === 'groups') {
            return $this->_html . $navigation . $this->renderGroupManagement();
        }

        return $this->_html . $navigation . $this->renderFeatureProfiles();
    }

    public function hookDisplayAdminProductsExtra($params)
    {
        if (!isset($params['id_product'])) {
            return '';
        }

        $productId = (int) $params['id_product'];
        $db = \Db::getInstance();
        $profiles = $db->executeS('SELECT id_profile, name FROM ' . _DB_PREFIX_ . 'po_link_profile WHERE active=1 ORDER BY name ASC') ?: [];
        $assignment = $db->getRow('SELECT id_profile, family_key FROM ' . _DB_PREFIX_ . 'po_link_product_family WHERE id_product=' . (int) $productId);

        $this->context->smarty->assign([
            'feature_profiles' => $profiles,
            'feature_assignment' => $assignment ?: ['id_profile' => 0, 'family_key' => ''],
        ]);

        return $this->display($this->getLocalPath() . 'po_linkedproduct_features.php', 'views/templates/hook/features_assignment.tpl');
    }

    public function hookDisplayProductLinked($params)
    {
        $hook = new \PoLinkedProductFeatures\Hook\DisplayProductLinkedFeatures($this, \Context::getContext());

        return $hook->run($params);
    }

    public function hookDisplayHeader($params)
    {
        $this->context->controller->registerJavascript(
            'modules-po_linkedproduct_features-front',
            'modules/' . $this->name . '/views/js/front.js',
            ['position' => 'bottom', 'priority' => 200]
        );
        $this->context->controller->registerStylesheet(
            'modules-po_linkedproduct_features-style-front',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 150]
        );
    }

    public function hookActionProductUpdate($params)
    {
        if (!isset($params['id_product'])) {
            return null;
        }

        $productId = (int) $params['id_product'];
        $this->saveProductFamilyAssignmentFromRequest($productId);
        $this->updateFeatureIndexForProduct($productId);

        return null;
    }

    public function hookActionObjectProductAddAfter($params)
    {
        $productId = 0;
        if (isset($params['object']) && isset($params['object']->id)) {
            $productId = (int) $params['object']->id;
        } elseif (isset($params['id_product'])) {
            $productId = (int) $params['id_product'];
        }

        if ($productId > 0) {
            $this->saveProductFamilyAssignmentFromRequest($productId);
            $this->updateFeatureIndexForProduct($productId);
        }

        return null;
    }

    public function hookActionObjectProductUpdateAfter($params)
    {
        $productId = 0;
        if (isset($params['object']) && isset($params['object']->id)) {
            $productId = (int) $params['object']->id;
        } elseif (isset($params['id_product'])) {
            $productId = (int) $params['id_product'];
        }

        if ($productId > 0) {
            $this->saveProductFamilyAssignmentFromRequest($productId);
            $this->updateFeatureIndexForProduct($productId);
        }

        return null;
    }

    protected function renderFeatureProfiles(): string
    {
        $db = \Db::getInstance();
        $profiles = $db->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'po_link_profile ORDER BY id_profile DESC') ?: [];
        $featureOptions = $this->getFeatureOptions((int) $this->context->language->id);
        $languages = \Language::getLanguages(false);

        $profileId = (int) Tools::getValue('profile_id');
        $profile = null;
        $labelMap = [];
        if ($profileId > 0) {
            $profile = $db->getRow('SELECT * FROM ' . _DB_PREFIX_ . 'po_link_profile WHERE id_profile=' . (int) $profileId);
            $labels = $db->executeS('SELECT id_feature, id_lang, label FROM ' . _DB_PREFIX_ . 'po_link_profile_label WHERE id_profile=' . (int) $profileId) ?: [];
            foreach ($labels as $label) {
                $labelMap[(int) $label['id_feature']][(int) $label['id_lang']] = $label['label'];
            }
        }

        $selectedOptions = $this->parseCsvIds($profile['options_csv'] ?? '');
        $hiddenOptions = $this->parseCsvIds($profile['hidden_options_csv'] ?? '');
        $sizeFeatureIds = $this->parseCsvIds((string) \Configuration::get(self::CONFIG_SIZE_FEATURE_IDS, '4'));

        $output = '<div class="panel">
            <div class="panel-heading">' . $this->l('Profile linkowania po cechach') . '</div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>' . $this->l('ID') . '</th>
                            <th>' . $this->l('Nazwa') . '</th>
                            <th>' . $this->l('Options CSV') . '</th>
                            <th>' . $this->l('Aktywny') . '</th>
                            <th>' . $this->l('Pokazuj niepełne dopasowania (is-muted)') . '</th>
                            <th>' . $this->l('Akcje') . '</th>
                        </tr>
                    </thead>
                    <tbody>';

        if (!$profiles) {
            $output .= '<tr><td colspan="6">' . $this->l('Brak profili.') . '</td></tr>';
        } else {
            foreach ($profiles as $p) {
                $optionsIds = $this->parseCsvIds((string) ($p['options_csv'] ?? ''));
                $optionsLabels = [];
                foreach ($optionsIds as $featureId) {
                    $optionsLabels[] = $featureOptions[$featureId] ?? ('#' . $featureId);
                }
                $optionsLabel = $optionsLabels ? implode(', ', $optionsLabels) : '-';

                $output .= '<tr>
                    <td>#' . (int) $p['id_profile'] . '</td>
                    <td>' . htmlspecialchars((string) $p['name']) . '</td>
                    <td>' . htmlspecialchars($optionsLabel) . '</td>
                    <td>' . ((int) $p['active'] === 1 ? '✅' : '❌') . '</td>
                    <td>' . ((int) ($p['show_muted'] ?? 1) === 1 ? '✅' : '❌') . '</td>
                    <td>
                        <a class="btn btn-default btn-xs" href="' . $this->context->link->getAdminLink('AdminModules', true)
                            . '&configure=' . $this->name . '&profile_id=' . (int) $p['id_profile'] . '">' . $this->l('Edytuj') . '</a>
                        <form method="post" style="display:inline-block" onsubmit="return confirm(\'' . $this->l('Usunąć profil?') . '\');">
                            <input type="hidden" name="lp_action" value="delete_profile">
                            <input type="hidden" name="profile_id" value="' . (int) $p['id_profile'] . '">
                            <button type="submit" class="btn btn-danger btn-xs">' . $this->l('Usuń') . '</button>
                        </form>
                    </td>
                </tr>';
            }
        }

        $output .= '</tbody></table></div></div>';

        $output .= '<form method="post" class="defaultForm form-horizontal">
            <input type="hidden" name="lp_action" value="save_profile">
            <input type="hidden" name="profile_id" value="' . (int) $profileId . '">
            <div class="panel">
                <div class="panel-heading">' . ($profileId ? $this->l('Edytuj profil') : $this->l('Dodaj profil')) . '</div>
                <div class="form-group">
                    <label class="control-label col-lg-3">' . $this->l('Nazwa') . '</label>
                    <div class="col-lg-9">
                        <input type="text" name="profile_name" class="form-control" value="' . htmlspecialchars((string) ($profile['name'] ?? '')) . '" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">' . $this->l('Cechy OPTIONS (max 3)') . '</label>
                    <div class="col-lg-9">
                        <select name="profile_options[]" class="form-control" multiple size="10">';

        foreach ($featureOptions as $featureId => $featureName) {
            $output .= '<option value="' . (int) $featureId . '"' . (in_array($featureId, $selectedOptions, true) ? ' selected' : '') . '>'
                . htmlspecialchars($featureName) . '</option>';
        }

        $output .= '        </select>
                        <p class="help-block">' . $this->l('Wybierz 1-3 cechy do przełączników.') . '</p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">' . $this->l('Aktywny') . '</label>
                    <div class="col-lg-9">
                        <input type="checkbox" name="profile_active" value="1"' . ((int) ($profile['active'] ?? 1) === 1 ? ' checked' : '') . '>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">' . $this->l('Pokazuj niepełne dopasowania (is-muted)') . '</label>
                    <div class="col-lg-9">
                        <input type="checkbox" name="profile_show_muted" value="1"' . ((int) ($profile['show_muted'] ?? 1) === 1 ? ' checked' : '') . '>
                        <p class="help-block">' . $this->l('Po odznaczeniu moduł nie będzie łączył ani wyświetlał pozycji oznaczonych jako is-muted.') . '</p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">' . $this->l('Nagłówki (override)') . '</label>
                    <div class="col-lg-9">';

        if (!$selectedOptions) {
            $output .= '<p class="help-block">' . $this->l('Wybierz cechy w OPTIONS, aby ustawić własne nagłówki.') . '</p>';
        } else {
            $output .= '<div class="table-responsive"><table class="table">
                <thead><tr><th>' . $this->l('Cecha') . '</th>';
            foreach ($languages as $lang) {
                $output .= '<th>' . htmlspecialchars($lang['iso_code']) . '</th>';
            }
            $output .= '</tr></thead><tbody>';
            foreach ($selectedOptions as $featureId) {
                $output .= '<tr><td>' . htmlspecialchars($featureOptions[$featureId] ?? ('#' . $featureId))
                    . '<br><label style="font-weight:normal; margin-top:6px;"><input type="checkbox" name="profile_hidden_options[]" value="' . (int) $featureId . '"'
                    . (in_array($featureId, $hiddenOptions, true) ? ' checked' : '') . '> ' . $this->l('Ukryj na froncie') . '</label></td>';
                foreach ($languages as $lang) {
                    $value = $labelMap[$featureId][$lang['id_lang']] ?? '';
                    $output .= '<td><input type="text" class="form-control" name="profile_label[' . (int) $featureId . '][' . (int) $lang['id_lang'] . ']" value="' . htmlspecialchars((string) $value) . '"></td>';
                }
                $output .= '</tr>';
            }
            $output .= '</tbody></table></div>';
        }

        $output .= '        </div>
                </div>
                <div class="panel-footer">
                    <button type="submit" class="btn btn-default pull-right">
                        <i class="process-icon-save"></i> ' . $this->l('Save') . '
                    </button>
                </div>
            </div>
        </form>';

        $output .= '<form method="post" class="defaultForm form-horizontal" style="margin-top:15px;">
            <input type="hidden" name="lp_action" value="save_size_feature_ids">
            <div class="panel">
                <div class="panel-heading">' . $this->l('Ustawienia sortowania rozmiaru') . '</div>
                <div class="form-group">
                    <label class="control-label col-lg-3">' . $this->l('ID cech rozmiaru') . '</label>
                    <div class="col-lg-9">
                        <input type="text" name="size_feature_ids" class="form-control" value="' . htmlspecialchars(implode(',', $sizeFeatureIds)) . '">
                        <p class="help-block">' . $this->l('Podaj ID cech rozmiaru oddzielone przecinkami (np. 4,12,15). Dla tych cech kolejność wartości będzie XS, S, M, L, XL...') . '</p>
                    </div>
                </div>
                <div class="panel-footer">
                    <button type="submit" class="btn btn-default pull-right">
                        <i class="process-icon-save"></i> ' . $this->l('Save') . '
                    </button>
                </div>
            </div>
        </form>';

        $output .= '<form method="post" class="defaultForm">
            <input type="hidden" name="lp_action" value="rebuild_index">
            <button type="submit" class="btn btn-primary" onclick="return confirm(\'' . $this->l('Przebudować indeks dla wszystkich produktów?') . '\');">
                ' . $this->l('Przebuduj indeks') . '
            </button>
        </form>';

        return $output;
    }

    protected function renderNavigation(string $section): string
    {
        $baseUrl = $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name;
        $profilesUrl = $baseUrl . '&lp_section=profiles';
        $groupsUrl = $baseUrl . '&lp_section=groups';

        return '<div class="panel">
            <ul class="nav nav-tabs">
                <li' . ($section === 'profiles' ? ' class="active"' : '') . '>
                    <a href="' . $profilesUrl . '">' . $this->l('Profile cech') . '</a>
                </li>
                <li' . ($section === 'groups' ? ' class="active"' : '') . '>
                    <a href="' . $groupsUrl . '">' . $this->l('Powiązania produktów') . '</a>
                </li>
            </ul>
        </div>';
    }

    protected function processGroupActions(): void
    {
        $token = (string) Tools::getValue('token');
        if ($token !== Tools::getAdminTokenLite('AdminModules')) {
            $this->_html .= $this->displayError($this->l('Nieprawidłowy token.'));
            return;
        }

        $action = (string) Tools::getValue('lp_action');
        $service = $this->getGroupService();

        try {
            switch ($action) {
                case 'dry_run':
                    $this->processGroupDryRun($service);
                    break;
                case 'create_group':
                    $this->processGroupCreate($service);
                    break;
                case 'delete_group':
                    $this->processGroupDelete($service);
                    break;
                case 'bulk_delete':
                    $this->processGroupBulkDelete($service);
                    break;
                case 'rebuild_group':
                    $this->processGroupRebuild($service);
                    break;
                case 'remove_product':
                    $this->processGroupRemoveProduct($service);
                    break;
            }
        } catch (\Throwable $e) {
            $this->_html .= $this->displayError($this->l('Błąd akcji: ') . $e->getMessage());
        }
    }

    protected function renderGroupManagement(): string
    {
        $view = (bool) Tools::getValue('view');
        $groupId = (int) Tools::getValue('id_group');
        $token = Tools::getAdminTokenLite('AdminModules');
        $currentUrl = $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name . '&lp_section=groups';

        if ($view && $groupId > 0) {
            $group = $this->getGroup($groupId);
            if (!$group) {
                $this->_html .= $this->displayError($this->l('Nie znaleziono grupy.'));
                return '';
            }

            $filters = [
                'product_id' => (int) Tools::getValue('filter_product_id'),
                'sku' => trim((string) Tools::getValue('filter_sku')),
            ];
            $page = max(1, (int) Tools::getValue('page'));
            $offset = ($page - 1) * self::GROUP_PAGE_SIZE;
            $service = $this->getGroupService();
            $productsData = $service->findGroupProducts($groupId, $filters, $offset, self::GROUP_PAGE_SIZE);

            $this->context->smarty->assign([
                'group' => $group,
                'products' => $productsData['rows'],
                'total' => $productsData['total'],
                'page' => $page,
                'page_size' => self::GROUP_PAGE_SIZE,
                'page_count' => (int) ceil($productsData['total'] / self::GROUP_PAGE_SIZE),
                'filters' => $filters,
                'current_url' => $currentUrl,
                'filter_query' => $this->buildQuery($filters),
                'token' => $token,
            ]);

            return $this->display($this->getLocalPath() . $this->name . '.php', 'views/templates/admin/group_view.tpl');
        }

        $filters = [];
        $page = max(1, (int) Tools::getValue('page'));
        $offset = ($page - 1) * self::GROUP_PAGE_SIZE;
        $groupsData = $this->getGroups($filters, $offset, self::GROUP_PAGE_SIZE);

        $this->context->smarty->assign([
            'groups' => $groupsData['rows'],
            'total' => $groupsData['total'],
            'page' => $page,
            'page_size' => self::GROUP_PAGE_SIZE,
            'page_count' => (int) ceil($groupsData['total'] / self::GROUP_PAGE_SIZE),
            'filters' => $filters,
            'features' => $this->getFeatureOptions((int) $this->context->language->id),
            'profiles' => $this->getProfileOptions(),
            'dry_run' => $this->groupDryRunData ?? null,
            'dry_run_input' => $this->groupDryRunInput ?? [],
            'current_url' => $currentUrl,
            'filter_query' => '',
            'token' => $token,
        ]);

        return $this->display($this->getLocalPath() . $this->name . '.php', 'views/templates/admin/groups.tpl');
    }

    protected function processGroupDryRun(\PoLinkedProductFeatures\Service\LinkedProductGroupService $service): void
    {
        [$prefix, $profileId, $featureIds] = $this->validateGroupInput();
        if (!$prefix || !$profileId || !$featureIds) {
            return;
        }

        $this->groupDryRunData = $service->previewMatch($prefix, $featureIds, [], 10);
        $this->groupDryRunInput = [
            'prefix' => $prefix,
            'profile_id' => $profileId,
        ];
    }

    protected function processGroupCreate(\PoLinkedProductFeatures\Service\LinkedProductGroupService $service): void
    {
        [$prefix, $profileId, $featureIds] = $this->validateGroupInput();
        if (!$prefix || !$profileId || !$featureIds) {
            return;
        }

        $db = \Db::getInstance();
        $exists = (int) $db->getValue('SELECT id_group FROM ' . _DB_PREFIX_ . 'po_link_group WHERE sku_prefix=\'' . pSQL($prefix) . '\' AND id_profile=' . (int) $profileId);
        if ($exists > 0) {
            $this->_html .= $this->displayError($this->l('Grupa z tym prefiksem już istnieje.'));
            return;
        }

        $db->execute('START TRANSACTION');
        try {
            $db->insert('po_link_group', [
                'id_profile' => (int) $profileId,
                'sku_prefix' => pSQL($prefix),
                'feature_values_json' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $groupId = (int) $db->Insert_ID();

            $db->execute('COMMIT');
        } catch (\Throwable $e) {
            $db->execute('ROLLBACK');
            $this->_html .= $this->displayError($this->l('Nie udało się utworzyć grupy.'));
            return;
        }

        $count = $service->rebuildGroup($groupId);
        $this->_html .= $this->displayConfirmation(sprintf($this->l('Grupa utworzona, powiązano produktów: %d.'), $count));
    }

    protected function processGroupDelete(\PoLinkedProductFeatures\Service\LinkedProductGroupService $service): void
    {
        $groupId = (int) Tools::getValue('id_group');
        if ($groupId <= 0) {
            return;
        }

        $service->deleteGroup($groupId);
        $this->_html .= $this->displayConfirmation($this->l('Grupa została usunięta.'));
    }

    protected function processGroupBulkDelete(\PoLinkedProductFeatures\Service\LinkedProductGroupService $service): void
    {
        $ids = Tools::getValue('group_ids', []);
        if (!is_array($ids)) {
            return;
        }

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
            $this->_html .= $this->displayConfirmation(sprintf($this->l('Usunięto grupy: %d.'), $deleted));
        }
    }

    protected function processGroupRebuild(\PoLinkedProductFeatures\Service\LinkedProductGroupService $service): void
    {
        $groupId = (int) Tools::getValue('id_group');
        if ($groupId <= 0) {
            return;
        }

        $count = $service->rebuildGroup($groupId);
        $this->_html .= $this->displayConfirmation(sprintf($this->l('Przebudowano grupę, powiązano produktów: %d.'), $count));
    }

    protected function processGroupRemoveProduct(\PoLinkedProductFeatures\Service\LinkedProductGroupService $service): void
    {
        $groupId = (int) Tools::getValue('id_group');
        $productId = (int) Tools::getValue('id_product');
        if ($groupId <= 0 || $productId <= 0) {
            return;
        }

        if ($service->removeProductFromGroup($groupId, $productId)) {
            $this->_html .= $this->displayConfirmation($this->l('Produkt został usunięty z grupy.'));
        }
    }

    protected function validateGroupInput(): array
    {
        $rawSkuRule = strtoupper(trim((string) Tools::getValue('sku_prefix')));
        $prefix = $this->normalizeSkuRule($rawSkuRule);
        $profileId = (int) Tools::getValue('profile_id');
        $featureIds = [];
        if ($profileId > 0) {
            $profile = \Db::getInstance()->getRow('SELECT options_csv FROM ' . _DB_PREFIX_ . 'po_link_profile WHERE id_profile=' . (int) $profileId);
            if ($profile) {
                $featureIds = array_values(array_unique(array_filter(array_map('intval', array_map('trim', explode(',', (string) $profile['options_csv']))), static function ($id) {
                    return $id > 0;
                })));
            }
        }

        $hasError = false;
        $isExactSkuList = strpos($rawSkuRule, ',') !== false;

        if ($prefix === '' && $isExactSkuList) {
            $this->_html .= $this->displayError($this->l('Podaj poprawną listę pełnych SKU oddzielonych przecinkami.'));
            $hasError = true;
        } elseif ($prefix === '') {
            $this->_html .= $this->displayError($this->l('Prefiks SKU lub lista SKU jest wymagana.'));
            $hasError = true;
        } elseif (Tools::strlen($prefix) > 64) {
            $this->_html .= $this->displayError($this->l('Reguła SKU jest zbyt długa.'));
            $hasError = true;
        } elseif ($isExactSkuList) {
            $skuList = $this->parseSkuList($rawSkuRule);
            if (!$skuList) {
                $this->_html .= $this->displayError($this->l('Podaj poprawną listę pełnych SKU oddzielonych przecinkami.'));
                $hasError = true;
            }
        } elseif (!preg_match('/^[A-Z0-9\-_]+$/', $prefix)) {
            $this->_html .= $this->displayError($this->l('Prefiks SKU ma niedozwolone znaki.'));
            $hasError = true;
        }

        if ($profileId <= 0) {
            $this->_html .= $this->displayError($this->l('Wybierz profil linkowania.'));
            $hasError = true;
        } elseif (count($featureIds) < 1 || count($featureIds) > 3) {
            $this->_html .= $this->displayError($this->l('Wybrany profil ma nieprawidłowe cechy.'));
            $hasError = true;
        }

        if ($hasError) {
            $this->groupDryRunInput = [
                'prefix' => $prefix,
                'profile_id' => $profileId,
            ];
            return [null, null, []];
        }

        return [$prefix, $profileId, $featureIds];
    }

    protected function normalizeSkuRule(string $value): string
    {
        $normalized = strtoupper(trim($value));
        if (strpos($normalized, ',') === false) {
            return $normalized;
        }

        $skuList = $this->parseSkuList($normalized);
        if (!$skuList) {
            return '';
        }

        return implode(',', $skuList);
    }

    protected function parseSkuList(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        $items = [];
        foreach ($parts as $part) {
            if ($part === '' || !preg_match('/^[A-Z0-9\-_]+$/', $part)) {
                return [];
            }
            $items[$part] = $part;
        }

        return array_values($items);
    }

    protected function getGroups(array $filters, int $offset, int $limit): array
    {
        $whereSql = '';
        $joins = ' INNER JOIN ' . _DB_PREFIX_ . 'po_link_profile p ON p.id_profile = g.id_profile';

        $total = (int) \Db::getInstance()->getValue('SELECT COUNT(DISTINCT g.id_group)
            FROM ' . _DB_PREFIX_ . 'po_link_group g' . $joins . $whereSql);

        $rows = \Db::getInstance()->executeS('SELECT g.id_group, g.id_profile, g.sku_prefix, g.updated_at, p.options_csv,
            COUNT(DISTINCT pf2.id_product) AS product_count
            FROM ' . _DB_PREFIX_ . 'po_link_group g' . $joins . '
            LEFT JOIN ' . _DB_PREFIX_ . 'po_link_product_family pf2 ON pf2.id_profile = g.id_profile AND pf2.family_key = g.sku_prefix' .
            $whereSql .
            ' GROUP BY g.id_group
              ORDER BY g.updated_at DESC
              LIMIT ' . (int) $offset . ', ' . (int) $limit) ?: [];

        $featureNames = $this->getFeatureOptions((int) $this->context->language->id);
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

    protected function getGroup(int $groupId): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT g.id_group, g.id_profile, g.sku_prefix, g.updated_at, p.options_csv
            FROM ' . _DB_PREFIX_ . 'po_link_group g
            INNER JOIN ' . _DB_PREFIX_ . 'po_link_profile p ON p.id_profile = g.id_profile
            WHERE g.id_group=' . (int) $groupId);

        if (!$row) {
            return null;
        }

        $features = $this->getFeatureOptions((int) $this->context->language->id);
        $ids = array_filter(array_map('intval', array_map('trim', explode(',', (string) $row['options_csv']))));
        $labels = [];
        foreach ($ids as $id) {
            $labels[] = $features[$id] ?? ('#' . $id);
        }
        $row['features_label'] = $labels ? implode(', ', $labels) : '-';

        return $row;
    }

    protected function buildQuery(array $filters): string
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

    protected function getGroupService(): \PoLinkedProductFeatures\Service\LinkedProductGroupService
    {
        return new \PoLinkedProductFeatures\Service\LinkedProductGroupService($this, $this->context);
    }

    protected function saveProfileFromRequest(): array
    {
        $db = \Db::getInstance();
        $profileId = (int) Tools::getValue('profile_id');
        $name = trim((string) Tools::getValue('profile_name'));
        $options = Tools::getValue('profile_options', []);
        $hiddenOptions = Tools::getValue('profile_hidden_options', []);
        $active = Tools::getValue('profile_active') ? 1 : 0;
        $showMuted = Tools::getValue('profile_show_muted') ? 1 : 0;

        if ($name === '') {
            throw new \RuntimeException($this->l('Nazwa profilu jest wymagana.'));
        }

        if (!is_array($options)) {
            $options = [];
        }

        $optionsCsv = $this->buildCsv($options);
        $optionIds = $this->parseCsvIds($optionsCsv);
        $hiddenOptionIds = array_values(array_intersect(
            $optionIds,
            $this->parseCsvIds($this->buildCsv(is_array($hiddenOptions) ? $hiddenOptions : []))
        ));

        if (count($optionIds) < 1 || count($optionIds) > 3) {
            throw new \RuntimeException($this->l('Wybierz od 1 do 3 cech w OPTIONS.'));
        }

        $data = [
            'name' => pSQL($name),
            'options_csv' => pSQL($optionsCsv),
            'hidden_options_csv' => pSQL(implode(',', $hiddenOptionIds)),
            'active' => (int) $active,
            'show_muted' => (int) $showMuted,
        ];

        $isNew = false;
        if ($profileId > 0) {
            $db->update('po_link_profile', $data, 'id_profile=' . (int) $profileId);
        } else {
            $db->insert('po_link_profile', $data);
            $profileId = (int) $db->Insert_ID();
            $isNew = true;
        }

        $db->delete('po_link_profile_label', 'id_profile=' . (int) $profileId);
        $labels = Tools::getValue('profile_label', []);
        if (is_array($labels)) {
            foreach ($labels as $featureId => $langs) {
                if (!is_array($langs)) {
                    continue;
                }
                foreach ($langs as $langId => $label) {
                    $label = trim((string) $label);
                    if ($label === '') {
                        continue;
                    }
                    $db->insert('po_link_profile_label', [
                        'id_profile' => (int) $profileId,
                        'id_feature' => (int) $featureId,
                        'id_lang' => (int) $langId,
                        'label' => pSQL($label),
                    ]);
                }
            }
        }

        return ['id' => $profileId, 'is_new' => $isNew];
    }

    protected function deleteProfile(int $profileId): void
    {
        if ($profileId <= 0) {
            return;
        }

        $db = \Db::getInstance();
        $db->delete('po_link_profile_label', 'id_profile=' . (int) $profileId);
        $db->delete('po_link_profile', 'id_profile=' . (int) $profileId);
        $db->delete('po_link_product_family', 'id_profile=' . (int) $profileId);
        $db->delete('po_link_index', 'id_profile=' . (int) $profileId);
        $db->delete('po_link_group', 'id_profile=' . (int) $profileId);
    }

    protected function getFeatureOptions(int $idLang): array
    {
        $options = [];
        foreach (\Feature::getFeatures($idLang) as $feature) {
            $id = (int) ($feature['id_feature'] ?? 0);
            $name = (string) ($feature['name'] ?? '');
            if ($id > 0 && $name !== '') {
                $options[$id] = $name;
            }
        }

        return $options;
    }

    protected function getProfileOptions(): array
    {
        return \Db::getInstance()->executeS('SELECT id_profile, name FROM ' . _DB_PREFIX_ . 'po_link_profile ORDER BY name ASC') ?: [];
    }

    protected function parseCsvIds(?string $csv): array
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

    protected function buildCsv(array $ids): string
    {
        $clean = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        })));

        return implode(',', $clean);
    }

    public function updateFeatureIndexForProduct(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }

        $db = \Db::getInstance();
        $assignment = $db->getRow('
            SELECT id_profile, family_key
            FROM ' . _DB_PREFIX_ . 'po_link_product_family
            WHERE id_product=' . (int) $productId
        );

        if (!$assignment) {
            $db->delete('po_link_index', 'id_product=' . (int) $productId);
            return;
        }

        $profile = $db->getRow('
            SELECT id_profile, options_csv
            FROM ' . _DB_PREFIX_ . 'po_link_profile
            WHERE id_profile=' . (int) $assignment['id_profile'] . ' AND active=1
        ');

        if (!$profile) {
            $db->delete('po_link_index', 'id_product=' . (int) $productId);
            return;
        }

        $optionIds = $this->parseCsvIds($profile['options_csv'] ?? '');
        if (!$optionIds) {
            $db->delete('po_link_index', 'id_product=' . (int) $productId);
            return;
        }

        $featureRows = $db->executeS(
            'SELECT id_feature, id_feature_value
             FROM ' . _DB_PREFIX_ . 'feature_product
             WHERE id_product=' . (int) $productId . '
               AND id_feature IN (' . implode(',', array_map('intval', $optionIds)) . ')'
        ) ?: [];

        $optionsMap = [];
        foreach ($featureRows as $row) {
            $optionsMap[(int) $row['id_feature']] = (int) $row['id_feature_value'];
        }

        $optionsJson = json_encode($optionsMap, JSON_UNESCAPED_UNICODE);
        if ($optionsJson === false) {
            $optionsJson = '{}';
        }

        $db->execute('REPLACE INTO ' . _DB_PREFIX_ . "po_link_index (id_product, id_profile, family_key, options_json)
            VALUES (" . (int) $productId . ", " . (int) $assignment['id_profile'] . ", '" . pSQL((string) $assignment['family_key']) . "', '" . pSQL($optionsJson) . "')");
    }

    public function rebuildFeatureIndex(): int
    {
        $db = \Db::getInstance();
        $rows = $db->executeS('SELECT id_product FROM ' . _DB_PREFIX_ . 'po_link_product_family') ?: [];
        $count = 0;
        foreach ($rows as $row) {
            $this->updateFeatureIndexForProduct((int) $row['id_product']);
            $count++;
        }

        return $count;
    }

    public function saveProductFamilyAssignmentFromRequest(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        $profileId = (int) Tools::getValue('po_link_profile_id');
        $familyKey = trim((string) Tools::getValue('po_link_family_key'));
        if (Tools::strlen($familyKey) > 64) {
            $familyKey = Tools::substr($familyKey, 0, 64);
        }

        $db = \Db::getInstance();

        if ($profileId > 0 && $familyKey !== '') {
            $db->execute('REPLACE INTO ' . _DB_PREFIX_ . "po_link_product_family (id_product, id_profile, family_key, updated_at)
                VALUES (" . (int) $productId . ", " . (int) $profileId . ", '" . pSQL($familyKey) . "', NOW())");
            $this->ensureGroupExists($profileId, $familyKey);
            $this->assignFamilyByReferencePrefix($profileId, $familyKey);
            return true;
        }

        $db->delete('po_link_product_family', 'id_product=' . (int) $productId);
        return false;
    }

    protected function assignFamilyByReferencePrefix(int $profileId, string $referencePrefix): void
    {
        if ($profileId <= 0 || $referencePrefix === '') {
            return;
        }

        $db = \Db::getInstance();
        $likePrefix = pSQL(addcslashes($referencePrefix, '%_'));
        $rows = $db->executeS('
            SELECT id_product
            FROM ' . _DB_PREFIX_ . 'product
            WHERE reference LIKE "' . $likePrefix . '%"
        ') ?: [];

        if (!$rows) {
            return;
        }

        $values = [];
        foreach ($rows as $row) {
            $values[] = '(' . (int) $row['id_product'] . ', ' . (int) $profileId . ", '" . pSQL($referencePrefix) . "', NOW())";
        }

        $chunks = array_chunk($values, 200);
        foreach ($chunks as $chunk) {
            $db->execute('REPLACE INTO ' . _DB_PREFIX_ . 'po_link_product_family (id_product, id_profile, family_key, updated_at) VALUES ' . implode(',', $chunk));
        }

        foreach ($rows as $row) {
            $this->updateFeatureIndexForProduct((int) $row['id_product']);
        }

        $this->touchGroupUpdatedAt($profileId, $referencePrefix);
    }

    protected function ensureGroupExists(int $profileId, string $referencePrefix): void
    {
        if ($profileId <= 0 || $referencePrefix === '') {
            return;
        }

        $db = \Db::getInstance();
        $db->execute('INSERT IGNORE INTO ' . _DB_PREFIX_ . "po_link_group (id_profile, sku_prefix, created_at, updated_at)
            VALUES (" . (int) $profileId . ", '" . pSQL($referencePrefix) . "', NOW(), NOW())");
    }

    protected function touchGroupUpdatedAt(int $profileId, string $referencePrefix): void
    {
        if ($profileId <= 0 || $referencePrefix === '') {
            return;
        }

        $db = \Db::getInstance();
        $db->execute('UPDATE ' . _DB_PREFIX_ . "po_link_group
            SET updated_at = NOW()
            WHERE id_profile=" . (int) $profileId . " AND sku_prefix='" . pSQL($referencePrefix) . "'");
    }
}
