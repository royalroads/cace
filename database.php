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
 * database.php, Manage database functions for CACE plug-in
 *
 * Course Auto-Create and Enrol database functions
 * for such things as fetching and inserting database records
 *
 * 2010-04-28
 * @package      plug-in
 * @subpackage   RRU_CACE
 * @copyright    2011 Andrew Zoltay, Royal Roads University
 * @license      http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . "/dmllib.php");
require_once("cacelib.php");

defined('MOODLE_INTERNAL') || die();

define("LOCK_ACCOUNT", 'nologin');  // In mdl_user, the auth value for "No login" auth type.

/**
 * Get set of new courses from Student Information System (Agresso)
 *
 * @author Andrew Zoltay
 * date    2010-04-28
 * @global object $CACE_CFG CACE configuration object
 * @param link_identifier $agrconn for SIS db
 * @return mixed_array MS SQL result resource of courses or -1 for error
 */
function cace_fetch_sis_newcourses($agrconn) {
    global $CACE_CFG;

    if ($agrconn) {
        // Make call to db - using $CACE_CFG->monthsahead to determine how far into the future to look for new courses.
        $query = "EXEC Learn.usp_GetNewCourses @intMonthsBeforeStart = $CACE_CFG->monthsahead, @blnIsLatestVersion = 1;";
        $result = mssql_query($query, $agrconn);
        if (!$result) {
            cace_write_to_log("ERROR calling Learn.usp_GetNewCourses: " . mssql_get_last_message());
        }

        return $result;
    } else {
        cace_write_to_log("ERROR - Connection creation failed");
        return false;
    }
}

/**
 * Get new courses from SIS and load base table in Moodle
 *
 * @author Andrew Zoltay
 * date    2010-04-28
 * @param link_identifier $agrconn for SIS db
 * @return int number of courses added to newcourses table or false for error
 */
function cace_load_newcourses_table($agrconn) {
    $coursesfetched = 0;
    $coursesadded = 0;
    $result = cace_fetch_sis_newcourses($agrconn);

    if ($result) {
        $newcourse = array();
        while ($row = mssql_fetch_assoc($result)) {
            $coursesfetched++;

            $newcourse['intCourseOfferingPK'] = $row['intCourseOfferingPK'];
            $newcourse['strIDNumber'] = $row['strIDNumber'];
            $newcourse['strMoodleFullName'] = $row['strMoodleFullName'];
            $newcourse['strMoodleShortName'] = $row['strMoodleShortName'];
            $newcourse['intUnixStartDate'] = $row['intUnixStartDate'];
            $newcourse['chrProgType'] = $row['chrProgType'];
            $newcourse['chrDept'] = $row['chrDept'];
            $newcourse['chrProgram'] = $row['chrProgram'];
            $newcourse['intUnixLastUpdate'] = $row['intUnixLastUpdate'];

            // Add course.
            if (cace_save_newcourse($newcourse)) {
                $coursesadded++;
            } else {
                cace_write_to_log("Database error - could not insert " . $newcourse['strIDNumber'] . " into cace_newcourses table");
            }
        }

        // Handle shared courses (one course shared by multiple departments)
        // "There can be only one!" - Highlander, 1986.
        cace_handle_shared_courses();

        // Compare courses fetched with those added and store result in log.
        cace_write_to_log("Added $coursesadded courses to mdl_cace_newcourses");
    } else {
        // An error occurred in cace_fetch_sis_newcourses().
        $coursesadded = false;
    }

    return $coursesadded;
}

/**
 * Empty the mdl_cace_newcourses table in preparation for new load
 *
 * @author Andrew Zoltay
 * date    2010-02-11
 * @global object $DB Moodle database object
 * @return boolean success if all rows are deleted from table else failure
 */
function cace_prep_newcourses_table() {
    global $DB;

    // Delete all the rows from the mdl_cace_newcourses table.
    if ($DB->delete_records('cace_newcourses')) {
        return true;
    } else {
        cace_write_to_log("ERROR - Failed to clear mdl_cace_newcourses table prior to load");
        return false;
    }
}

/**
 * Insert new course row into newcourses table
 *
 * @author Andrew Zoltay
 * date    2010-04-28
 * @global object $DB Moodle database object
 * @param associative array $newcourse for a course
 * @return boolean true if successfully inserted, otherwise false
 */
