<?php
namespace Stanford\SelectRepeatInstance;

use \REDCap;
use \Project;
use \Survey;
use \Exception;

/*
 * Data is returned without the record and event context.  So, while a normal redcap getData might produce data like:
    [record_id 1]
        [event_id 1]                => [form data]
        [event_id 2]                => [form data]

    or, for repeating forms,

    [record_id 1]
        "repeat_instances"
            [event_id 1]
                "form_name"
                    [instance 1]    => [form_data]

    This module returns only the [form_data].
*/



/**
 * Class FormHelper
 * @package Stanford\Utilities
 *
 */
class FormHelper
{
    // Metadata
    private $Proj;
    private $project_id;
    private $data_dictionary;
    private $fields;
    private $instrument;
    private $is_survey;
    private $survey_id;
    private $events_enabled = array();  // Array of event_ids where the instrument is enabled with event_id as key
                                        // and type as either: 'single', 'repeating_instrument', or 'repeating_event'

    /** @var $module SelectRepeatInstance */
    private $module;

    // Instance-Specific Details
    private $singleton_data;               // Just one instance
    private $instance_data;                // An array of all instances
    private $data;
    private $data_raw;                     // Raw getData results for debugging
    private $all_records_loaded = false;   // A flag if all records were loaded
    private $record_id;
    private $event_id;
    private $event_type;

    // Last error message
    public $last_error_message = null;

    const TYPE_SINGLETON         = 0;
    const TYPE_REPEAT_INSTRUMENT = 1;
    const TYPE_REPEAT_EVENT      = 2;

    // The object is constructed without context (event_id or instance_id)
    // TODO: How should errors be handled.  I think an exception should be thrown for some things...

    /**
     * FormHelper constructor
     * No event and instance are supplied - they are parameters for various methods
     * @param      $project_id
     * @param      $instrument
     * @param      $module
     * @param bool $include_form_status_field
     * @throws Exception
     */
    function __construct($project_id, $instrument, $module, $include_form_status_field = true)
    {
        //TODO: Module is temporary for logging purposes...
        global $Proj;
        $this->module = $module;
        $this->module->emDebug("Constructed");

        // Set the Project
        $this->Proj = $Proj->project_id == $project_id ? $Proj : new Project($project_id);
        if (empty($this->Proj) or ($this->Proj->project_id != $project_id)) {
            $msg = "Unable to determine a valid project for $project_id";
            $this->module->emError($msg);
            $this->last_error_message = $msg;
            throw new Exception($msg);
        }
        $this->project_id = $project_id;

        // Set the instrument
        if (!isset($this->Proj->forms[$instrument])) {
            // TODO: If someone changes a form name after configuring a module that uses this method
            // Should it fail with an exception or something else?
            $msg = "Form $instrument is not valid in project $this->project_id";
            $this->module->emError($msg);
            $this->last_error_message = $msg;
            throw new Exception($msg);
        }
        $this->instrument = $instrument;

        // Set fields on instrument
        $this->data_dictionary = REDCap::getDataDictionary($project_id, 'array', false, null, array($instrument));
        $this->fields = array_keys($this->data_dictionary);

        // Add form_status field to fields
        if ($include_form_status_field) $this->fields[] = $instrument . "_complete";

        // Set Survey Info
        $this->is_survey = isset($this->Proj->forms[$this->instrument]["survey_id"]);
        $this->survey_id = $this->is_survey ? $this->Proj->forms[$instrument]["survey_id"] : null;

        // Set Events Enabled for Instrument
        // key is event_id, value is 'singleton', 'repeat-instrument', or 'repeat-event'
        $this->Proj->getRepeatingFormsEvents();
        foreach ($this->Proj->eventsForms as $event_id => $forms_array) {
            if (in_array($this->instrument, $forms_array)) {
                // Form is in event - get repeating type:
                if (isset($this->Proj->RepeatingFormsEvents[$event_id][$this->instrument])) {
                    $form_repeat_type = self::TYPE_REPEAT_INSTRUMENT;
                } elseif (@$this->Proj->RepeatingFormsEvents[$event_id] === "WHOLE") {
                    $form_repeat_type = self::TYPE_REPEAT_EVENT;
                } else {
                    $form_repeat_type = self::TYPE_SINGLETON;
                }
                $this->events_enabled[$event_id] = $form_repeat_type;
            }
        }
    }


