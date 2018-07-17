<?php
/**
 * Created by PhpStorm.
 * User: jael
 * Date: 10/6/17
 * Time: 2:24 PM
 */

namespace Stanford\AutoContinueLogic;

class AutoContinueLogic extends \ExternalModules\AbstractExternalModule {


    function  hook_survey_page_top($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1)
    {
        //get the config entry for  autocontinue_logic
        $config_entry = $this->getProjectSetting('autocontinue_logic');
        // strip carriage returns
        $config_entry = preg_replace("/[\n\r]/","",$config_entry);
        // \Plugin::log($config_entry, "DEBUG", "config entry");

        if (!empty($config_entry)) {

            $auto_continue_logic = json_decode($config_entry, true);
           // \Plugin::log($auto_continue_logic, "DEBUG", "AUTO CONTINUE LOGIC FOUND");

        } else {

            $msg =  "This project uses Auto-Continue Logic External Module.  The logic is either invalid or missing.  Please check.";
            \REDCap::logEvent($msg, "", "", $record, $event_id, $project_id);

            // \Plugin::log($msg, "ERROR");
            //todo: bubble up to project?  logging?

        }

        // Check if custom logic is applied to this instrument
        if (isset ($auto_continue_logic [$instrument])) {
            // Get the logic and evaluate it
            $raw_logic = $auto_continue_logic [$instrument];

            // \Plugin::log($raw_logic, "DEBUG", "$project_id: Logic Before: " . $raw_logic);
            // Prepend current event as needed
            if (\REDCap::isLongitudinal()) {
                $unique_event_name = \REDCap::getEventNames(true,false,$event_id);
                // \Plugin::log("$project_id: unique_event_name: " . $unique_event_name);
                $raw_logic = \LogicTester::logicPrependEventName($raw_logic, $unique_event_name);
                // \Plugin::log("$project_id: logic after: " . $raw_logic);
            }

            $isValid = \LogicTester::isValid($raw_logic);

            if (!$isValid) {
                print "<div class='red'><h3><center>Supplied survey auto-continue logic is invalid:<br>$raw_logic</center></h3></div>";
            }
            $logic_result = \LogicTester::evaluateLogicSingleRecord($raw_logic, $record);
            // \Plugin::log("- $record at $instrument with [" . ($logic_result ? "true" : "false") . "] from $raw_logic");

            if ($logic_result == false) {
                // If autocontinue is enabled - then redirect to next instrument
                \REDCap::logEvent("$instrument skipped due to AutoContinue Logic EM", "", "", $record, $event_id, $project_id);
                $this->exitAfterHook();
                global $end_survey_redirect_next_survey;
                if ($end_survey_redirect_next_survey) {
                    // Try to get the next survey url
                    $next_survey_url = \Survey::getAutoContinueSurveyUrl($record, $instrument, $event_id);
                    // print "Redirecting you to $next_survey_url";
                    // \Plugin::log("Redirecting $record from $instrument to $next_survey_url");
                    redirect($next_survey_url);
                } else {
                    // If there is a normal end-of-survey url - go there
                    global $end_survey_redirect_url;
                    if ($end_survey_redirect_url != "") {
                        redirect($end_survey_redirect_url);
                    }                // Display the normal end-of-survey message with an additional note
                    else {
                        // Get full acknowledgement text (perform piping, if applicable)
                        global $acknowledgement;
                        $full_acknowledgement_text = "Thanks for taking the survey.";

                        $custom_text = ""; // "<div class='yellow'><h3><center>This survey does not apply.</center></h3></div>";
                        // \Plugin::log("Survey logic is false for $record from $instrument but no auto-continue is enabled - displaying end of survey text.");
                        exitSurvey($custom_text . $full_acknowledgement_text, false);
                    }
                }
                // Catch all
                // exit ();
            } else {
                // administer the instrument
            }
        }
    }


}
