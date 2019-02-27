<?php
/**
 * Created by PhpStorm.
 * User: jael
 * Date: 10/6/17
 * Time: 2:24 PM
 */

namespace Stanford\AutoContinueLogic;

include_once("emLoggerTrait.php");

use \REDCap;
use \Survey;

class AutoContinueLogic extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;


    /**
     * LOAD THE CONFIG - FIRST USING THE JSON FORMAT AND THEN BASED ON EACH FIELD AS SPECIFIED
     * @param $project_id
     * @return array|mixed
     */
    function loadConfig($project_id) {

        $all_settings = $this->getProjectSettings($project_id);
        $auto_continue_logic = array();

        // First load the json options (stripping linefeeds)
        if (! empty($all_settings['autocontinue_logic']['value'])) {
            $json = preg_replace("/[\n\r]/","",$all_settings['autocontinue_logic']['value']);
            $auto_continue_logic = json_decode($json,true);
        }

        // Next take the per-instrument settings
        if (! empty($all_settings['instrument_name']['value'])) {
            $instruments = $all_settings['instrument_name']['value'];
            $logics = $all_settings['instrument_logic']['value'];
            foreach ($instruments as $i => $instrument_name) {
                $auto_continue_logic[$instrument_name] = $logics[$i];
            }
        }

        return $auto_continue_logic;
    }

    function redcap_survey_page_top($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1)
    {

        // $this->emDebug("At SurveyPage Top of ACL", func_get_args(), $_POST);

        // Don't do anything if there isn't a record_id - and for promis-cat instruments
        if (empty($record)) {
            $this->emDebug('Record missing - returning');
            return;
        }

        $auto_continue_logic = $this->loadConfig($project_id);


        // Check if custom logic is applied to this instrument
        if (isset ($auto_continue_logic [$instrument])) {
            // Get the logic and evaluate it
            $raw_logic = $auto_continue_logic [$instrument];

            $this->emDebug($auto_continue_logic, "Instrument: " . $instrument, "Raw Logic", $raw_logic);

            $logic_result = REDCap::evaluateLogic($raw_logic, $project_id, $record, $event_id, $repeat_instance); //, $instrument);
            // $this->emDebug("EVALUATING: ", $raw_logic, $project_id, $record, $event_id, $repeat_instance, "RESULT:", $logic_result);

            $this->emDebug("Logic Result",
                    $logic_result,
                    "Raw: " . $raw_logic,
                    "PID: " . $project_id,
                    "RECORD: " . $record,
                    "EVENT ID: " . $event_id,
                    "INSTANCE: " . $repeat_instance,
                    "INSTRUMENT: " . $instrument);

            if ($logic_result == false) {
                // If autocontinue is enabled - then redirect to next instrument
                REDCap::logEvent("$instrument skipped due to AutoContinue Logic EM", "", "", $record, $event_id, $project_id);

                global $end_survey_redirect_next_survey;
                if ($end_survey_redirect_next_survey) {
                    // Try to get the next survey url
                    $next_survey_url = Survey::getAutoContinueSurveyUrl($record, $instrument, $event_id, $repeat_instance);

                    $this->emDebug("Redirecting client to next survey: " . $next_survey_url);

                    $this->redirect($next_survey_url);
                    return false;
                } else {
                    // If there is a normal end-of-survey url - go there
                    global $end_survey_redirect_url;
                    if ($end_survey_redirect_url != "") {
                        $this->emDebug("Redirecting to end_survey_redirect_url: " . $next_survey_url, "Record $record / Instrument $instrument");
                        $this->redirect($end_survey_redirect_url);
                        return false;
                    } else {
                        // Get full acknowledgement text (perform piping, if applicable)
                        $this->emDebug("Displaying acknowledgement");
                        global $acknowledgement;
                        exitSurvey($acknowledgement, false);
                    }
                }
            } else {
                // administer the instrument
                $this->emDebug("Logic valid - administering $instrument for record $record");
            }
        }
    }


    public function redirect($url) {
        $this->emDebug("Redirecting to URL: $url");
        // Doing a soft-redirect so that we can return from the hook and not throw and EM error
        echo("<script type=\"text/javascript\">window.location.href=\"$url\";</script>");
    }

}
