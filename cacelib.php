<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * cacelib.php, Library of CACE functions
 *
 * Course Auto-Create and Enrol function library contains
 * code to create/update courses and enrol/unenrol students
 *
 * 2010-05-03
 * @package      plug-in
 * @subpackage   RRU_CACE
 * @copyright    2011 Andrew Zoltay, Royal Roads University
 * @license      http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . "/course/lib.php");
require_once($CFG->dirroot . "/enrol/locallib.php");
require_once($CFG->libdir . "/moodlelib.php");
require_once($CFG->libdir . "/resourcelib.php");
require_once("cace_config.php");
require_once("database.php");
require_once("mssql.php");

if (!defined('RRU_CRLF')) {
    define('RRU_CRLF', "\r\n");
}
define('PAGE_MOD', 'page');

$cace_email_messages = array();

/*******************************************************************************/
/*            A U T O - C R E A T E / U P D A T E  C O U R S E S               */
/*******************************************************************************/

/**
 * Create a new course using existing Moodle functionality
 *
 * @author Andrew Zoltay
 * date    2010-05-03
 * @param associative array $newcourse used to create new course
 * @param string $pagecontent contents of page resource
 * @return int id of new course or failure of course creation
 */
function cace_create_newcourse($newcourse, $pagecontent) {
    global $DB, $USER;
    // Need to force a user so the system can log who is doing the action.
    $USER = $DB->get_record ( 'user', array ('username' => 'mdladmin'));
    // Prep the course info.
    $course = cace_prep_newcourse($newcourse);

    // Verify $course was prepared correctly.
    if (!$course) {
        cace_write_to_log("ERROR - Moodle cace_prep_newcourse() failed for $newcourse->idnumber");
        return false;
    }

    // Create the new course shell.
    try {
        $createdcourse = create_course($course);

        // If course shell was created, add "Development Notes" resource to new shell
        // First prep the data - default section to the first one in the course (0).
        $data = cace_prep_page_data($createdcourse, 0, $pagecontent);

        if (!cace_add_page_resource($createdcourse, $data)) {
            // Just report the error - don't stop execution.
            cace_write_to_log("ERROR - Failed to add $data->name for course $newcourse->idnumber");
        }

        // Add default blocks to the right column.
        cace_update_default_course_blocks($createdcourse);
        
        // Add a record in the grade categories table and also in the grade items table to support letter grade and percentages
        // in the course totals column.
        cace_add_grade_records($createdcourse->id);

        return $createdcourse->id;

    } catch (moodle_exception $e) {
        cace_write_to_log("ERROR - Moodle create_course() failed for $newcourse->idnumber " . $e->getMessage());
        return false;
    }

}

/**
 * Update the Moodle course with the contents from the SIS course
 * Note: $course includes id, fullname, shortname and startdate
 *
 * @author Andrew Zoltay
 * date    2011-06-02
 * @param update course object $course
 * @return true for success, false for failure
 */
function cace_update_course($course) {

    $course->timemodified = time();

    if (cace_dbupdate_course($course)) {
        // Do some logging.
        add_to_log($course->id, "course", "update", "edit.php?id=$course->id", $course->id);

        // Trigger events.
        events_trigger('course_updated', $course);

        return true;
    } else {
        return false;
    }
}

/**
 * Load course data object with appropriate data
 *
 * @author Andrew Zoltay
 * date    2010-05-03
 * @global $CACE_CFG - configuration settings for CACE
 * @param associative array $course used to prepare new course
 * @return object course data object or false if fails
 */
