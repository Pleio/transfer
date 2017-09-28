<?php

class TransferFile {
    static $handle;

    function __construct($export, $filename) {
        $this->handle = fopen("{$export->path}/{$filename}", "w");
        $this->export = $export;
    }

    public function writeEntity($entity) {
        $class = get_class($entity);

        switch ($class) {
            case "ElggUser":
                return $this->writeUser($entity);
            case "ElggGroup":
                return $this->writeGroup($entity);
            default:
                return $this->writeObject($entity);
        }
    }

    public function writeUser($entity) {
        $fields = ["guid", "username", "name", "email", "time_created", "time_updated"];
        $data = $this->getData($entity, $fields);

        return $this->writeJson($data);
    }

    public function writeGroup($entity) {
        $fields = ["guid", "owner_guid", "name", "description", "time_created", "time_updated"];
        $data = $this->getData($entity, $fields);

        $data["members"] = $this->getRelationshipGuids($entity, "member");
        $data["admins"] = $this->getRelationshipGuids($entity, "group_admin");

        $this->export->addUserGuid($entity->owner_guid);

        foreach ($data["members"] as $member) {
            $this->export->addUserGuid($member);
        }

        foreach ($data["admins"] as $admin) {
            $this->export->addUserGuid($admin);
        }

        return $this->writeJson($data);
    }

    public function writeObject($entity) {
        $fields = ["guid", "owner_guid", "container_guid", "type", "title", "description", "time_created", "time_updated"];
        $data = $this->getData($entity, $fields);
        $data["subtype"] = $entity->getSubtype();

        switch ($entity->getSubtype()) {
            case "blog":
            case "news":
            case "question":
            case "discussion":
                $comments = $this->getComments($entity);
                break;
            case "file":
                $data["filename"] = $entity->getFilenameOnFilestore();
                break;
            default:
                $ignore = true;
        }

        if (!$ignore) {
            $this->export->addUserGuid($entity->owner_guid);
            return $this->writeJson($data);
        }
    }

    private function getData($entity, $fields) {
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $entity->$field;
        }

        return $data;
    }

    private function getRelationshipGuids(ElggEntity $entity, $relationship) {
        $guid = sanitise_int($entity->guid);
        $relationship = sanitise_string($relationship);

        $results = get_data("SELECT r.guid_one FROM elgg_entity_relationships r WHERE relationship = '{$relationship}' AND r.guid_two = {$guid}");

        $guids = [];
        foreach ($results as $result) {
            $guids[] = $result->guid_one;
        }

        return $guids;
    }

    private function getComments(ElggEntity $entity) {
        $fields = ["guid", "owner_guid", "description", "time_created", "time_updated"];

        $comments = [];

        $subtypes = [];
        foreach (["comment", "answer"] as $subtype) {
            $subtype = get_subtype_id("object", $subtype);

            if ($subtype) {
                $subtypes[] = $subtype;
            }
        }

        $subtypes = implode(",", $subtypes);

        $results = get_data("SELECT guid FROM elgg_entities WHERE container_guid = {$entity->guid} AND subtype IN ({$subtypes})");
        foreach ($results as $result) {
            $entity = get_entity($result->guid);
            $this->export->addUserGuid($entity->owner_guid);
            $comments[] = $this->getData($comment, $fields);
        }

        return $comments;
    }

    private function writeJson($data) {
        return fwrite($this->handle, json_encode($data) . "\r\n");
    }

    public function close() {
        fclose($this->handle);
    }
}