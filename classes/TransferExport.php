<?php

class TransferExport {
    function __construct() {
        $this->path = $this->generateExportPath();
        if (!file_exists($this->path)) {
            mkdir($this->path, 0750, true);
        }

        $this->user_guids = [];
        $this->group_guids = [];
    }

    private function generateExportPath() {
        $uuid = date("Ymd-His");
        $site = elgg_get_site_entity();
        $parameters = get_default_filestore()->getParameters();
        return "{$parameters['dir_root']}export/{$site->guid}/{$uuid}/";
    }

    function addGroup(ElggGroup $group) {
        $this->group_guids[] = $group->guid;

        $file = new TransferFile($this, "content_{$group->guid}.json");

        $rows = get_data("SELECT guid FROM elgg_entities WHERE container_guid = {$group->guid}");
        foreach ($rows as $row) {
            $entity = get_entity($row->guid);
            $file->writeEntity($entity);
        }

        $file->close();
    }

    function addUserGuid($user_guid) {
        if (!in_array($user_guid, $this->user_guids)) {
            $this->user_guids[] = $user_guid;
        }
    }

    function finish() {
        $file = new TransferFile($this, "groups.json");

        foreach ($this->group_guids as $guid) {
            $file->writeEntity(get_entity($guid));
        }

        $file->close();

        $file = new TransferFile($this, "users.json");

        foreach ($this->user_guids as $guid) {
            $file->writeEntity(get_entity($guid));
        }

        $file->close();
    }
}