    /**
     * This function will load data internally from the database using the record, event and optional
     * filter in the calling arguments here as well as pid and instrument name from the constructor.  The data
     * is saved internally in $this->data.
     *
     * The calling code must then call one of the get* functions to retrieve the data.
     *
     * @param      $record (record or array of records, or leave blank for pulling ALL data)
     * @param null $event_id
     * @param null $filter
     * @return None
     * @throws Exception
     */
    public function loadData($record=null, $event_id=null, $filter=null)
    {
        //TODO: WHAT SHOULD THIS RETURN?

        // Convert record to an array if a singleton
        if (!is_null($record) && !is_array($record)) $record = array($record);
        if (empty($record)) $this->all_records_loaded = true;

        // Set event to first if not specified
        $event_id = $this->verifyValidEventId($event_id);

        // Get the data
        $params = array(
            "project_id"     => $this->project_id,
            "records"        => $record,
            "fields"         => $this->fields,
            "event_id"       => $event_id,
            "filterLogic"    => $filter
            // "exportAsLabels" => true    // NOT SURE WHY THIS IS TRUE?
        );
        $query = REDCap::getData($params);
        // $repeating_forms = REDCap::getData($this->project_id, $return_format, array($record_id), $this->fields, $this->event_id, NULL, false, false, false, $filter, true);

        // Re-parse the instance data into a simpler array
        switch($this->getEventType($event_id)) {
            case self::TYPE_REPEAT_EVENT:
            case self::TYPE_REPEAT_INSTRUMENT:
                foreach (array_keys($query) as $record_id) {
                    if (isset($query[$record_id]["repeat_instances"][$event_id][$this->instrument])) {
                        $this->data[$record_id][$event_id] = $query[$record_id]["repeat_instances"][$event_id][$this->instrument];
                    }
                }
                break;
            case self::TYPE_SINGLETON:
            default:
                foreach (array_keys($query) as $record_id) {
                    $this->data[$record_id][$event_id] = $query[$record_id][$event_id];
                }
        }
    }


    /**
     * This function will return the data retrieved based on a previous loadData call. All instances of an
     * instrument fitting the criteria specified in loadData will be returned. See the file header for the
     * returned data format.
     *
     * @param      $record_id
     * @param null $event_id
     * @return array (of data loaded from loadData) or false if an error occurred
     * @throws Exception
     */
    public function getAllInstances($record_id, $event_id=null) {

        // Set the event_id
        $event_id = $this->verifyValidEventId($event_id);

        // Verify data is loaded if not in cache
        if (!isset($this->data[$record_id][$event_id])) {
            $this->loadData($record_id, $event_id, null);
        }

        return @$this->data[$record_id][$event_id];
    }


    /**
     * This function will return one instance of data retrieved in dataLoad using the $instance_id.
     * It should only be called if the form/event is repeating
     *
     * @param      $record_id
     * @param      $instance_id
     * @param null $event_id
     * @return mixed Array of instance data or false if an error occurs
     * @throws Exception
     */
    public function getData($record_id, $event_id=null, $instance_id=null, $filter=null)
    {
        $event_id = $this->verifyValidEventId($event_id);

        // Load data if not already done
        if (!isset($this->data[$record_id][$event_id]) && !$this->all_records_loaded) {
            $this->loadData($record_id, $event_id, $filter);
        }

        // Is a valid record
        if (!isset($this->data[$record_id][$event_id])) {
            $msg                      = "Record $record_id in event $event_id does not exist";
            $this->last_error_message = $msg;
            return false;
        }
        $data = $this->data[$record_id][$event_id];

        // Repeating or singleton
        if ($this->isRepeating($event_id)) {

            // Instance is required
            if (is_null($instance_id)) {
                // Return all instances
                $result = $data;
            } elseif (!isset($data[$instance_id])) {
                $msg                      = "Instance $instance_id is not valid for record $record_id in event $event_id";
                $this->last_error_message = $msg;
                return false;
            } else {
                // Return just the requested instance
                $result = $data[$instance_id];
            }
        } else {
            // Return singleton data
            $result = $data;
        }

        return $result;
    }


