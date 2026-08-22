<?php

function upgrade_module_1_13_1($module)
{
    // No schema changes: adds the self-update panel (Back Office ->
    // AI Bridge -> Configure) and AiBridgeSelfUpdater. The manifest URL
    // config key is read with a default fallback, no pre-population needed.
    return true;
}
