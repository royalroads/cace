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
 * upgrade.php, make necessary DB changes
 *
 * Make changes to the database for customizations that
 * are required by the CACE middleware
 *
 * 2012-01-09
 * @package      cace
 * @copyright    2011 Andy Zoltay, Royal Roads University
 * @license      http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This script is only available from within the Moodle environment.
defined('MOODLE_INTERNAL') || die();

/**
 * Hook into upgradelib.php to make any CACE db changes
 * 
 * @author Andrew Zoltay
 * date    2012-01-09
 * @global object $CFG Moodle configuration object
 * @global object $DB Database object
 * @global type $OUTPUT
 * @param int $oldversion previous CACE plugin version
 * @return bool true for success, false for failure
 */
function xmldb_local_cace_upgrade($oldversion) {
    global $CFG, $DB, $OUTPUT;

    $dbman = $DB->get_manager(); // Loads ddl manager and xmldb classes.

    if ($oldversion < 2012010901) {
        // Handle removing of cace_user_fin_lock table for versions prior to 2012010901.
        $table = new xmldb_table('cace_user_fin_lock');
        if ($dbman->table_exists($table)) {
            if ($dbman->drop_table($table)) {
                $OUTPUT->notification('Successfully drop cace_user_fin_lock table');
            } else {
                $OUTPUT->notification('Failed to drop cace_user_fin_lock table!');
                return false;
            }
        }
    }

    if ($oldversion < 2012050201) {
        // NOTE!!! This is against Moodle development "rules", but is required for performance reasons
        // Add mdl_cace_uvw_moodle_enrolments.
        $viewsql = 'CREATE OR REPLACE VIEW mdl_cace_uvw_moodle_enrolments 
                    AS
                    SELECT DISTINCT
                        c.id AS courseid, c.fullname, c.idnumber, 
                        ue.id AS userenrolid, 
                        e.enrol AS enroltype, 
                        u.id AS userid, u.username, u.idnumber AS student_pk, 
                        r.roleid
                    FROM mdl_role_assignments r
                    INNER JOIN mdl_user u ON (u.id = r.userid)
                    INNER JOIN mdl_context ct ON (ct.id = r.contextid)
                    INNER JOIN mdl_user_enrolments ue ON (ue.userid = u.id)
                    INNER JOIN mdl_enrol e ON (e.id = ue.enrolid AND e.courseid = ct.instanceid)
                    INNER JOIN mdl_course c ON (c.id = ct.instanceid)
                    WHERE ct.contextlevel = 50';
        try {
            $DB->execute($viewsql);
        } catch (ddl_exception $e) {
            $OUTPUT->notification('Failed to create mdl_cace_uvw_moodle_enrolments view!');
            debugging($e->getMessage());
        }
    }

    if ($oldversion < 2012121801) {
        $sql = 'DROP TABLE IF EXISTS mdl_cace_enrolments';
        try {
            $DB->execute($sql);
        } catch (ddl_exception $e) {
            $OUTPUT->notification('Failed to drop mdl_cace_enrolments table');
            debugging($e->getMessage());
        }

        $sql = 'DROP VIEW IF EXISTS mdl_cace_uvw_moodle_enrolments';
        try {
            $DB->execute($sql);
        } catch (ddl_exception $e) {
            $OUTPUT->notification('Failed to drop mdl_cace_uvw_moodle_enrolments view');
            debugging($e->getMessage());
        }
    }

    return true;
}