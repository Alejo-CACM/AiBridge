<?php

function upgrade_module_1_14_2($module)
{
    // No schema changes: fixes AiBridgeSelfUpdater incorrectly treating
    // Tools::recurseCopy()'s always-null return value as failure, which
    // blocked every self-update at the backup step.
    return true;
}
