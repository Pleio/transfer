<?php
global $CONFIG;

set_time_limit(0);

$id = get_input("id");

if (!$id) {
    forward(REFERER);
}

// do not send any mail during import
$CONFIG->block_mail = true;

$import = new TransferImport($id);
$import->start();

system_message(elgg_echo("transfer:import:done"));