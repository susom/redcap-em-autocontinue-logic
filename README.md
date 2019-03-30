# AutoContinue Logic

This external module allows you to add branching logic to a series of surveys that are linked via AutoContinue.

It should not be used with the survey queue feature.

## Instructions

1. Enable more than 1 instrument as surveys in REDCap.
1. Enable auto-continue toward the bottom of the survey setup page for all but the last survey
1. Configure the autocontinue logic module
    * Note that if you specify any logic, it must evaluate to true or else the instrument will be skipped
    * If there are no more valid instruments after a failed logic, then the end-of-survey settings for the original instrument will apply.
    
In the most recent version I added some indicators to the instrument list and survey settings page to notify you if enabled.