    // /**
    //  * Get all form data for a record
    //  *
    //  * @param      $record_id
    //  * @param null $event_id
    //  * @param null $filter
    //  * @return mixed
    //  * @throws Exception
    //  */
    // public function getData($record_id, $event_id=null, $filter=null) {
    //     // Set the event_id
    //     $event_id = $this->verifyValidEventId($event_id);
    //
    //     // Check if data is loaded
    //     if (!isset($this->data[$record_id][$event_id]) && !$this->all_records_loaded) {
    //         $this->loadData($record_id, $event_id, $filter);
    //     }
    //
    //     return $this->data[$record_id][$event_id];
    // }


    /**
     * Save data for a record
     * If form is singleton, then data is expected to be a singleton array.
     * If form is repeating then data is an array of instances.  If you wish to only save a single
     * instance, use the saveInstance method
     *
     * is expected to be an instance array
     * @param      $record_id
     * @param      $data
     * @param null $event_id
     * @param null $filter
     * @return mixed
     * @throws Exception
     */
    public function saveData($record_id, $data, $event_id=null, $filter = null) {
        // Set the event_id
        $event_id = $this->verifyValidEventId($event_id);

        $payload = array();
        if($this->isRepeating($event_id)) {
            $this->module->emDebug("$event_id is repeating");
            // Data should be an array of instance_ids => data
            $keys = array_keys($data);


            $payload[$record_id]['repeat_instances'][$event_id][$this->instrument] = $data;
        } else {
            $this->module->emDebug("$event_id is NOT repeating");
            $payload[$record_id][$event_id] = $data;
        }

        $result = REDCap::saveData($this->project_id, 'array', $payload);
        $this->module->emDebug('Save', $payload, $result);

        if (!isset($result["errors"]) and ($result["item_count"] <= 0)) {
            $msg = "Problem saving data for record $record_id in event $event_id (type = " .
                $this->getEventType() . ") on project $this->project_id. Returned: " . json_encode($result);
            $this->last_error_message = $msg;
            return false;
        } else {
            return true;
        }
    }


    /**
     * This function will return the first instance_id for this record and optionally event. This function
     * does not return data. If the instance data is desired, call getInstanceById using the returned instance id.
     *
     * @param      $record_id
     * @param null $event_id
     * @return int (instance number) or false (if an error occurs)
     * @throws Exception
     */
    public function getFirstInstanceId($record_id, $event_id=null) {
        $instances = $this->getInstances($record_id, $event_id);

        // There was a problem
        if ($instances === false) return false;

        // None exist?
        // TODO: What about repeating events where none of hte fields for this form are entered yet?
        if (empty($instances)) return false;

        // Return the first key
        foreach($instances as $key => $unused) {
            return $key;
        }
        return NULL;
    }


    /**
     * This function will return the last instance_id for this record and optionally event. This function
     * does not return data. To retrieve data, call getInstanceById using the returned $instance_id.
     *
     * @param      $record_id
     * @param null $event_id
     * @return int | false (If an error occurs)
     * @throws Exception
     */
    public function getLastInstanceId($record_id, $event_id=null) {
        $instances = $this->getInstances($record_id, $event_id);

        // There was a problem
        if ($instances === false) return false;

        // None exist?
        // TODO: What about repeating events where none of hte fields for this form are entered yet?
        if (empty($instances)) return false;

        // Return the last key
        $key = NULL;
        if ( is_array( $instances ) ) {
            end( $instances );
            $key = key( $instances );
        }
        return $key;
    }


