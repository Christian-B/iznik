<?php
const MODTOOLS = FALSE;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/Notifications.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

$l = new Notifications($dbhr, $dbhm);
$g = Group::get($dbhr, $dbhm);

$gid = $g->findByShortName('EdinburghFreegle');
#$l->notifyGroupMods($gid);
$l->notify(1093072,"Title", "Message");