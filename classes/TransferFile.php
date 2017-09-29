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

        if (elgg_is_active_plugin("subsite_manager")) {
            $data["pleio_guid"] = $entity->guid;
        }

        return $this->writeJson($data);
    }

    public function writeGroup($entity) {
        $fields = ["guid", "owner_guid", "name", "description", "time_created", "time_updated"];
        $data = $this->getData($entity, $fields);

        $data["members"] = $this->getRelationshipGuids($entity, "member");
        $data["admins"] = $this->getRelationshipGuids($entity, "group_admin");
        $data["is_open"] = $entity->membership === ACCESS_PUBLIC ? 1 : 0;

        $this->export->addUserGuid($entity->owner_guid);

        foreach ($data["members"] as $member) {
            $this->export->addUserGuid($member);
        }

        foreach ($data["admins"] as $admin) {
            $this->export->addUserGuid($admin);
        }

        $this->copyIcons("groups", $entity);

        return $this->writeJson($data);
    }

    public function writeObject($entity) {
        $fields = ["guid", "owner_guid", "container_guid", "type", "title", "description", "time_created", "time_updated", "tags"];
        $data = $this->getData($entity, $fields);
        $data["subtype"] = $entity->getSubtype();

        switch ($entity->getSubtype()) {
            case "blog":
                $this->copyIcons("blogs", $entity);
                break;
            case "groupforumtopic":
                $data["subtype"] = "discussion";
                $data["comments"] = $this->getComments($entity);
                break;
            case "news":
            case "question":
            case "discussion":
                $data["comments"] = $this->getComments($entity);
                break;
            case "file":
                $data["filename"] = $entity->getFilename();
                $data["originalfilename"] = $entity->originalfilename;
                $data["parent_guid"] = $this->getFolderGuid($entity);
                $data["mimetype"] = $entity->mimetype;
                $data["simpletype"] = $entity->simpletype;
                $data["comments"] = $this->getComments($entity);
                $this->copyIcons("file", $entity);
                $this->copyFile($entity);
                break;
            case "folder":
                $data["parent_guid"] = $this->getFolderGuid($entity);
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

    private function getFolderGuid($entity) {
        $guid = (int) $entity->guid;

        switch ($entity->getSubtype()) {
            case "file":
                $row = get_data_row("SELECT r.guid_one FROM elgg_entity_relationships r WHERE
                relationship = 'folder_of' AND r.guid_two = $guid");
                return (int) $row->guid_one ? $row->guid_one : 0;
            case "folder":
                return (int) $entity->parent_guid ? $entity->parent_guid : 0;            
        }
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

        if ($entity->getSubtype() == "file") {
            foreach ($entity->getAnnotations("generic_comment", 0, 0) as $annotation) {
                $comments[] = [
                    "guid" => "annotation:{$annotation->id}",
                    "owner_guid" => $annotation->owner_guid,
                    "description" => $annotation->value,
                    "time_created" => $annotation->time_created,
                    "time_updated" => $annotation->time_created
                ];

                $this->export->addUserGuid($annotation->owner_guid);
            }
        } elseif ($entity->getSubtype() == "groupforumtopic") {
            foreach ($entity->getAnnotations("group_topic_post", 0, 0) as $annotation) {
                $comments[] = [
                    "guid" => "annotation:{$annotation->id}",
                    "owner_guid" => $annotation->owner_guid,
                    "description" => $annotation->value,
                    "time_created" => $annotation->time_created,
                    "time_updated" => $annotation->time_created
                ];

                $this->export->addUserGuid($annotation->owner_guid);
            }
        } else {
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
                $comments[] = $this->getData($entity, $fields);

                $this->export->addUserGuid($entity->owner_guid);
            }
        }

        return $comments;
    }

    private function writeJson($data) {
        return fwrite($this->handle, json_encode($data) . "\r\n");
    }

    private function copyIcons($namespace, $entity) {
        $filehandler = new ElggFile();
        $filehandler->owner_guid = $entity->owner_guid;

        foreach (['large', 'medium', 'small', 'tiny', 'master', 'topbar'] as $size) {
            $filehandler->setFilename("{$namespace}/{$entity->guid}{$size}.jpg");
            if (!$filehandler->open("read")) {
                continue;
            }
            
            copy($filehandler->getFilenameOnFilestore(), "{$this->export->path}/icons/{$entity->guid}{$size}.jpg");
        }
    }

    public function copyFile($entity) {
        if (!$entity->open("read")) {
            return;
        }

        $info = pathinfo($entity->getFilenameOnFilestore());
        copy($entity->getFilenameOnFilestore(), "{$this->export->path}/files/{$entity->guid}.{$info['extension']}");
    }

    public function close() {
        fclose($this->handle);
    }
}