    /**
     * This function will return the next instance_id in the sequence that does not currently exist.
     * If there are no current instances, it will return 1.
     *
     * @param      $record_id
     * @param null $event_id
     * @return int | false (if an error occurs)
     * @throws Exception
     */
    public function getNextInstanceId($record_id, $event_id=null)
    {
        $lastInstanceID = $this->getLastInstanceId($record_id, $event_id);

        if ($lastInstanceID === false) {
            // something went wrong
            $result = false;
        } else {
            $result = empty($lastInstanceID) ? 1 : $lastInstanceID + 1;

        }
        return $result;
    }


    /**
     * This function will save an instance of data.  If the instance_id is supplied, it will overwrite
     * the current data for that instance with the supplied data. An instance_id must be supplied since
     * instance 1 is actually stored as null in the database.  If an instance is not supplied, an error
     * will be returned.
     *
     * @param      $record_id
     * @param      $data
     * @param null $instance_id
     * @param null $event_id
     * @return true | false (if an error occurs)
     * @throws Exception
     */
    public function saveInstance($record_id, $data, $instance_id, $event_id = null)
    {
        // Verify event
        $event_id = $this->verifyValidEventId($event_id);

        // Verify repeating
        if (! $this->isRepeating($event_id)) return false;

        // Verify instance_id
        $instance_id = filter_var($instance_id, FILTER_SANITIZE_NUMBER_INT);
        if (! $instance_id > 0) {
            $msg = "Instance ID must be 1 or greater";
            $this->last_error_message = $msg;
            return false;
        }

        // Verify data
        if (!is_array($data)) {
            $msg = "Data must be in an array format";
            $this->last_error_message = $msg;
            return false;
        }

        // Generate payload
        $payload = array();
        $payload[$record_id]['repeat_instances'][$event_id][$this->instrument][$instance_id] = $data;

        $result = REDCap::saveData($this->project_id, 'array', $payload);
        $this->module->emDebug('Save', $result);
        if (!isset($result["errors"]) and ($result["item_count"] <= 0)) {
            $msg = "Problem saving instance $instance_id for record $record_id in event $event_id on " .
                "project $this->project_id. Returned: " . json_encode($result);
            $this->last_error_message = $msg;
            return false;
        } else {
            return true;
        }
    }


