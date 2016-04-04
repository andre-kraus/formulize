<?php

include_once '../class/forms.php';

class SyncCompareCatalog {

    // connection to the DB
    private $db = null;

    // $metadata from xoops_versions file
    private $metadata = null;

    /*
     * tableName :
     *      createTable: TRUE/FALSE
     *      fields: [...]   # list of fields in this table
     *      inserts: [
     *          [..] // contains entire record
     *      ]
     *      updates: [
     *          [..] // contains entire record
     *      ]
     *      deletes:  [
     *          [..] // contains entire record
     *      ]
     */
    private $changes = array();

    // array to keep track of which records have been seen, used for detecting deletions
    /*
     * tableName: [
     *      rec1_prim_key_val,
     *      rec2_prim_key_val,
     *      ...
     * ]
     */
    private $rec_track = array();

    function __construct() {
        // open a connection to the database
        $this->db = new \PDO('mysql'.':host='.XOOPS_DB_HOST.';dbname='.XOOPS_DB_NAME, XOOPS_DB_USER, XOOPS_DB_PASS);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // pull metadata from xoops_version file
        $module_handler = xoops_gethandler('module');
        $formulizeModule = $module_handler->getByDirname("formulize");
        $this->metadata = $formulizeModule->getInfo();
    }

    function __destruct() {
        // explicitly null some variables so they are definitely garbage collected
        $this->db = null;
        $this->metadata = null;
    }

    // === PUBLIC FUNCTIONS ===

    public function addTable($tableName, $fields) {
        if (!isset($this->changes[$tableName])) {
            $tableExists = $this->tableExists($tableName);
            $this->changes[$tableName] = array("fields" => $fields, "inserts" => array(),
                "updates" => array(), "deletes" => array(), "createTable" => !$tableExists);
        }
    }

    public function addRecord($tableName, $record, $fields) {
        // there should be one record value for each field string
        if (count($record) != count($fields)) {
            throw new Exception("compare(...) requires record and fields to have the same number of values");
        }

        $primaryField = $this->getPrimaryField($tableName);
        $recPrimaryValue = $record[array_search($primaryField, $fields)];

        // track the record
        $rec_track_table = &$this->rec_track[$tableName];
        if (!$rec_track_table)  // create table array if it doesn't exist
            $this->rec_track[$tableName] = array();
        $rec_track_table[] = (string)$recPrimaryValue;

        // check db to see if the record exists
        $dbRecord = $this->getRecord($tableName, $primaryField, $recPrimaryValue);
        if (!$dbRecord) {
            $this->addRecChange("insert", $tableName, $fields, $record);
        }
        else {  // compare the data values, add any update statement to $compareResults
            // compare each record field for changes
            $isChanged = FALSE;
            for ($i = 0; $i < count($record); $i++) {
                $field = $fields[$i];
                $value = $record[$i];
                $dbValue = (string)$dbRecord[$field];
                if ($dbValue != $value) {
                    $isChanged = TRUE;
                }
            }
            if ($isChanged) {
                $this->addRecChange("update", $tableName, $fields, $record);
            }
        }
    }

    public function detectDeletions($tableName) {
        if (!$this->tableExists($tableName))return; // no deletions possible if table doesn't exist here

        $rec_track_table = &$this->rec_track[$tableName];
        $primaryField = $this->getPrimaryField($tableName);

        // get list of primary keys from db table
        if ($tableName == "group_permission") { // special case where we only want formulize module records
            $sql = "SELECT ".$primaryField." FROM ".prefixTable($tableName)." WHERE gperm_modid=".getFormulizeModId().";";
        }
        else { // default case of all records from table
            $sql = "SELECT ".$primaryField." FROM ".prefixTable($tableName).";";
        }
        $result = $this->db->query($sql);
        $primary_keys = $result->fetchAll(PDO::FETCH_COLUMN, 0); // fetch flat array only

        // if a primary key is in the db but not in rec_track then it was deleted
        foreach ($primary_keys as $value) {
            if (!in_array((string)$value, $rec_track_table)) { // was deleted
                $deleted_rec = $this->getRecord($tableName, $primaryField, $value);
                $this->changes[$tableName]["deletes"][] = $deleted_rec;
                $this->changes[$tableName]["deletes"][] = $deleted_rec;
            }
        }
    }

    public function getChanges() {
        return $this->changes;
    }

