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
?>
<li>
  <a href="plugin.php?plugin=GoogleCalendarScheduler&page=content.php">
    Calendar Scheduler
  </a>
</li>
