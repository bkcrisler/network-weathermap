<?php

class WeathermapManagedMap
{
    public $sortorder;
    public $group_id;
    public $active;
    public $configfile;
    public $imagefile;
    public $htmlfile;
    public $titlecache;
    public $filehash;
    public $warncount;
    public $config;
    public $thumb_width;
    public $thumb_height;
    public $schedule;
    public $archiving;
}

class WeathermapManager
{

    var $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function getMap($mapId)
    {
        $statement = $this->pdo->prepare("SELECT * FROM weathermap_maps WHERE id=?");
        $statement->execute(array($mapId));
        $map = $statement->fetch(PDO::FETCH_OBJ);

        return $map;
    }

    public function getGroup($groupId)
    {
        $statement = $this->pdo->prepare("SELECT * FROM weathermap_groups WHERE id=?");
        $statement->execute(array($groupId));
        $group = $statement->fetch(PDO::FETCH_OBJ);

        return $group;
    }

    private function make_set($data, $allowed)
    {
        $values = array();
        $set = "";
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $set .= "`" . str_replace("`", "``", $field) . "`" . "=:$field, ";
                $values[$field] = $data[$field];
            }
        }
        $set = substr($set, 0, -2);

        return array($set, $values);
    }

    public function updateMap($mapId, $data)
    {
        // $data = ['name' => 'foo','submit' => 'submit']; // data for insert
        $allowed = ["active", "sortorder", "group_id"]; // allowed fields
        list($set, $values) = $this->make_set($data, $allowed);

        $values['id'] = $mapId;

        $stmt = $this->pdo->prepare("UPDATE weathermap_maps SET $set where id=:id");
        $stmt->execute($values);
    }

    public function activateMap($mapId)
    {
        $this->updateMap($mapId, array('active' => 'on'));
    }

    public function disableMap($mapId)
    {
        $this->updateMap($mapId, array('active' => 'off'));
    }

    public function setMapGroup($mapId, $groupId)
    {
        $this->updateMap($mapId, array('group_id' => $groupId));
        $this->resortMaps();
    }

    public function deleteMap($id)
    {
        $this->pdo->prepare("DELETE FROM weathermap_maps WHERE id=?")->execute(array($id));
        $this->pdo->prepare("DELETE FROM weathermap_auth WHERE mapid=?")->execute(array($id));
        $this->pdo->prepare("DELETE FROM weathermap_settings WHERE mapid=?")->execute(array($id));

        $this->resortMaps();
    }

    public function addPermission($mapId, $userId)
    {
        $this->pdo->prepare("INSERT INTO weathermap_auth (mapid,userid) VALUES(?,?)")->execute(array($mapId, $userId));
    }

    public function removePermission($mapId, $userId)
    {
        $this->pdo->prepare("DELETE FROM weathermap_auth WHERE mapid=? AND userid=?")->execute(array($mapId, $userId));
    }

    // Repair the sort order column (for when something is deleted or inserted, or moved between groups)
    // our primary concern is to make the sort order consistent, rather than any special 'correctness'
    public function resortMaps()
    {
        $stmt = $this->pdo->query('SELECT * FROM weathermap_maps ORDER BY group_id,sortorder;');

        $newMapOrder = array();

        $i = 1;
        $lastGroupSeen = -1020.5;
        foreach ($stmt as $map) {
            if ($lastGroupSeen != $map['group_id']) {
                $lastGroupSeen = $map['group_id'];
                $i = 1;
            }
            $newMapOrder[$map['id']] = $i;
            $i++;
        }

        $statement = $this->pdo->prepare("UPDATE weathermap_maps SET sortorder=? WHERE id=?");

        if (!empty($newMapOrder)) {
            foreach ($newMapOrder as $mapId => $sortOrder) {
                $statement->execute(array($sortOrder, $mapId));
            }
        }

    }

    public function moveMap($mapId, $direction)
    {
        $source = $this->pdo->prepare('SELECT * FROM weathermap_maps WHERE id=?;')->execute(array($mapId));

//        $source = db_fetch_assoc("select * from weathermap_maps where id=$mapId");
        $oldOrder = $source[0]['sortorder'];
        $group = $source[0]['group_id'];

        $newOrder = $oldOrder + $direction;
        $target = $this->pdo->prepare("SELECT * FROM weathermap_maps WHERE group_id=? AND sortorder =?")->execute(array($group, $newOrder));
//        $target = db_fetch_assoc("select * from weathermap_maps where group_id=$group and sortorder = $newOrder");

        if (!empty($target[0]['id'])) {
            $otherId = $target[0]['id'];
            // move $mapid in direction $direction
            $this->pdo->prepare("UPDATE weathermap_maps SET sortorder =? WHERE id=?")->execute(array($newOrder, $mapId));
//            $sql[] = "update weathermap_maps set sortorder = $newOrder where id=$mapId";
            // then find the other one with the same sortorder and move that in the opposite direction
            $this->pdo->prepare("UPDATE weathermap_maps SET sortorder =? WHERE id=?")->execute(array($oldOrder, $otherId));
//            $sql[] = "update weathermap_maps set sortorder = $oldOrder where id=$otherId";
        }

    }

    public function moveGroup($groupId, $direction)
    {

    }

    public function resortGroups()
    {
        $stmt = $this->pdo->query('SELECT * FROM weathermap_groups ORDER BY sortorder;');

        $newGroupOrder = array();

        $i = 1;
        foreach ($stmt as $map) {
            $newGroupOrder[$map['id']] = $i;
            $i++;
        }
        $statement = $this->pdo->prepare("UPDATE weathermap_groups SET sortorder=? WHERE id=?");

        if (!empty($newGroupOrder)) {
            foreach ($newGroupOrder as $mapId => $sortOrder) {
                $statement->execute(array($sortOrder, $mapId));
            }
        }
    }

    public function mapSettingSave($mapId, $name, $value)
    {
        if ($mapId > 0) {
            // map setting
            $data = array("id" => $mapId, "name" => $name, "value" => $value);
            $statement = $this->pdo->prepare("REPLACE INTO weathermap_settings (mapid, optname, optvalue) VALUES (:id, :name, :value)");
            $statement->execute($data);
        } elseif ($mapId < 0) {
            // group setting
            $data = array("groupid" => -$mapId, "name" => $name, "value" => $value);
            $statement = $this->pdo->prepare("REPLACE INTO weathermap_settings (mapid, groupid, optname, optvalue) VALUES (0, :groupid,  :name, :value)");
            $statement->execute($data);
        } else {
            // Global setting
            $data = array("name" => $name, "value" => $value);
            $statement = $this->pdo->prepare("REPLACE INTO weathermap_settings (mapid, groupid, optname, optvalue) VALUES (0, 0,  :name, :value)");
            $statement->execute($data);
        }
    }

    public function mapSettingUpdate($settingId, $name, $value)
    {
        $data = array("optname" => $name, "optvalue" => $value);

        $allowed = ["optname", "optvalue"]; // allowed fields
        list($set, $values) = $this->make_set($data, $allowed);

        $values['id'] = $settingId;

        $stmt = $this->pdo->prepare("UPDATE weathermap_settings SET $set where id=:id");
        $stmt->execute($values);
    }

    public function mapSettingDelete($mapId, $settingId)
    {
        $this->pdo->prepare("DELETE FROM weathermap_settings WHERE id=? AND mapid=?")->execute(array($settingId, $mapId));
    }

    public function createGroup($groupName)
    {
        $sortOrder = $this->pdo->query("SELECT max(sortorder)+1 AS next_id FROM weathermap_groups")->fetchColumn();
        $this->pdo->prepare("INSERT INTO weathermap_groups(name, sortorder) VALUES(?,?)")->execute(array($groupName, $sortOrder));
    }

    public function deleteGroup($groupId)
    {
        $statement = $this->pdo->prepare("SELECT MIN(id) as first_group FROM weathermap_groups WHERE id <> ?");
        $statement->execute(array($groupId));
        $newId = $statement->fetchColumn();

        # move any maps out of this group into a still-existing one
        $this->pdo->prepare("UPDATE weathermap_maps set group_id=? where group_id=?")->execute(array($newId, $groupId));

        # then delete the group
        $this->pdo->prepare("DELETE FROM weathermap_groups WHERE id=?")->execute(array($groupId));

        # Finally, resort, just in case
        $this->resortGroups();
      }

    public function renameGroup($groupId, $newName)
    {
        $this->pdo->prepare("UPDATE weathermap_groups SET name=? WHERE id=?")->execute(array($newName, $groupId));
    }

}
