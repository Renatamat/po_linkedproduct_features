<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_2($module)
{
    $db = Db::getInstance();

    $column = $db->getRow('SHOW COLUMNS FROM `' . _DB_PREFIX_ . "po_link_profile` LIKE 'hidden_options_csv'");
    if (!$column) {
        if (!$db->execute('ALTER TABLE `' . _DB_PREFIX_ . 'po_link_profile` ADD `hidden_options_csv` VARCHAR(64) NOT NULL DEFAULT "" AFTER `options_csv`')) {
            return false;
        }
    }

    return true;
}
