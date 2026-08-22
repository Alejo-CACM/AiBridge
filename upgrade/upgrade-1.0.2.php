<?php

function upgrade_module_1_0_2($module)
{
    if ((int) Tab::getIdFromClassName('AdminAiBridgeApprovals')) {
        return true;
    }

    $parentId = (int) Tab::getIdFromClassName('AdminParentModulesSf');

    if (!$parentId) {
        return false;
    }

    $tab = new Tab();
    $tab->class_name = 'AdminAiBridgeApprovals';
    $tab->module = $module->name;
    $tab->id_parent = $parentId;

    foreach (Language::getLanguages(false) as $language) {
        $tab->name[(int) $language['id_lang']] = 'AI Bridge Approvals';
    }

    return $tab->add();
}