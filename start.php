<?php
elgg_register_event_handler('init', 'system', 'transfer_init');

function transfer_init() {
    elgg_register_admin_menu_item('administer', 'transfer', 'administer_utilities');

    elgg_register_action('transfer/export', dirname(__FILE__) . '/actions/export.php', 'admin');
    elgg_register_action('transfer/import', dirname(__FILE__) . '/actions/import.php', 'admin');

}

function transfer_get_groups() {
    $groups = [];
    $sql = "SELECT guid, name FROM elgg_groups_entity limit 10000";
    $rows = get_data($sql);
    foreach ($rows as $group) {
        $groups[$group->name] = $group->guid;
    }

    return $groups;
}

function transfer_get_exports() {
    $site = elgg_get_site_entity();
    $parameters = get_default_filestore()->getParameters();

    $path = "{$parameters['dir_root']}export/{$site->guid}/*";

    $exports = [];
    foreach (glob($path, GLOB_ONLYDIR) as $dir) {
        $exports[] = basename($dir);
    }

    return $exports;
}

function transfer_get_imports() {
    $site = elgg_get_site_entity();
    $parameters = get_default_filestore()->getParameters();

    $path = "{$parameters['dir_root']}import/{$site->guid}/*";

    $exports = [];
    foreach (glob($path, GLOB_ONLYDIR) as $dir) {
        $exports[] = basename($dir);
    }

    return $exports;
}