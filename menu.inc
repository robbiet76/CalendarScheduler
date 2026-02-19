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
$pluginName = 'CalendarScheduler';
?>
<li>
  <a href="plugin.php?plugin=<?php echo rawurlencode($pluginName); ?>&page=content.php">
    Calendar Scheduler
  </a>
</li>
