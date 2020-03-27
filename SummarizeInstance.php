<?php

namespace Stanford\SummarizeInstance;

require_once("emLoggerTrait.php");

use \REDCap;

class SummarizeInstance extends \ExternalModules\AbstractExternalModule
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
     */
    public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id = NULL, $survey_hash, $response_id, $repeat_instance = 1)
    {
        // Take the current instrument and get all the fields.
        $instances = $this->getSubSettings('instance');

        // Loop over all instances
        foreach ($instances as $i => $instance) {
            // Get fields from current instance
            $source_event_id      = $instance['source-event-id'];
            $source_form          = $instance['source-form'];
            $logic                = $instance['logic'];
            $destination_event_id = $instance['destination-event-id'];

            if ($event_id !== $source_event_id) {
                $this->emDebug("Skipping event $event_id");
                continue;
            }

            // Is the source event a repeating event or do we just have a repeating form?
            $rp = new RepeatingForms($project_id, $instrument);

            if($rp === false) {
                $this->emLog($rp->last_error_message);
            }
        }

    }

}