function cace_save_newcourse($newcourse) {
    global $DB;

    $ncrecord = new stdClass();
    $ncrecord->agrcourseoffpk = $newcourse['intCourseOfferingPK'];
    $ncrecord->idnumber = $newcourse['strIDNumber'];
    $ncrecord->fullname = $newcourse['strMoodleFullName'];
    $ncrecord->shortname = $newcourse['strMoodleShortName'];
    $ncrecord->startdate = $newcourse['intUnixStartDate'];
    $ncrecord->programtype = $newcourse['chrProgType'];
    $ncrecord->department = $newcourse['chrDept'];
    $ncrecord->program = $newcourse['chrProgram'];
    $ncrecord->lastupdated = $newcourse['intUnixLastUpdate'];

    if ($DB->insert_record('cace_newcourses', $ncrecord)) {
        return true;
    } else {
        cace_write_to_log("ERROR - Failed to insert $ncrecord->idnumber into mdl_cace_newcourses table");
        return false;
    }
}

/**
 * Return a list of new SIS courses that do not exist in Moodle
 *
 * @author Andrew Zoltay
 * date    2010-04-28
 * @global object $DB Moodle database object
 * @return mixed_array of course info required to create new courses
 *         or false if db error occurs
 */
function cace_fetch_courses_to_create() {
    global $DB;

    try {
        $sql = "SELECT DISTINCT
                    nc.agrcourseoffpk, nc.idnumber, nc.fullname, nc.shortname, nc.startdate,
                    nc.programtype, department, program
                FROM {cace_newcourses} nc
                WHERE NOT EXISTS (SELECT 1 FROM {course}
                                    WHERE nc.idnumber = idnumber)";

        $coursedata = $DB->get_records_sql($sql);
        return $coursedata;

    } catch (dml_exception $e) {
        cace_write_to_log("ERROR - Failed to retrieve list of courses to create");
        return false;
    }
}

/**
 * Return a list of SIS courses that exist in Moodle
 * but have had changes made to their data in Agresso since the last update run
 *
 * @author Andrew Zoltay
 * date    2010-06-01
 * @global object $DB Moodle database object
 * @return mixed_array of course info required to update existing courses
 *         or false if db error occurs
 */
function cace_fetch_courses_to_update($lastrun) {
    global $DB;

    try {
        // Fetch any courses that have been modified in Agresso after the last update run ($lastrun).
        $sql = "SELECT DISTINCT
                    c.id,
                    nc.agrcourseoffpk, nc.idnumber, nc.fullname, nc.shortname, nc.startdate
                FROM {cace_newcourses} nc
                INNER JOIN {course} c ON (nc.idnumber = c.idnumber)
                WHERE nc.lastupdated > $lastrun";

        $coursedata = $DB->get_records_sql($sql);
        return $coursedata;

    } catch (dml_exception $e) {
        cace_write_to_log("ERROR - Failed to retrieve list of courses to update");
        return false;
    }
}

/**
 * Return a category id given a category name
 * Note: need to check top category to make sure it's from
 *       the correct program type (i.e. Graduate,Undergrad,Non-credit)
 *
 * @author Andrew Zoltay
 * date    2011-05-24
 * @global object $DB Moodle database object
 * @param string $categoryname - name of category
 * @param string $topcategory - name of top level category
 * @return int Course Category ID
 */
