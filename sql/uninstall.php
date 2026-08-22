<?php

/* Approval/execution logs and conversation memory are retained as audit history. */

return Db::getInstance()->execute(
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'aibridge_upload`'
) && Db::getInstance()->execute(
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'aibridge_employee_token`'
);