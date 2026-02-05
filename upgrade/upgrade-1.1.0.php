<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0($module)
{
    $sql = [];
    $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'po_link_group` (
        `id_group` INT(11) NOT NULL AUTO_INCREMENT,
        `id_profile` INT(11) NOT NULL,
        `sku_prefix` VARCHAR(64) NOT NULL,
        `feature_values_json` TEXT NULL,
        `created_at` DATETIME NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id_group`),
        UNIQUE KEY `uniq_profile_prefix` (`id_profile`, `sku_prefix`),
        KEY `idx_prefix` (`sku_prefix`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

    foreach ($sql as $query) {
        if (Db::getInstance()->execute($query) == false) {
            return false;
        }
    }

    Db::getInstance()->execute('INSERT IGNORE INTO ' . _DB_PREFIX_ . 'po_link_group (id_profile, sku_prefix, created_at, updated_at)
        SELECT DISTINCT id_profile, family_key, NOW(), NOW()
        FROM ' . _DB_PREFIX_ . 'po_link_product_family');

    if (method_exists($module, 'installTab')) {
        $module->installTab();
    }

    return true;
}
