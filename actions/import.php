<?php
set_time_limit(0);

$id = get_input("id");

if (!$id) {
    forward(REFERER);
}

$import = new TransferImport($id);
$import->start();

system_message(elgg_echo("transfer:import:done"));