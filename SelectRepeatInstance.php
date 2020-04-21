<?php

namespace Stanford\SelectRepeatInstance;

require_once("emLoggerTrait.php");
// require_once("RepeatingForms.php");
require_once("FormHelper.php");

use \REDCap;
use \Exception;

class SelectRepeatInstance extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;


    /** Prepends an entry into notebox specified by user upon record save
     * @param      $project_id
     * @param null $record
     * @param      $instrument
     * @param      $event_id
     * @param null $group_id
     * @param      $survey_hash
     * @param      $response_id
     * @param int  $repeat_instance
     * @return bool
     * @throws Exception
     */
    public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id = NULL, $survey_hash, $response_id, $repeat_instance = 1)
    {

        // Take the current instrument and get all the fields.
        $instances = $this->getSubSettings('instance');

        // Loop over all instances
        foreach ($instances as $i => $instance) {
            // Get fields from current instance
            $source_event_id           = $instance['source-event-id'];
            $source_form               = $instance['source-form'];
            $logic                     = $instance['logic'];
            $destination_event_id      = $instance['destination-event-id'];
            $destination_summary_field = $instance['destination-summary-field'];

            $event_names = REDCap::getEventNames(true,true);
            $event_name = $event_names[$event_id];

            if ($event_id !== $source_event_id) {
                // $this->emDebug("Skipping event $event_id");
                continue;
            }

            if ($instrument !== $instance['source-form']) {
                // $this->emDebug("Skipping current form $instrument");
                continue;
            }

            // Is the source event a repeating event or do we just have a repeating form?
            $f = new FormHelper($project_id, $instrument, $this);

            if($f === false) {
                $this->emLog("Unable to instantiate RepeatingForms");
                continue;
            }

            // Determine if we should do the summary copy
            if (!empty($logic)) {
                $result = \REDCap::evaluateLogic($logic,$project_id,$record,$event_name,$repeat_instance, $instrument);
                $this->emDebug("Logic Evaluated:  $logic => " . ($result ? 'True' : 'False'));
                if (!$result) {
                    // Failed logic - skip
                    continue;
                }
            }

            // Make sure the destination is a singleton event
            if ($f->getEventType($destination_event_id) !== $f::TYPE_SINGLETON) {
                $this->emLog("$instrument is enabled as a non-repeating form in the destination event $destination_event_id");
                continue;
            }

            if ($f->getEventType($source_event_id) == $f::TYPE_SINGLETON) {
                // We are doing a normal copy of the data
                $this->emDebug("Doing a singleton save");
                $data = $f->getData($record, $source_event_id);
            } else {
                $this->emDebug("Doing an instance save $repeat_instance data");
                $data = $f->getDataByInstanceId($record, $source_event_id, $repeat_instance);
            }
            $this->emDebug("Save Data is ", $data);
            $result = $f->saveData($record, $data, $destination_event_id);

            // Load the data
            // $data = $f->getInstanceById( $record, $repeat_instance, $event_id);
            // $data['redcap_event_name'] = $event_names[$destination_event_id];
            // $data[\REDCap::getRecordIdField()] = $record;

            if (!empty($destination_summary_field)) {
                // Verify field exists in destination event
                $dest_fields = \REDCap::getValidFieldsByEvents($project_id,$destination_event_id,false);
                if(!in_array($destination_summary_field, $dest_fields)) {
                    $this->emError("Destination Summary Field $destination_summary_field is not enabled in destination event $destination_event_id");
                } else {
                    $data = array($destination_summary_field => $repeat_instance);
                    $result2 = $f->saveData($record, $data, $destination_event_id);
                    $this->emDebug("Saving destination summary field $destination_summary_field", $data, $result2);
                    // $data[$destination_summary_field] = $repeat_instance;
                }
            }


            // $result = REDCap::saveData('json', json_encode(array($data)), 'overwrite');
            if (!empty($result['errors'])) {
                $this->emError($result);
                REDCap::logEvent("Summary Instance Update Failure","Failed to update summary instance for record $record instance $repeat_instance on form $instrument","", $record, $event_id, $project_id);
            } else {
                REDCap::logEvent("Summary Instance Update","","", $record, $event_id, $project_id);
                $this->emDebug("Summary Instance Updated");
            }

            // $this->emDebug("DATA", $data, $result);
        }

    }

}