    // TBD: Not sure how to delete an instance ????
    public function deleteInstance($record_id, $instance_id, $event_id = null) {

        global $module;
        $module->emLog("This is the pid in deleteInstance $this->project_id for record $record_id and instance $instance_id and event $event_id");
        // If longitudinal and event_id = null, send back an error
        if ($this->Proj->longitudinal && is_null($event_id)) {
            $this->last_error = "Event ID Required for longitudinal project in " . __FUNCTION__;
            return false;
        } else if (!$this->Proj->longitudinal) {
            $event_id = $this->event_id;
        }

        $this->last_error = "Delete instance is not implemented yet!" . __FUNCTION__;

        // *** Copy deleteRecord from Records.php  *****
        // Collect all queries in array for logging
        /*
        $sql_all = array();

        $event_sql = "AND event_id IN ($event_id)";
        $event_sql_d = "AND d.event_id IN ($event_id)";
        */

        // "Delete" edocs for 'file' field type data (keep its row in table so actual files can be deleted later from web server, if needed).
        // NOTE: If *somehow* another record has the same doc_id attached to it (not sure how this would happen), then do NOT
        // set the file to be deleted (hence the left join of d2).
        /*
        $sql_all[] = $sql = "update redcap_metadata m, redcap_edocs_metadata e, redcap_data d left join redcap_data d2
							on d2.project_id = d.project_id and d2.value = d.value and d2.field_name = d.field_name and d2.record != d.record
							set e.delete_date = '".NOW."' where m.project_id = " . $this->pid . " and m.project_id = d.project_id
							and e.project_id = m.project_id and m.element_type = 'file' and d.field_name = m.field_name
							and d.value = e.doc_id and e.delete_date is null and d.record = '" . $record_id . "'
							and d.instance = '" . $instance_id . "'
							and d2.project_id is null $event_sql_d";
        db_query($sql);
        */
        // "Delete" edoc attachments for Data Resolution Workflow (keep its record in table so actual files can be deleted later from web server, if needed)
        /*
        $sql_all[] = $sql = "update redcap_data_quality_status s, redcap_data_quality_resolutions r, redcap_edocs_metadata m
							set m.delete_date = '".NOW."' where s.project_id = " . $this->pid . " and s.project_id = m.project_id
							and s.record = '" . $record_id . "' $event_sql and s.status_id = r.status_id
							and s.instance = " . $instance_id . "
							and r.upload_doc_id = m.doc_id and m.delete_date is null";
        db_query($sql);
        */
        // Delete record from data table
        /*
        $sql_all[] = $sql = "DELETE FROM redcap_data WHERE project_id = " . $this->pid . " AND record = '" . $record_id . "' AND instance = " . $instance_id . " $event_sql";
        db_query($sql);
        $module->emLog("Deleted from redcap_data: " . $sql);
        */

        // Also delete from locking_data and esignatures tables
        /*
        $sql_all[] = $sql = "DELETE FROM redcap_locking_data WHERE project_id = " . $this->pid . " AND record = '" . $record_id . "' AND instance = " . $instance_id . " $event_sql";
        db_query($sql);
        $sql_all[] = $sql = "DELETE FROM redcap_esignatures WHERE project_id = " . $this->pid . " AND record = '" . $record_id . "' AND instance = " . $instance_id . " $event_sql";
        db_query($sql);
        */
        // Delete from calendar - no instance in table
        //$sql_all[] = $sql = "DELETE FROM redcap_events_calendar WHERE project_id = " . PROJECT_ID . " AND record = '" . db_escape($fetched) . "' $event_sql";
        //db_query($sql);
        // Delete records in survey invitation queue table
        // Get all ssq_id's to delete (based upon both email_id and ssq_id)
        /*
        $subsql =  "select q.ssq_id from redcap_surveys_scheduler_queue q, redcap_surveys_emails e,
					redcap_surveys_emails_recipients r, redcap_surveys_participants p
					where q.record = '" .$record_id . "' and q.email_recip_id = r.email_recip_id and e.email_id = r.email_id
					and q.instance = '" . $instance_id . "'
					and r.participant_id = p.participant_id and p.event_id = $event_id";
        // Delete all ssq_id's
        $subsql2 = pre_query($subsql);
        if ($subsql2 != "''") {
            $sql_all[] = $sql = "delete from redcap_surveys_scheduler_queue where ssq_id in ($subsql2)";
            db_query($sql);
        }
        */
        // Delete responses from survey response table for this arm
        /*
        $sql = "select r.response_id, p.participant_id, p.participant_email
				from redcap_surveys s, redcap_surveys_response r, redcap_surveys_participants p
				where s.project_id = " . $this->pid . " and r.record = '" . $record_id . "'
				and s.survey_id = p.survey_id and p.participant_id = r.participant_id and p.event_id in $event_id";
        $q = db_query($sql);
        if (db_num_rows($q) > 0)
        {
            // Get all responses to add them to array
            $response_ids = array();
            while ($row = db_fetch_assoc($q))
            {
                // If email is blank string (rather than null or an email address), then it's a record's follow-up survey "participant",
                // so we can remove it from the participants table, which will also cascade to delete entries in response table.
                if ($row['participant_email'] === '') {
                    // Delete from participants table (which will cascade delete responses in response table)
                    $sql_all[] = $sql = "DELETE FROM redcap_surveys_participants WHERE participant_id = ".$row['participant_id'];
                    db_query($sql);
                } else {
                    // Add to response_id array
                    $response_ids[] = $row['response_id'];
                }
            }
            // Remove responses (I don't think instance is the same as $instance_id??????
            if (!empty($response_ids)) {
                $sql_all[] = $sql = "delete from redcap_surveys_response where response_id in (".implode(",", $response_ids).") and instance = " . $instance_id;
                db_query($sql);
            }
        }
        */
        /*
        // Delete record from randomization allocation table (if have randomization module enabled)
        if ($randomization && Randomization::setupStatus())
        {
            // If we have multiple arms, then only undo allocation if record is being deleted from the same arm
            // that contains the randomization field.
            $removeRandomizationAllocation = true;
            if ($multiple_arms) {
                $Proj = new Project(PROJECT_ID);
                $randAttr = Randomization::getRandomizationAttributes();
                $randomizationEventId = $randAttr['targetEvent'];
                // Is randomization field on the same arm as the arm we're deleting the record from?
                $removeRandomizationAllocation = ($Proj->eventInfo[$randomizationEventId]['arm_id'] == $arm_id);
            }
            // Remove randomization allocation
            if ($removeRandomizationAllocation)
            {
                $sql_all[] = $sql = "update redcap_randomization r, redcap_randomization_allocation a set a.is_used_by = null
									 where r.project_id = " . PROJECT_ID . " and r.rid = a.rid and a.project_status = $status
									 and a.is_used_by = '" . db_escape($fetched) . "'";
                db_query($sql);
            }
        }
        */
        // Delete record from Data Quality status table
        //$sql_all[] = $sql = "DELETE FROM redcap_data_quality_status WHERE project_id = " . PROJECT_ID . " AND record = '" . $record_id . "' $event_sql AND instance = $instance_id";
        //db_query($sql);
        // Delete all records in redcap_ddp_records
        //$sql_all[] = $sql = "DELETE FROM redcap_ddp_records WHERE project_id = " . PROJECT_ID . " AND record = '" . db_escape($fetched) . "'";
        //db_query($sql);
        // Delete all records in redcap_surveys_queue_hashes
        //$sql_all[] = $sql = "DELETE FROM redcap_surveys_queue_hashes WHERE project_id = " . PROJECT_ID . " AND record = '" . db_escape($fetched) . "'";
        //db_query($sql);
        // Delete all records in redcap_new_record_cache
        //$sql_all[] = $sql = "DELETE FROM redcap_new_record_cache WHERE project_id = " . PROJECT_ID . " AND record = '" . db_escape($fetched) . "'";
        //db_query($sql);
        // If we're required to provide a reason for changing data, then log it here before the record is deleted.
        //$change_reason = ($require_change_reason && isset($_POST['change-reason'])) ? $_POST['change-reason'] : "";
        //Logging
        //Logging::logEvent(implode(";\n", $sql_all),"redcap_data","delete",$fetched,"$table_pk = '$fetched'","Delete record$appendLoggingDescription",$change_reason);
        // **** End copy/paste *****

        return false;
    }


