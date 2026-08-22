<?php

function upgrade_module_1_14_3($module)
{
    // No schema changes: AiBridgeSelfUpdater now surfaces the real PHP
    // warnings and a filesystem diagnostic when a directory copy fails
    // during self-update, instead of a generic "backup failed" message.
    return true;
}