function cace_prep_newcourse($course) {
    global  $CACE_CFG;

    $newcourse = new stdClass();

    // Check some fields before we create the new course shell.
    if (empty($course->fullname)) {
        cace_write_to_log("ERROR - Course fullname is required");
        return false;
    }
    if (empty($course->shortname)) {
        cace_write_to_log("ERROR - Course shortname is required");
        return false;
    }
    if (empty($course->idnumber)) {
        cace_write_to_log("ERROR - Course idnumber is required");
        return false;
    }

    if (empty($CACE_CFG->defaultcatid)) {
        cace_write_to_log('ERROR - $CACE_CFG->defaultcatid is not set in the config.php. Please define a course category ID');
        return false;
    }

    // Fill in properties that are required for the new course shell.
    $newcourse->category = cace_get_course_category($course);
    $newcourse->fullname = $course->fullname;
    $newcourse->shortname = $course->shortname;
    $newcourse->idnumber = $course->idnumber;

    $newcourse->password = '';// Cr: not used actually but could be added to file, has no default in DB.
    $newcourse->summary = '';
    $newcourse->format = get_config('moodlecourse', 'format');
    $newcourse->numsections = get_config('moodlecourse', 'numsections');
    $newcourse->startdate = $course->startdate;

    $newcourse->hiddensections = 0;
    $newcourse->newsitems = get_config('moodlecourse', 'newsitems');
    $newcourse->showgrades = 1; // AZ - use default.
    $newcourse->showreports = 0; // AZ - use default.
    $newcourse->maxbytes = get_config('moodlecourse', 'maxbytes');
    $newcourse->metacourse = 0; // AZ - use default.
    $newcourse->summaryformat = FORMAT_HTML; // Change the Course summary text box editor format.
    
    // Enrolments
    $newcourse->enrol = ''; // Cr: has no default in DB.
    $newcourse->defaultrole = 0; // AZ - use default.
    $newcourse->enrollable = 0; // AZ - use default.
    $newcourse->enrolstartdate = 0; // AZ - use default.
    $newcourse->enrolenddate = 0; // Cr: defaults to 0 in DB.
    $newcourse->enrolperiod = 0; // AZ - use default.

    // Enrolment expiry notification
    $newcourse->expirynotify = 0; // AZ - use default.
    $newcourse->notifystudents = 0; // AZ - use default.
    $newcourse->expirythreshold = 864000;// Cr: 10 days, defaults to 0 in DB.

    // Teams
    $newcourse->groupmode = 0; // AZ - use default.
    $newcourse->groupmodeforce = 0; // AZ - use default.
    $newcourse->defaultgroupingid = 0; // AZ - use default.

    // Availability
    $newcourse->visible = 0; // AZ default to "This course is not available to students".
    $newcourse->guest = 0; // AZ default to "No guest access".

    // Language
    $newcourse->lang = '';// Cr: next ones have no defaults in DB.

    // Other stuff.
    $newcourse->theme = '';
    $newcourse->cost = '';
    $newcourse->timecreated = time();// Cr: defaults to 0 in DB.
    $newcourse->timemodified = time();// Cr: defaults to 0 in DB.

    // Pass out the course data.
    return $newcourse;
}

/**
 * Purpose: Prepare a page resource
 *
 * @author Andrew Zoltay
 * date    2011-04-27
 * @global $CACE_CFG - configuration settings for CACE
 * @global object $DB Moodle database object
 * @param object $course
 * @param int $sectionid
 * @param string $content page content
 */
function cace_prep_page_data($course, $section, $content) {
    global $CACE_CFG, $DB;

    // Get resource module configuration items.
    $config = get_config('resource');

    // Get Page module info.
    $module = $DB->get_record('modules', array('name' => PAGE_MOD), '*', MUST_EXIST);

    // Build the data class that stores the page info.
    $data = new stdClass();
    $data->section          = $section;     // The section number itself - relative!!! (section column in course_sections table).
    $data->course           = $course->id;  // Course ID.
    $data->coursemodule     = '';           // Course module id - leave empty since we're adding a new resource.
    $data->module           = $module->id;
    $data->modulename       = $module->name;
    $data->groupmode        = $course->groupmode;
    $data->groupingid       = $course->defaultgroupingid;
    $data->groupmembersonly = 0;
    $data->id               = '';
    $data->instance         = '';           // CM instance - leave empty since we're adding a new resource.
    $data->type             = 'course';     // Default to 'course'.
    $data->revision         = 1;            // first revision.

    $data->name             = $CACE_CFG->res_name;  // Page resource name.
    $data->intro            = $CACE_CFG->res_intro; // Page resource Description.
    $data->introformat      = 1;            // Default to HTML format.

    $data->page['text']     = $content;     // Page resource content - the meat.
    $data->page['format']   = '1';          // Default to HTML format.
    $data->page['itemid']   = '';           // Draft item id - default to empty string since no draft.

    $data->printheading     = 1;            // Display page name toggle - default to true.
    $data->printintro       = 0;            // Display page description - default to false.
    $data->visible          = 1;            // Default Visible to true.

    $data->add              = PAGE_MOD;     // Hard code to 'page'.
    $data->return           = 0;            // Must be false if this is an add.

    $data->display          = RESOURCELIB_DISPLAY_OPEN;  // Default to open in place.

    return $data;

}

