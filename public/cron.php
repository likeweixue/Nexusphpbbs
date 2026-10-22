<?php
require_once(__DIR__ . "/../include/bittorrent.php");
dbconn();
if ($useCronTriggerCleanUp) {
	$return = autoclean();
	if ($return) {
		echo $return."\n";
	} else {
		echo "Clean-up not triggered.\n";
	}
} else {
	echo "Forbidden. Clean-up is set to be browser-triggered.\n";
}
