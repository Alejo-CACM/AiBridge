<?php

function upgrade_module_1_16_0($module)
{
    $tabInstalled = true;

    if (!(int) Tab::getIdFromClassName('AdminAiBridgeChat')) {
        $parentId = (int) Tab::getIdFromClassName('AdminParentModulesSf');

        if ($parentId) {
            $tab = new Tab();
            $tab->class_name = 'AdminAiBridgeChat';
            $tab->module = $module->name;
            $tab->id_parent = $parentId;

            foreach (Language::getLanguages(false) as $language) {
                $tab->name[(int) $language['id_lang']] = 'AI Bridge Chat';
            }

            $tabInstalled = (bool) $tab->add();
        } else {
            $tabInstalled = false;
        }
    }

    return $tabInstalled && $module->registerHook('displayBackOfficeHeader');
}