    // returns the record descriptions by table and by updates/inserts/deletes
    public function getChangeDescrs() {
        $descrs = array();

        foreach ($this->changes as $tableName => $tableInfo) {
            if (count($tableInfo["inserts"]) == 0
                && count($tableInfo["updates"]) == 0
                && count($tableInfo["deletes"]) == 0
                && $tableInfo["createTable"] == FALSE) {
                continue; // if no changes in this table, and not a new table then dont bother
            }

            $descrs[$tableName] = array("inserts"=>array(), "updates"=>array(), "deletes"=>array());
            $descrs[$tableName]["createTable"] = $tableInfo["createTable"];

            foreach ($tableInfo["inserts"] as $rec) {
                $metadata = $this->getRecMetadata($tableName, "insert", $rec);
                $metadata = array_filter($metadata); // remove any empty values
                $descrs[$tableName]["inserts"][] = implode(" / ", $metadata);
            }

            foreach ($tableInfo["updates"] as $rec) {
                $metadata = $this->getRecMetadata($tableName, "update", $rec);
                $metadata = array_filter($metadata); // remove any empty values
                $descrs[$tableName]["updates"][] = implode(" / ", $metadata);
            }

            foreach ($tableInfo["deletes"] as $rec) {
                $metadata = $this->getRecMetadata($tableName, "delete", $rec);
                $metadata = array_filter($metadata); // remove any empty values
                $descrs[$tableName]["deletes"][] = implode(" / ", $metadata);
            }
        }
        return $descrs;
    }

    public function cacheChanges() {
        $sessVarName = "sync-changes-" .  session_id() . ".cache";
        cacheVar($this->changes, $sessVarName);
    }

    public function loadCachedChanges() {
        // TODO - if loaded changes was successful but an empty array then this returns false
        //           and a "no import data" error is displayed on UI...
        $sessVarName = "sync-changes-" .  session_id() . ".cache";
        $this->changes = loadCachedVar($sessVarName);
        return boolval($this->changes);
    }

    public function commitChanges() {
        $numSuccess = 0;
        $numFail = 0;

        // iterate through, commit all inserts that are not on new tables
        foreach ($this->changes as $tableName => $tableData) {
            $fields = $tableData["fields"];
            if ($tableData["createTable"] == FALSE) {
                foreach ($tableData["inserts"] as $rec) {
                    ($this->commitInsert($tableName, $rec, $fields)) ? $numSuccess++ : $numFail++;
                }
            }
        }

        // now commit all updates not on new tables
        foreach ($this->changes as $tableName => $tableData) {
            $fields = $tableData["fields"];
            if ($tableData["createTable"] == FALSE) {
                foreach ($tableData["updates"] as $rec) {
                    ($this->commitUpdate($tableName, $rec)) ? $numSuccess++ : $numFail++;
                }
            }
        }

        // now create all the data tables and insert the new records into them
        foreach ($this->changes as $tableName => $tableData) {
            $fields = $tableData["fields"];
            if ($tableData["createTable"] == TRUE) {
                ($this->commitCreateTable($tableName)) ? $numSuccess++ : $numFail++;

                // now insert all records that go into this table
                foreach ($tableData["inserts"] as $rec) {
                    ($this->commitInsert($tableName, $rec, $fields)) ? $numSuccess++ : $numFail++;
                }
            }
        }

        return array("success"=>$numSuccess, "fail"=>$numFail);
    }

    // === PRIVATE FUNCTIONS ===

    private function addRecChange($type, $tableName, $fields, $record) {
        if ($type !== "insert" && $type !== "update" && $type !== "delete") {
            throw new Exception("SyncCompareCatalog::addRecChange() only supports 'insert'/'update' change types.");
        }

        // convert record to associative array
        $data = $this->convertRec($record, $fields);

        // simple modification of change type for indexing into the $changes table data structure
        $typeArrayName = $type.'s';

        // now add record to the correct list
        $changeTypeList = &$this->changes[$tableName][$typeArrayName];
        array_push($changeTypeList, $data);
    }

    private function tableExists($tableName) {
        $result = $this->db->query('SHOW TABLES LIKE "'.prefixTable($tableName).'";');
        $tableExists = $result->rowCount() > 0;
        return $tableExists;
    }

    private function getRecord($tableName, $primaryField, $primaryValue) {
        $result = $this->db->query('SELECT * FROM '.prefixTable($tableName).' WHERE '.$primaryField.' = "'.$primaryValue.'";')->fetchAll();
        if (count($result) == 1)
            return $result[0];
        return FALSE; // return false if failed to find record
    }

