<?php

namespace Stanford\AutoContinueLogic;

include_once("emLoggerTrait.php");

use \REDCap;
use \Survey;
use \RepeatInstance;

class AutoContinueLogic extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $next_survey_id;
    public $next_survey_form;
    public $next_survey;
    public $auto_continue_logic;



    /**
     * LOAD THE CONFIG - FIRST USING THE JSON FORMAT AND THEN BASED ON EACH FIELD AS SPECIFIED
     * @param $project_id
     * @return array|mixed
     */
    function loadConfig() {

        // This could probably be re-worked to use the new getSubSettings method but not bothering now...
        $all_settings = $this->getProjectSettings();
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

        $this->auto_continue_logic = $auto_continue_logic;
        return $auto_continue_logic;
    }



    /**
     * DETERMINE THE NEXT VALID SURVEY INSTRUMENT IN THE INSTRUMENTS IN THIS EVENT
     * This is used to prevent multiple redirects
     *
     * @param     $record
     * @param     $current_form_name
     * @param     $event_id
     * @param int $instance
     * @return bool            True or False if one was found
     */
    public function isAnotherValidSurvey($record, $current_form_name, $event_id, $instance=1)
	{
		global $Proj;

		// Get all forms from this event
		$forms_array = $Proj->eventsForms[$event_id];

		// Get all forms after the current one
		$forms_array = array_slice($forms_array, array_search($current_form_name, $forms_array) + 1);

		// Create array of valid surveys remaining
		foreach ($forms_array as $k => $form) {
			$this_survey_id = isset($Proj->forms[$form]['survey_id']) ? $Proj->forms[$form]['survey_id'] : 0;

			// Id the next form isn't a survey, the continue
            if (!$this_survey_id) continue;

            $this_survey = $Proj->surveys[$this_survey_id];
            // Check it is enabled
            if ($this_survey['survey_enabled'] == 1 &&

                // Check response limit (if enabled) - do not do AutoContinue for this survey/event if hit limit already
                !Survey::reachedResponseLimit($Proj->project_id, $this_survey_id, $event_id) &&

                // Check that it isn't expired
                !($this_survey['survey_expiration'] != '' && $this_survey['survey_expiration'] <= NOW)
            ) {
                // This is the next valid survey - return its form name
                $this->next_survey      = $this_survey;
                $this->next_survey_id   = $this_survey_id;
                $this->next_survey_form = $Proj->surveys[$this_survey_id]['form_name'];
                return true;
			}
		}
        return false;
	}



    /**
     * APPLY AUTOCONTINUE LOGIC AND HANDLE THE REDIRECT
     *
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     * @param $group_id
     * @param $survey_hash
     * @param $response_id
     * @param $repeat_instance
     */
    function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        global $Proj;

        // $this->emDebug(__METHOD__, PAGE, $instrument);

        // Don't do anything if there isn't a record_id - and for promis-cat instruments
        if (empty($record)) {
            $this->emDebug('Record missing - returning');
            return;
        }

        // Load the autocontinue logic from config
        $this->loadConfig();

        // if (empty($this->auto_continue_logic[$instrument])) {
        //     $this->emDebug("No ac logic specified for $instrument");
        // } else {
        //     $logic_result = REDCap::evaluateLogic($this->auto_continue_logic[$instrument], $project_id, $record, $event_id, $repeat_instance);
        //     if (!$logic_result) $this->emDebug("Logic Result is False", $logic_result);
        //     //empty($this->auto_continue_logic[$instrument])) $this->emDebug("Logic is empty");
        // }

        // Check the current instrument first
        if ( empty($this->auto_continue_logic[$instrument]) ||
            REDCap::evaluateLogic($this->auto_continue_logic[$instrument], $project_id, $record, $event_id, $repeat_instance))
        {
            // We should administer this instrument - we can simply return out of this hook
            $this->emDebug("Administering survey: $instrument");
            return;
        } else {
            $this->emDebug("Survey $instrument logic evaluates to FALSE - skipping");
        }

        // Before, we would just redirect to the next survey and do all this over again, but if you have a lot
        // of redirects, the client doesn't have a very good experience.  Now we are going to 'look ahead'
        // before redirecting to find the next eligible survey

        // Evaluate it until we run out of instruments, pass logic, or find an instrument without any logic
        $last_instrument = $instrument;
        do {

            // See if there is another survey after the 'last_instrument'
            if (!$this->isAnotherValidSurvey($record, $last_instrument, $event_id, $repeat_instance)) {
                // There isn't, so lets exit on our current survey
                // Ideally we would exit on the survey BEFORE this survey but we can't do that easily
                $this->emDebug("We've run out of surveys on this autocontinue sequence");
                global $end_survey_redirect_url;
                if ($end_survey_redirect_url != "") {
                    // Do the end-of-survey redirect
                    $this->emDebug("Redirecting to end_survey_redirect_url: " . $end_survey_redirect_url . " for Record $record / Instrument $instrument");
                    $this->redirect($end_survey_redirect_url);
                } else {
                    // Get full acknowledgement text (perform piping, if applicable)
                    $this->emDebug("Displaying acknowledgement from $instrument");
                    global $acknowledgement;
                    exitSurvey($acknowledgement, false);
                }
                // Leave hook
                return;
            }

            // See if this downstream survey is ready to be rendered
            if (empty($this->auto_continue_logic[$this->next_survey_form]) ||
                REDCap::evaluateLogic($this->auto_continue_logic[$this->next_survey_form], $project_id, $record, $event_id, $repeat_instance))
            {
                // This instrument is good to be administered - let's check a few more things:
                // Repeating Forms ONLY: Get count of existing instances and find next instance number
                if ($Proj->isRepeatingForm($event_id, $this->next_survey_form)) {
                    list ($instanceTotal, $instanceMax) = RepeatInstance::getRepeatFormInstanceMaxCount($record, $event_id, $this->next_survey_form, $Proj);
                    // $instance = max(array($instanceMax, $instance)) + 1;
                    $instance = $instanceMax + 1;
                } elseif (!$Proj->isRepeatingEvent($event_id)) {
                    // If next form/event is not a repeating form or repeating event, always use instance 1
                    $instance = 1;
                }

                // Use survey_functions to generate a hash for this survey
                list($next_participant_id, $next_hash) = Survey::getFollowupSurveyParticipantIdHash($this->next_survey_id, $record, $event_id, false, $instance);
                $next_survey_url = APP_PATH_SURVEY_FULL . "?s=$next_hash";
                if ($next_survey_url) {
                    $this->emDebug("Redirecting to next valid instrument $this->next_survey_form at $next_survey_url");
                    $this->redirect($next_survey_url);
                    return;
                } else {
                    $this->emError("This shouldn't happen - unable to get next_survey_url", $this->next_survey_form, $this->next_survey_id);
                    return;
                }
            } else {
                $this->emDebug("Next survey, $this->next_survey_form, has FALSE logic - skipping");
            }

            $last_instrument = $this->next_survey_form;

        } while (true);
    }


    /**
     * Lets add some UI enhancements
     * @param null $project_id
     */
    function redcap_every_page_top($project_id = null) {
        $this->addSurveyIcons();

        $this->addSurveySettingsDisclaimer();
    }



    /**
     * This complex function re-orders the autocontinue logic fields into the same order as the survey instruments
     * in the project
     *
     * @param $subsetting_key
     * @param $form_key_name
     */
    function reorderSubSettingsBasedOnFieldOrder($subsetting_key, $form_key_name) {

        // Load the current subsettings
        // $subsettings = $this->getProjectSetting('ac_logic_list');
        $subsettings = $this->getSubSettings($subsetting_key);

        // Sort the values in the subsettings based on the form_key_name
        $forms = array_keys(REDCap::getInstrumentNames());
        usort($subsettings, function ($a, $b) use ($form_key_name, $forms) {
            return array_search($a[$form_key_name], $forms) - array_search($b[$form_key_name],$forms);
        });
        $this->emDebug($subsettings);

        // Get a list of fields that are part of the subsetting_key
        $subSettingConfig = $this->getSettingConfig($subsetting_key);
        $result = [];
        foreach($subSettingConfig['sub_settings'] as $subSettingField) {
            $name = $subSettingField['key'];
            $result[$name] = [];
        }

        // Loop through the actual data to rebuild subsetting fields in the $result variable
        foreach ($subsettings as $i => $settings) {
            // $settings is a key-value array where key is subsetting_key_name
            foreach ($settings as $name => $val) {
                if (isset($result[$name])) $result[$name][] = $val;
            }
        }

        // $this->emDebug($result);

        // Update results for any changed subsetting fields
        $count = 0;
        foreach ($result as $key => $data) {
            // Keep track of the number of subsettings so we can set the number of 'true' for the parent key
            $count = max($count, count($data));
            $current = $this->getProjectSetting($key);
            if ($current !== $data) {
                $this->emDebug("Updating $key");
                $this->setProjectSetting($key, $data);
            } else {
                $this->emDebug("No need to update");
            }
        }

        // Update the parent key if the number of subsettings has changed
        $current = $this->getProjectSetting($subsetting_key);
        if (count($current) != $count) {
            $data = $count == 0 ? [] : array_fill(0,$count,"true");
            $this->emDebug("Updating subsetting key $subsetting_key", $data);
            $this->setProjectSetting($subsetting_key, $data);
        }

    }



    /**
     * When we save a config, let's check the order of the fields
     * @param $project_id
     */
    function redcap_module_save_configuration($project_id) {
        $this->reorderSubSettingsBasedOnFieldOrder('ac_logic_list','form_name');
    }



    /**
     * Adding some more info to the survey Settings page as well.
     */
    function addSurveySettingsDisclaimer()
    {
        if (PAGE == "Surveys/edit_info.php" || PAGE == "Surveys/create_survey.php") {
            $survey_name = $_GET['page'];

            // Get current value from external-module settings
            $this->loadConfig();

            // Nothing to do if there isn't logic for this instrument
            if (empty($this->auto_continue_logic[$survey_name])) return;

            ?>
            <div style="display:none;">
                <table>
                    <tr id="autocontinuelogic-tr" style="padding: 10px 0;">
                        <td valign="top" style="width:20px;">
                            <i class="fas fa-code-branch"></i>
                        </td>
                        <td valign="top">
                            <div class=""><strong>AutoContinueLogic EM is configured:</strong></div>
                        </td>
                        <td valign="top" style="padding-left:15px;">
                            <div>This survey will only be administered if the logic below is <b>true</b>:
                                <a href="javascript:;" class="help2" onclick="simpleDialog('<p>This project has the AutoContinueLogic External Module installed. ' +
                                  'If someone tries to open this survey and this logic is not true, the participant will be redirected to the next available survey.</p>' +
                                  '<p>If the logic is false and there are no more eligible surveys after this, then the participant will receive the ' +
                                   'the end-of-survey options as configured below.</p>' +
                                   '<p>You can change these setting in the External Module config page</p>','AutoContinueLogic External Module',600);">?</a>
                            </div>
                            <code style="font-weight:normal; background-color:white; display:block; padding: 5px; width: 98%; border: 1px solid #c1c1c1;;">
                                <?php echo $this->auto_continue_logic[$survey_name]; ?>
                            </code>
                        </td>
                    </tr>
                </table>
            </div>

            <script>
                $(document).ready(function () {
                    var parentTr = $('#end_survey_redirect_next_survey').closest('tr');
                    $('#autocontinuelogic-tr')
                        .insertAfter($('#save_and_return-tr'))
                        .show()
                    ;
                });
            </script>
            <?php

        }
    }



    /**
     * On the edit instrument table it shows a lock icon next to surveys that have webauth enabled
     */
    function addSurveyIcons()
    {
        if (PAGE == "Design/online_designer.php") {
            $this->loadConfig();

            if (count($this->auto_continue_logic) > 0) {
                $tip = '';
                ?>
                <script>
                    $(document).ready(function () {
                        var autocontinue_logic_surveys = <?php echo json_encode($this->auto_continue_logic); ?>;
                        console.log("Here", autocontinue_logic_surveys);
                        $.each(autocontinue_logic_surveys, function (i, j) {
                            console.log(i,j);
                            var img = $('<a href="#"><i class="fas fa-code-branch"></i></a>')
                                .attr('title', "<div style='font-size: 10pt;'>This survey uses the AutoContinue Logic EM and <u>only</u> renders if the following is true:</div><code style='font-size: 9pt;'>" + autocontinue_logic_surveys[i] + "</code>")
                                // .css({'margin-left': '3px'})
                                .attr('data-html', true)
                                // .attr('data-toggle', 'tooltip')
                                .attr('data-trigger', 'hover')
                                .attr('data-placement', 'right')
                                .insertAfter('a.modsurvstg[href*="page=' + i + '&"]');
                            img.popover(); //tooltip();
                        });
                        $('a.modsurvstg').removeAttr('style');
                    });
                </script>
                <style>
                    a.modsurvstg { display:inline-block; }
                </style>
                <?php
            }
        }
    }




    /**
     * Does a client-side redirect to prevent EM hook termination errors
     * @param $url
     */
    public function redirect($url) {
        // $this->emDebug("Redirecting to URL: $url");
        // Doing a soft-redirect so that we can return from the hook and not throw and EM error
        echo("<script type=\"text/javascript\">window.location.href=\"$url\";</script>");
    }

}
