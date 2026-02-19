<?php
/**
 * Calendar Scheduler — Plugin Menu Entry
 *
 * File: menu.inc
 * Purpose: Register the Calendar Scheduler navigation link in FPP's Content
 * Setup menu when the active menu context is `content`.
 */

if ($menu !== 'content') {
    return;
}

$pluginRootName = basename(__DIR__);
$canonicalPluginName = 'CalendarScheduler';
$legacyPluginName = 'GoogleCalendarScheduler';
$pluginParentDir = dirname(__DIR__);

// During rename migration, suppress duplicate menu entries from legacy alias.
if (
    $pluginRootName === $legacyPluginName &&
    is_dir($pluginParentDir . DIRECTORY_SEPARATOR . $canonicalPluginName)
) {
    return;
}

$pluginName = $canonicalPluginName;
if (
    !is_dir($pluginParentDir . DIRECTORY_SEPARATOR . $canonicalPluginName) &&
    $pluginRootName === $legacyPluginName
) {
    $pluginName = $legacyPluginName;
}
?>
<li>
  <a href="plugin.php?plugin=<?php echo rawurlencode($pluginName); ?>&page=content.php">
    Calendar Scheduler
  </a>
</li>