    private function getPrimaryField($tableName) {
        $result = $this->db->query('SHOW COLUMNS FROM '.prefixTable($tableName).' WHERE `Key` = "PRI"')->fetchAll();
        if (count($result) > 1) {
            throw new Exception("Synchronization compare for table ".$tableName." returns multiple primary key fields");
        }
        return $result[0]['Field'];
    }

    private function convertRec($record, $fields) {
        $result = array();
        for ($i = 0; $i < count($record); $i++) {
            $key = $fields[$i];
            $val = $record[$i];
            $result[$key] = $val;
        }
        return $result;
    }

    private function getRecDescFields($tableName) {
        $tableMetadata = $this->metadata["table_metadata"];
        if (!array_key_exists($tableName, $tableMetadata)) {
            return array();
        }

        $descFields = $tableMetadata["fields"]; // description fields on this table directly

        if ($tableMetadata["joins"]) {
            foreach ($tableMetadata["joins"] as $joinTableInfo) {
                array_push($descFields, $joinTableInfo["field"]);
            }
        }

        return $descFields;
    }

    private function getRecMetadata($tableName, $type, $record) {
        // table has no metadata if not in the table_metadata list
        $tableMetadata = $this->metadata["table_metadata"];
        if (!array_key_exists($tableName, $tableMetadata)) {
            return array();
        }

        $tableMetaInfo = $tableMetadata[$tableName];

        // if delete use data from this database
        if ($type == "delete") {
            $metadata = $this->getRecMetadataFromDB($tableName, $tableMetaInfo, $record);
        }
        else { // if update or insert use data in changes, then fall back to database
            $metadata = $this->getRecMetadataFromChanges($tableName, $tableMetaInfo, $record);
        }

        return $metadata;
    }

    // this function will search the changes list first then fallback to the DB
    private function getRecMetadataFromChanges($tableName, $tableMetaInfo, $record) {
        $metadata = array();

        // first add the fields from this very table record that might be indicated as metadata
        if ($tableMetaInfo["fields"]) {
            foreach ($tableMetaInfo["fields"] as $field) {
                $metadata[$field] = $record[$field];
            }
        }

        // for joined table fields check the changes list, then fallback to DB
        if ($tableMetaInfo["joins"]) {
            foreach ($tableMetaInfo["joins"] as $joinTableInfo) {
                $joinTableName = $joinTableInfo["join_table"];
                $joinTableKey = $joinTableInfo["join_field"][1];
                $joinTableField = $joinTableInfo["field"];
                $recTableKey = $joinTableInfo["join_field"][0];
                $recTableKeyVal = $record[$recTableKey];

                $changesTable = &$this->changes[$joinTableName];
                $fieldValue = false;
                if ($changesTable) {
                    foreach ($changesTable["inserts"] as $rec) {
                        if ($rec[$joinTableKey] == $recTableKeyVal) {
                            $fieldValue = $rec[$joinTableField];
                        }
                    }
                    foreach ($changesTable["updates"] as $rec) {
                        if ($rec[$joinTableKey] == $recTableKeyVal) {
                            $fieldValue = $rec[$joinTableField];
                        }
                    }
                    if (!$fieldValue) { // fall back to DB
                        $sql = "SELECT ".$joinTableField." FROM ".prefixTable($joinTableName)." WHERE ".$joinTableKey."=".$recTableKeyVal.";";
                        $result = $this->db->query($sql)->fetchAll();
                        $fieldValue = $result[0][$joinTableField];
                    }
                }
                else { // if table not in changes list, fallback to DB
                    $sql = "SELECT ".$joinTableField." FROM ".prefixTable($joinTableName)." WHERE ".$joinTableKey."=".$recTableKeyVal.";";
                    $result = $this->db->query($sql)->fetchAll();
                    $fieldValue = $result[0][$joinTableField];
                }
                $metadata[$joinTableField] = $fieldValue;
            }
        }

        return $metadata;
    }