/**
 * Purpose: add a Moodle page resource to a course
 *
 * @author Andrew Zoltay
 * date    2011-04-27
 * @global $CFG - configuration settings for CACE
 * @global object $DB Moodle database object
 * @global $CACE_CFG - configuration settings for CACE
 * @param int $courseid
 * @param object $pagedata
 */
function cace_add_page_resource($course, $pagedata) {
    global $CFG, $DB, $CACE_CFG, $USER;
    require_once("$CFG->dirroot/mod/page/lib.php");

    try {
        // Create a new course module for the page.
        $newcm = new stdClass();
        $newcm->course           = $course->id;
        $newcm->module           = $pagedata->module;
        $newcm->instance         = 0; // Don't have this value yet, but will update later.
        $newcm->visible          = $pagedata->visible;
        $newcm->groupmode        = $pagedata->groupmode;
        $newcm->groupingid       = $pagedata->groupingid;
        $newcm->groupmembersonly = $pagedata->groupmembersonly;

        $pagedata->coursemodule = add_course_module($newcm);

        // This is lame, but need to create a dummy object for page_add_instance()
        // useless $mform parameter that isn't used in the function.
        $dummyform = new stdClass();

        // Create the page instance.
        $pageid = page_add_instance($pagedata, $dummyform);
        $pagedata->instance = $pageid;

        // Update course_modules table with new instance.
        $DB->set_field('course_modules', 'instance', $pageid, array('id' => $pagedata->coursemodule));

        // Add the module to the correct section of the course
        // course_modules and course_sections each contain a reference
        // to each other, so we have to update one of them twice.
        $sectionid = course_add_cm_to_section($pagedata->course, $pagedata->coursemodule, $pagedata->section);
        $DB->set_field('course_modules', 'section', $sectionid, array('id' => $pagedata->coursemodule));

        set_coursemodule_visible($pagedata->coursemodule, $pagedata->visible);

        // Trigger mod_created event with information about the new module.
        // Use the new events system to log the creation of a resource.
        $eventdata                 = clone $pagedata;
        $eventdata->modname        = $pagedata->modulename;
        $eventdata->name           = $pagedata->name;
        $eventdata->id             = $pagedata->coursemodule;
        $eventdata->courseid       = $course->id;
        $eventdata->userid         = $CACE_CFG->admin_user;    // Use Admin account for event data.
        $event = \core\event\course_module_created::create_from_cm($eventdata);
        $event->trigger();

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Purpose: get development notes from a file
 *
 * @author Andrew Zoltay
 * date    2011-05-25
 * @return returns the content of dev_notes.txt or false for failure
 */
function cace_load_devnotes_from_file() {
    // Retrieve Development Notes page content from file.
    $pagecontent = file_get_contents('dev_notes.txt', true);

    return $pagecontent;
}

/**
 * Update block instances for the course so default blocks appear on all pages for the course.
 *
 * @author Carlos Chiarella
 * date    2012-03-05
 * @global object $DB Moodle database object
 * @param object $course a course object.
 */
function cace_update_default_course_blocks($course) {
    global $DB;

    // Get context info of the course.
    $context = context_course::instance($course->id);

    $sql = "UPDATE {block_instances} SET showinsubcontexts = '1',
            pagetypepattern = '*' WHERE parentcontextid = ?";
    try {
            $DB->execute($sql, array($context->id));
            return true;
    } catch (Exception $e) {
            cace_write_to_log("ERROR - Failed to update default course blocks contextid: $context->id. The courseid is: $course->id");
    }
}

/**
 * Purpose: Identify the correct course category for the new course
 *
 * @author Andrew Zoltay
 * date    2011-05-24
 * @global $CACE_CFG - configuration settings for CACE
 * @global $cace_email_messages - array to store error messages to be accessed later
 * @param object $course
 * @return int destination category id for the course
 */
function cace_get_course_category($course) {
    global $CACE_CFG, $cace_email_messages;

    // Use values from Agresso to determine correct course category for the course.
    // Try the Program name.
    if (!isset($coursecategory)) {
        $coursecategory = cace_fetch_course_category($course->program, $course->programtype);

        if (!$coursecategory) {
            // Add a message to the log that the program could not be found.
            $errmsg = "WARNING - could not locate the program category '$course->program' (Top category: "  .
                    "$course->programtype) for course $course->idnumber.";
            cace_write_to_log($errmsg);
            // Add message to global email messages.
            $cace_email_messages[] = $errmsg;
        }
    }

    // Use default if not set by this point.
    if (!isset($coursecategory) or !$coursecategory) {
        $coursecategory = $CACE_CFG->defaultcatid;
        // Add a message to the log that the no categories could be found.
        $errmsg = "WARNING - could not locate any categories for course '$course->idnumber'" .
                ". The course will be created in default category specified in configuration file.";
        cace_write_to_log($errmsg);
        // Add message to global email messages.
        $cace_email_messages[] = $errmsg;
    }

    return $coursecategory;
}

/**
 * Purpose: Auto-create course shells based on external data
 * Steps:
 *   1. Clean up mdl_newcourses by deleting any rows in the table
 *   2. Pull new course information from Student Information System (Agresso)
 *   3. Save new course data in mdl_cace_newcourses table
 *   4. Use a query between mdl_cace_newcourses and mdl_course to
 *      identify all courses that need to be created
 *   5. Loop through courses to be created and create them through the Moodle course createfunction
 *   6. Send notification email listing which courses were created and which courses failed and why
 *
 * @author Andrew Zoltay
 * date    2010-05-03
 * @global $CACE_CFG - configuration settings for CACE
 * @global $CFG - Moodle configuration settings
 * @global $cace_email_messages - array to store error messages to be accessed later
 * @return none
 */
function cace_import_course_shells() {
    global $CACE_CFG, $CFG, $cace_email_messages;

    $coursescreatedcount = 0;
    $coursesfailedcount = 0;
    $coursescreated = "";
    $coursesnotcreated = "";
    $errormsg = null;

    cace_write_to_log("-------------------------------------------------------------------");
    cace_write_to_log("Beginning Auto-create course shell process");
    cace_write_to_log("-------------------------------------------------------------------");

    // Get page resource content from file.
    $pageresourcecontent = cace_load_devnotes_from_file();
    if (!$pageresourcecontent) {
        cace_write_to_log("ERROR - failed to load Development Notes content from dev_notes.txt file");
        // Set a default value.
        $pageresourcecontent = '<p></p><p>ID TR<br />ELT <br />Program office;</p>';
    }

    // Clean up newcourses table in preparation for new batch of courses.
    if (cace_prep_newcourses_table()) {
        // Connect to SIS.
        $conn = cace_mssql_connect();

        // Save new courses to Moodle table.
        $newcoursecount = cace_load_newcourses_table($conn);

        // Close connection to SIS.
        cace_mssql_close($conn);

        // Either no new courses or db connection failure.
        if ($newcoursecount > 0) {
            // Loop through new courses and create the course shell.
            cace_write_to_log("$newcoursecount courses that start in the next $CACE_CFG->monthsahead months were found in Agresso");

            // Get the new courses that we're going to create.
            $newcourses = cace_fetch_courses_to_create();

            if (!$newcourses) {
                cace_write_to_log("No new courses to create");
            } else {
                // Found some new courses to create.
                foreach ($newcourses as $newcourse) {
                    // Create new course.
                    $newcourseid = cace_create_newcourse($newcourse, $pageresourcecontent);
                    if ($newcourseid > 0) {
                        $coursescreatedcount++;
                        cace_write_to_log("Created new course: $newcourse->fullname (ID: $newcourseid)");

                        // Build successfully created courses list.
                        $coursescreated = $coursescreated . "<li> - $newcourse->fullname : <a href=" . '"' .
                                $CFG->wwwroot.'/course/view.php?id='.$newcourseid . '">' . "$CFG->wwwroot/course/view.php?id=$newcourseid</a></li>" . RRU_CRLF;
                    } else {
                        $coursesfailedcount++;
                        cace_write_to_log("ERROR - Failed to create new course shell for '$newcourse->fullname' AGR PK = $newcourse->agrcourseoffpk");

                        // Build successfully created courses list.
                        $coursesnotcreated = $coursesnotcreated . " - Agresso ID = $newcourse->agrcourseoffpk - $newcourse->fullname" . RRU_CRLF;
                    }
                    // Check for any warnings created during the creation process.
                    if (count($cace_email_messages) > 0) {
                        for ($i = 0; $i < count($cace_email_messages); $i++) {
                            $coursescreated = $coursescreated . $cace_email_messages[$i] . RRU_CRLF;
                        }
                        // Clear the global messages array.
                        $cace_email_messages = array();
                    }
                }
            }
            cace_write_to_log("$coursescreatedcount new course shells were created. $coursesfailedcount course shells failed to be created");
        } else {
            // Error occurred in cace_load_newcourses_table().
            $errormsg = "ERROR - an error occurred while loading new courses - please check cace.log on server." . RRU_CRLF;
        }

        /* Send notification of job completion */

        // Define ficticious sender email address.
        $sender = 'cace@royalroads.ca';

        // Get recipient user object.
        $recipient = $CACE_CFG->emailto;

        // Report error.
        if (empty($recipient)) {
            cace_write_to_log("$CACE_CFG->emailto was not set in config.php");
        }

        $subject = 'CACE Auto-created courses - ' . date('Y-m-d');
        $message =
                   '<html>' . RRU_CRLF .
                   '<body>' . RRU_CRLF .
                   '<h1>CACE Auto-create course report for ' . date('Y-m-d H:i:s') . '</h1><br>' . RRU_CRLF .
                   '<div>' . RRU_CRLF .
                   $errormsg .
                   '</div>' . RRU_CRLF .
                   '<h2>' . $coursescreatedcount . ' new course shells were created:</h2>' . RRU_CRLF .
                   '<div>' . RRU_CRLF .
                   '<ul>' . RRU_CRLF .
                   $coursescreated . RRU_CRLF .
                   '</ul>' . RRU_CRLF .
                   '</div>' . RRU_CRLF .
                   '<h2>' . $coursesfailedcount . ' course shells failed to be created:</h2>' . RRU_CRLF .
                   '<div>' . RRU_CRLF .
                   $coursesnotcreated . RRU_CRLF .
                   '</div>' . RRU_CRLF .
                   '</body>' . RRU_CRLF .
                   '</html>';

        // Send the message.
        if (cace_send_mail($recipient, $sender, $subject, $message)) {
            cace_write_to_log("Sent email to $recipient");
        } else {
            cace_write_to_log("ERROR - failed to send email to $CACE_CFG->emailto");
        }
    }

    cace_write_to_log("Script complete");
    cace_write_to_log("-------------------------------------------------------------------");
}


/**
 * Purpose: Auto-update course shells based on external data
 * Steps:
 *   1. Clean up mdl_newcourses by deleting any rows in the table
 *   2. Pull new course information from Student Information System (Agresso)
 *   3. Save new course data in mdl_cace_newcourses table
 *   4. Use a query between mdl_cace_newcourses and mdl_course and mdl_config_plugins to
 *      identify all courses that need to be updated
 *   5. Loop through courses to be updated and update them
 *   6. Send notification email listing which courses were updated and which courses failed and why
 *
 * @author Andrew Zoltay
 * date    2010-06-01
 * @global $CACE_CFG - configuration settings for CACE
 * @global $cace_email_messages - array to store error messages to be accessed later
 * @return none
 */
function cace_update_courses() {
    global $CACE_CFG;

    // Initialize some variables for use in email.
    $coursesupdatedcount = 0;
    $coursesfailedcount = 0;
    $coursesupdated = "";
    $coursesnotupdated = "";
    $errormsg = null;

    cace_write_to_log("-------------------------------------------------------------------");
    cace_write_to_log("Beginning Auto-update course shell process");
    cace_write_to_log("-------------------------------------------------------------------");

    // Clean up newcourses table in preparation for new batch of courses.
    if (cace_prep_newcourses_table()) {
        // Connect to SIS.
        $conn = cace_mssql_connect();

        // Pull fresh data from SIS.
        $newcoursecount = cace_load_newcourses_table($conn);

        // Close connection to SIS.
        cace_mssql_close($conn);

        // Either no new courses or db connection failure.
        if ($newcoursecount > 0) {
            // Loop through new courses and create the course shell.

            // Get the last update run from config_plugins.
            $lastruntime = get_config('local_cace', 'autoupdate_last_run');

            // Get the new courses that we're going to create.
            $updatecourses = cace_fetch_courses_to_update($lastruntime);

            if (!$updatecourses) {
                cace_write_to_log("No courses require updates");
            } else {
                cace_write_to_log(count($updatecourses)
                . " courses that start in the next $CACE_CFG->monthsahead months were found in Agresso that require updates");

                // Found some new courses to create.
                foreach ($updatecourses as $updatecourse) {
                    $message = '';

                    // Update the course.
                    if (cace_update_course($updatecourse)) {
                        $coursesupdatedcount++;
                        $message = 'Successfully updated course: ' . $updatecourse->fullname
                                    . ' (ID: ' . $updatecourse->id . ')';
                        $coursesupdated = $coursesupdated . '- ' . $message . RRU_CRLF;
                    } else {
                        $coursesfailedcount++;
                        $message = 'ERROR - failed to update course: ' . $updatecourse->fullname
                                    . ' (ID: ' . $updatecourse->id . ')';
                        $coursesnotupdated = $coursesnotupdated . '- ' . $message . RRU_CRLF;
                    }
                    cace_write_to_log($message);
                }
            }

            // If success - update config_plugins -.
            // Autoupdate_last_run value to current date/time in prepartion for next run.
            set_config('autoupdate_last_run', time(), 'local_cace');
        } else if (!$newcoursecount) {
            // Error occurred in cace_load_newcourses_table().
            $errormsg = "ERROR - an error occurred while loading updated courses - please check cace.log on server." . RRU_CRLF . RRU_CRLF;
        }

        /* Send notification of job completion */

        // Define ficticious sender email address.
        $sender = 'cace@royalroads.ca';

        // Get recipient user object.
        $recipient = $CACE_CFG->emailto;

        // Report error.
        if (empty($recipient)) {
            cace_write_to_log("$CACE_CFG->emailto was not set in config.php");
        }
        $subject = 'CACE Auto-update courses - ' . date('Y-m-d');
        $emailmessage = 'Auto-update course report for ' . date('Y-m-d H:i:s') . RRU_CRLF . RRU_CRLF .
                   $errormsg .
                   $coursesupdatedcount . ' new course shells were updated:' . RRU_CRLF .
                   $coursesupdated . RRU_CRLF . RRU_CRLF .
                   $coursesfailedcount . ' course shells failed to be updated:' . RRU_CRLF .
                   $coursesnotupdated . RRU_CRLF . RRU_CRLF;

        // Send the message.
        if (cace_send_mail($recipient, $sender, $subject, $emailmessage)) {
            cace_write_to_log("Sent email to $recipient");
        } else {
            cace_write_to_log("ERROR - failed to send email to $CACE_CFG->emailto");
        }
    }

    cace_write_to_log("Auto-Update course shells script complete");
    cace_write_to_log("-------------------------------------------------------------------");

}

/**
 * Purpose: add 2 records to the grade_items table and grade_categories table. The reason is to display 
 * letter grades and percentage as the course totals
 *
 * @author Carlos Chiarella
 * date    2015-04-02
 * @global object $DB Moodle database object
 * @param int $courseid
 * @param boolean true if success else false
 */
function cace_add_grade_records ($courseid) {
    // Prepare data to create a record in the grade categories table.
    $gradecatrecord = cace_prepared_gradecategory_rec($courseid);

    // Insert a record in the grade categories table.
    $gradecatrecordid = cace_insert_gradecategory_rec($gradecatrecord);

    if ($gradecatrecordid > 0 ) {
        // Update the path field for the grade category record that was created.
        cace_update_gradecategory_rec($gradecatrecordid);
        // Prepare data to create a record in the grade items table.
        $gradeitemrecord = cace_prepared_gradeitem_rec($courseid, $gradecatrecordid);
        // Insert a record in the grade items table.
        cace_insert_gradeitem_rec($gradeitemrecord);
        return true;
    } else {
        cace_write_to_log("ERROR - Failed to insert $newgradecat->courseid into mdl_grade_categories table");
        return false;
    }
}
/**
 * Purpose: prepared a record to be inserted in the grade categories table
 * 
 * @author Carlos Chiarella
 * date    2015-04-07
 * @global $CFG - configuration settings
 * @param int $courseid
 * @return object $newgradecate
 */

function cace_prepared_gradecategory_rec($courseid) {
    global $CFG;
    $newgradecat = new stdClass();
    $newgradecat->courseid            = $courseid;
    $newgradecat->depth               = 1;
    $newgradecat->fullname            = '?';
    $newgradecat->aggregation         = 10;
    $newgradecat->aggregateonlygraded = 1;
    $newgradecat->timecreated         = time();
    $newgradecat->timemodified        = time();

    return $newgradecat;
}

/**
 * Purpose: prepared a record to be inserted in the grade items table
 * 
 * @author Carlos Chiarella
 * date    2015-04-07
 * @global $CFG - configuration settings
 * @param int $courseid
 * @param int $gradecatrecordid
 * @return object $newgradeitem
 */
function cace_prepared_gradeitem_rec($courseid, $gradecatrecordid) {
    global $CFG;
    $newgradeitem = new stdClass();
    $newgradeitem->courseid            = $courseid;
    $newgradeitem->itemtype            = 'course';
    $newgradeitem->iteminstance        = $gradecatrecordid;
    $newgradeitem->display             = '32';
    $newgradeitem->timecreated          = time();
    $newgradeitem->timemodified         = time();
    return $newgradeitem;
}

/*******************************************************************************/
/*                   S U P P O R T I N G  F U N C T I O N S                    */
/*******************************************************************************/

/**
 * Send an email to recipient
 *
 * @author Andrew Zoltay
 * date    2010-05-03
 * @param string $to email recipient
 * @param string $from sender
 * @param string $subject subject of email
 * @param string $body body of the email
 * @return boolean success if email was sent or false otherwise
 */
function cace_send_mail($to, $from, $subject, $body) {
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=iso-8859-1' . "\r\n";
    $headers .= 'From: ' . $from . "\r\n" .
                'X-Mailer: PHP/' .  phpversion();
    $status = mail($to, $subject, $body, $headers);
    $sent = false;

    if ($status === true) {
        $sent = true;
    } else {
        cace_write_to_log("ERROR - cace_send_mail: failed to send email notification");
    }
    return $sent;
}


/**
 * Purpose: Write a message to the CACE log
 *          - uses php error_log function even though $message
 *            may not be an error
 *
 * @author Andrew Zoltay
 * date    2010-05-03
 * @global $CACE_CFG - configuration settings for CACE
 * @param string $message entry to be written to log
 * @return boolean success if written to log or false otherwise
 */
function cace_write_to_log($message) {
    global  $CACE_CFG;

    // Verify destination is set.
    if ($CACE_CFG->log) {
        $destfile = $CACE_CFG->log;
    } else {
        $destfile = $CFG->dirroot . '\cace.log';
    }

    $entry = date('Y-m-d H:i:s') . ' - ' . $message . RRU_CRLF;

    if (file_put_contents($destfile, $entry, FILE_APPEND)) {
        return true;
    } else {
        return false;
    }
}
