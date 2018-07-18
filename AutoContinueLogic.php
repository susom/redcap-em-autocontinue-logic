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

        $auto_continue_logic = array();
        $all_settings = $this->getProjectSettings($project_id);

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
                // $this->exitAfterHook();

                global $end_survey_redirect_next_survey;

                if ($end_survey_redirect_next_survey) {
                    // Try to get the next survey url
                    $next_survey_url = \Survey::getAutoContinueSurveyUrl($record, $instrument, $event_id);
                    // print "Redirecting you to $next_survey_url";
                    // \Plugin::log("Soft Redirecting $record from $instrument to $next_survey_url");
                    // This is causing issues so I'm going to try a client-redirect
                    // redirect($next_survey_url);
                    $this->redirect($next_survey_url);
                    return false;
                } else {
                    // If there is a normal end-of-survey url - go there
                    global $end_survey_redirect_url;
                    if ($end_survey_redirect_url != "") {
                        // \Plugin::log("Redirecting to end_survey_redirect_url $record from $instrument to $end_survey_redirect_url");
                        // redirect($end_survey_redirect_url);
                        $this->redirect($end_survey_redirect_url);
                        return false;
                    }                // Display the normal end-of-survey message with an additional note
                    else {
                        // Get full acknowledgement text (perform piping, if applicable)
                        global $acknowledgement;
                        $full_acknowledgement_text = "Thanks for taking the survey.";

                        $custom_text = ""; // "<div class='yellow'><h3><center>This survey does not apply.</center></h3></div>";
                        // \Plugin::log("Survey logic is false for $record from $instrument but no auto-continue is enabled - displaying end of survey text.");
                        // \Plugin::log("Calling ExitSurvey from $instrument to $full_acknowledgement_text / $acknowledgement");

                        // The acknowledgement text comes from the last survey
                        exitSurvey($acknowledgement, false);
                    }
                }
                // Catch all
                // exit ();
            } else {
                // administer the instrument
            }
        }
    }


    public function redirect($url) {
        // Doing a soft-redirect so that we can return from the hook and not throw and EM error
        echo("<script type=\"text/javascript\">window.location.href=\"$url\";</script>");
    }


}