    private function getRecMetadataFromDB($tableName, $tableMetaInfo, $record) {
        $sqlSelect = 'SELECT ';
        $sqlFrom = 'FROM '.prefixTable($tableName);
        $sqlJoins = array();
        $sqlWhere = 'WHERE ';

        // if the table has fields of its own for metadata, add them
        $tableFieldsCount = count($tableMetaInfo["fields"]);
        if ($tableFieldsCount > 0) {
            for ($i = 0; $i < $tableFieldsCount; $i++) {
                $fieldName = $tableMetaInfo["fields"][$i];
                $sqlSelect .= prefixTable($tableName).".".$fieldName.", ";
            }
        }

        // now add the information from the join tables
        if (count($tableMetaInfo["joins"]) > 0) {
            foreach ($tableMetaInfo["joins"] as $joinTableInfo) {
                $joinTableName = $joinTableInfo["join_table"];
                $mainTableJoinField = $joinTableInfo["join_field"][0];
                $joinTableJoinField = $joinTableInfo["join_field"][1];
                $joinField = $joinTableInfo["field"];

                // add field for this table join
                $sqlSelect .= prefixTable($joinTableName).'.'.$joinField.', ';

                // add the left join information for this table
                $tableJoinSql = 'LEFT JOIN '.prefixTable($joinTableName).' on ';
                $tableJoinSql .= prefixTable($tableName).'.'.$mainTableJoinField.' = '.prefixTable($joinTableName).'.'.$joinTableJoinField;
                array_push($sqlJoins, $tableJoinSql);
            }
        }

        // remove the unnecessary trailing ', ' on the end of the SQL select fragment
        $sqlSelect = substr($sqlSelect, 0, -2);

        // generate where clause using table primary key and record values
        $primaryField = $this->getPrimaryField($tableName);
        $primaryFieldVal = $record[$primaryField];
        $sqlWhere .= prefixTable($tableName).'.'.$primaryField.' = '.$primaryFieldVal;

        // combine the pieces of the sql statement, execute the query, and return the data
        $sql = $sqlSelect.' '.$sqlFrom.' '.implode(" ", $sqlJoins).' '.$sqlWhere;
        $result = $this->db->query($sql);
        return $result->fetchAll()[0];
    }

    // insert a new record into the database
    private function commitInsert($tableName, $record, $fields) {
        $sql = 'INSERT INTO '.prefixTable($tableName).' ('.join(", ", $fields).') VALUES (';

        // add comma separated list of values
        foreach ($record as $field => $value) {
            $sanitizedValue = $this->db->quote($value);
            $sql .= "$sanitizedValue, ";
        }
        $sql = substr($sql, 0, -2); // remove the unnecessary trailing ', '
        $sql .= ');'; //close values brackets

        $result = $this->db->query($sql);
        // returns success/failure of query based on number of affected rows
        return $result->rowCount() == 1;
    }

    // update an existing record in the database
    private function commitUpdate($tableName, $record) {
        $primaryField = $this->getPrimaryField($tableName);
        $recPrimaryValue = $record[$primaryField];

        $sql = 'UPDATE '.prefixTable($tableName).' SET ';

        foreach ($record as $field => $value) {
            $quotedVal = $this->db->quote($value);
            $sql .= $field."=$quotedVal, ";
        }

        // remove the unnecessary trailing ', '
        $sql = substr($sql, 0, -2);

        // add the where clause to specify which record to update
        $quotedVal = $this->db->quote($recPrimaryValue);
        $sql .= " WHERE $primaryField=$quotedVal;";

        $result = $this->db->query($sql);
        // returns success/failure of query based on number of affected rows
        return $result->rowCount() == 1;
    }

    // use the forms class to create a new form data table in the database
    private function commitCreateTable($tableName) {
        // get the fid for the data table based on the table name
        $formHandle = substr($tableName, strlen("formulize_"));
        $formHandler =& xoops_getmodulehandler('forms', 'formulize');
        $fid = $formHandler->getByHandle($formHandle);

        // create the data table and return the boolean success result
        $success = $formHandler->createDataTable($fid);
        return $success;
    }
}

function prefixTable($tableName) {
    return XOOPS_DB_PREFIX."_".$tableName;
}

function cacheVar($var, $varname) {
    // cleanup any old files from this cached variable
    formulize_scandirAndClean(XOOPS_ROOT_PATH."/modules/formulize/cache/", $varname);

    // serialize variable and write to file in cache
    $filepath = XOOPS_ROOT_PATH . "/modules/formulize/cache/" . $varname;
    file_put_contents($filepath, serialize($var));
}

function loadCachedVar($varname) {
    // cleanup any old files from this cached variable
    formulize_scandirAndClean(XOOPS_ROOT_PATH."/modules/formulize/cache/", $varname);

    // get cached variable and unserialize
    try {
        $fileStr = file_get_contents(XOOPS_ROOT_PATH . "/modules/formulize/cache/".$varname);
    }
    catch (Exception $e) {
        throw $e;
    }
    return unserialize($fileStr);
}
