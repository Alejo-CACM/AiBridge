<?php

function upgrade_module_1_17_1($module)
{
    // No schema changes: adds a cache-busting ?v= query string to the chat
    // widget's CSS/JS asset URLs so browsers pick up updates immediately
    // instead of serving a stale cached copy.
    return true;
}