function cace_fetch_course_category($categoryname, $topcategory) {
    global $DB;

    try {
        $categoryid = $DB->get_field_sql("SELECT c1.id FROM {course_categories} c1
                          INNER JOIN {course_categories} c2 ON (SUBSTR(c1.path,2, LOCATE('/',c1.path,2)-2) = c2.id)
                          WHERE c1.name = ? AND c2.name = ?", array($categoryname, $topcategory));

        if (!empty($categoryid)) {
            return $categoryid;
        } else {
            return false;
        }
    } catch (dml_exception $e) {
        cace_write_to_log("ERROR - Failed to retrieve category from mdl_course_categories");
        return false;
    }
}

/**
 * Update the Moodle course with the contents from the SIS course
 * Note: $course includes id, fullname, shortname and startdate
 *
 * @author Andrew Zoltay
 * date    2011-06-02
 * @global object $DB Moodle database object
 * @param object $course course info
 * @return boolean true for success, false for failure
 */
function cace_dbupdate_course ($course) {
    global $DB;

    try {
        $result = $DB->update_record('course', $course);
        return $result;
    } catch (dml_exception $e) {
        cace_write_to_log("ERROR - Failed to update course id: '$course->id'");
        return false;
    }
}

/**
 * Purpose: insert a record in the grade categories table
 *
 * @author Carlos Chiarella
 * date    2015-04-07
 * @global object $DB Moodle database object
 * @param object $gradecatrecord
 * @return id of the record inserted or boolean false if there was a problem
 */
function cace_insert_gradecategory_rec($gradecatrecord) {
   global $DB;
   try {
       $idgradecat = $DB->insert_record('grade_categories', $gradecatrecord);
       return $idgradecat;
   } catch (Exception $e) {
      cace_write_to_log("ERROR - Failed to insert $gradecatrecord->courseid into mdl_grade_categories table");
      return false;
  }
}

/**
 * Purpose: update the path of the grade categories record that was inserted previously
 *
 * @author Carlos Chiarella
 * date    2015-04-07
 * @global object $DB Moodle database object
 * @param int $gradecatrecordid
 */
function cace_update_gradecategory_rec($gradecatrecordid) {
    global $DB;
    // Update the path field for the grade category record that was created.
    try {
        $path = '/'. $gradecatrecordid . '/';
        $DB->set_field('grade_categories', 'path', $path , array('id' => $gradecatrecordid));
    } catch (Exception $e) {
        cace_write_to_log("ERROR - failed to update the path for id: $gradecatrecordid in the mdl_grade_categories table");
    }
}

/**
 * Purpose: insert a record in the grade items table
 *
 * @author Carlos Chiarella
 * date    2015-04-07
 * @global object $DB Moodle database object
 * @param object $gradeitemrecord to be inserted
 */
function cace_insert_gradeitem_rec($gradeitemrecord) {
    global $DB;
    try {
        $idgradeitem = $DB->insert_record('grade_items', $gradeitemrecord);
    } catch (Exception $e) {
        cace_write_to_log("ERROR - failed to insert $gradeitemrecord->courseid in the mdl_grade_items table");
    }
}

/**
 * Shared courses are courses that have students from more than one program enroled in them.
 * Because the Program name is part of the course title in Moodle, the course is essentially
 * duplicated in mdl_cace_newcourses when data is exported from Agresso.
 * So, we make the title include the department instead of the program so a single course shell is created.
 *
 * @author Andrew Zoltay
 * date    2011-06-14
 * @global object $DB Moodle database object
 */
function cace_handle_shared_courses() {
    global $DB;

    // First identify the all the shared courses.
    try {
        $sql = "SELECT
                    id, agrcourseoffpk, idnumber, fullname, shortname,
                    department, program
                    FROM {cace_newcourses}
                    WHERE agrcourseoffpk IN (SELECT agrcourseoffpk FROM {cace_newcourses}
                                            GROUP BY idnumber
                                            HAVING Count(department) > 1)
                    ORDER BY agrcourseoffpk;";

        $sharedcourses = $DB->get_records_sql($sql);

    } catch (Exception $e) {
        cace_write_to_log("ERROR - Failed to retrieve list of shared courses from mdl_cace_newcourses");
    }

    $sharedcoursecount = 0;
    $failedcount = 0;
    $previouscoursepk = 0;

    foreach ($sharedcourses as $sharedcourse) {
        // Update them so there is only a single course to be imported into Moodle.
        if ($previouscoursepk != $sharedcourse->agrcourseoffpk) {

            // Reform the course name so it uses department instead of program.
            $shortname = str_replace(trim($sharedcourse->program), trim($sharedcourse->department), $sharedcourse->shortname);
            $fullname = 'SHARED - ' . str_replace(trim($sharedcourse->program), trim($sharedcourse->department), $sharedcourse->fullname);

            // Bundle up the parameters for the execute statement.
            $params = array($fullname, $shortname, $sharedcourse->department, $sharedcourse->department, $sharedcourse->agrcourseoffpk);

            $updatesql = "UPDATE {cace_newcourses}
                          SET fullname = ?,
                          shortname = ?,
                          department = ?,
                          program = ?
                          WHERE agrcourseoffpk = ?";
            try {
                $DB->execute($updatesql, $params);
                cace_write_to_log("Updated shared course $sharedcourse->idnumber's title to '$fullname'");
                $sharedcoursecount++;
            } catch (Exception $e) {
                cace_write_to_log("ERROR - Failed to update shared course $sharedcourse->idnumber");
                $failedcount;
            }

            $previouscoursepk = $sharedcourse->agrcourseoffpk;

        }
    }
    cace_write_to_log("$sharedcoursecount 'shared' courses were updated and $failedcount 'shared' courses failed to be updated");

}