    /**
     * Gets all instances and sorts numerically
     * @param      $record_id
     * @param null $event_id
     * @return bool|mixed
     * @throws Exception
     */
    private function getInstances($record_id, $event_id = null) {
        // Verify event
        $event_id = $this->verifyValidEventId($event_id);

        // Verify repeating
        if (! $this->isRepeating($event_id)) return false;

        $data = $this->getData($record_id, $event_id);
        ksort($data,SORT_NUMERIC);
        return $data;
    }


    /**
     * Verify the event_id supplied is valid or throw an exception
     * For classical projects, assume the first_event_id
     * Throw an exception if validation is not met
     *
     * @param $event_id
     * @return null
     * @throws Exception
     */
    private function verifyValidEventId($event_id) {

        // Set the event_id
        if (empty($event_id)) {
            if ($this->Proj->longitudinal) {
                $msg                      = "You must supply an event_id for longitudinal projects in " . __FUNCTION__;
                $this->last_error_message = $msg;
                throw new Exception($msg);
            } else {
                $event_id = $this->Proj->firstEventId;
            }
        }

        // Is event valid for instrument?
        if(!isset($this->events_enabled[$event_id])) {
            $msg = "$this->instrument is not enabled in event $event_id";
            $this->last_error_message = $msg;
            throw new Exception($msg);
        }

        return $event_id;
    }


