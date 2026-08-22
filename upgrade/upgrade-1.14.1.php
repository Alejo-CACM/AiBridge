<?php

function upgrade_module_1_14_1($module)
{
    // No schema changes: fixes a double UTF-8 encoding bug in the Back
    // Office configuration screen text ("ConexiÃƒÂ³n" -> "Conexión").
    return true;
}
