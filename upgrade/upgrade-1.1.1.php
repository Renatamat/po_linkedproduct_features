<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_1($module)
{
    $db = Db::getInstance();

    $column = $db->getRow('SHOW COLUMNS FROM `' . _DB_PREFIX_ . "po_link_profile` LIKE 'show_muted'");
    if (!$column) {
        if (!$db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'po_link_profile` ADD `show_muted` TINYINT(1) NOT NULL DEFAULT 1')) {
            return false;
        }
    }

    return true;
}