    /**
     * Verify event_id is repeating for instrument or throw exception
     * @param $event_id
     * @throws Exception
     */
    private function verifyRepeatingInstrument($event_id) {
        if (! $this->isRepeating($event_id)) {
            $msg = "$this->instrument is not repeating in event $event_id";
            $this->last_error_message = $msg;
            throw new Exception($msg);
        }
    }


    /**
     * Is instrument repeating in event_id
     * @param $event_id
     * @return bool
     */
    private function isRepeating($event_id) {
        $event_type = $this->getEventType($event_id);
        if ($event_type === self::TYPE_REPEAT_INSTRUMENT ||
            $event_type === self::TYPE_REPEAT_EVENT) {
            return true;
        }
        return false;
    }


    /**
     * Return the event type for the active instrument
     * as 0, Singleton | 1, Repeat Instrument | 2, Repeat Event
     * @param $event_id
     * @return mixed 0-2 or false
     */
    public function getEventType($event_id) {
        return isset($this->events_enabled[$event_id]) ? $this->events_enabled[$event_id] : false;
    }


    /**
     * Return the data dictionary for this form
     *
     * @return array
     */
    public function getDataDictionary()
    {
        return $this->data_dictionary;
    }

    /**
     * This function will look for the data supplied in the given record/event and send back the instance
     * number if found.  The data supplied does not need to be all the data in the instance, just the data that
     * you want to search on.
     *
     * @param      $needle
     * @param      $record_id
     * @param null $event_id
     * @return int | false (if an error occurs)
     * @throws Exception
     */
    public function exists($needle, $record_id, $event_id=null) {
        // TODO: NOT BEING USED?
        // Longitudinal projects need to supply an event_id
        if ($this->Proj->longitudinal && is_null($event_id)) {
            $this->last_error = "Event ID Required for longitudinal project in " . __FUNCTION__;
            return false;
        } else if (!$this->Proj->longitudinal) {
            $event_id = $this->event_id;
        }

        // Check to see if we have the correct data loaded.
        if ($this->data_loaded == false || $this->record_id != $record_id || $this->event_id != $event_id) {
            $this->loadData($record_id, $event_id, null);
        }

        // Look for the supplied data in an already created instance
        $found_instance_id = null;
        $size_of_needle = sizeof($needle);
        if ($this->Proj->longitudinal) {
            foreach ($this->data[$record_id][$event_id] as $instance_id => $instance) {
                $intersected_fields = array_intersect_assoc($instance, $needle);
                if (sizeof($intersected_fields) == $size_of_needle) {
                    $found_instance_id = $instance_id;
                }
            }
        } else {
            foreach ($this->data[$this->record_id] as $instance_id => $instance) {
                $intersected_fields = array_intersect_assoc($instance, $needle);
                if (sizeof($intersected_fields) == $size_of_needle) {
                    $found_instance_id = $instance_id;
                }
            }
        }

        // Supplied data did not match any instance data
        if (is_null($found_instance_id)) {
            $this->last_error_message = "Instance was not found with the supplied data " . __FUNCTION__;
        }

        return $found_instance_id;
    }


    /**
     * Obtain the survey url for this instance of the form
     * @param $record
     * @param $instance_id
     * @return string|null
     */
    public function getSurveyUrl($record, $instance_id) {
        // Make sure the instrument is a survey
        if(!$this->is_survey) {
            $this->last_error_message = "This instrument is not a survey";
            return null;
        }

        $url = REDCap::getSurveyLink($record, $this->instrument, $this->event_id, $instance_id, $this->project_id);
        return $url;
    }

}
