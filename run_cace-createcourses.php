<?php
define('CLI_SCRIPT', true); // Set up to be run from command line
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
 * run_cace_createcourses.php, called by CRON to do CACE work
 *
 * Purpose: Auto-create course shells based on external data
 * Steps:
 *   1. Clean up mdl_cace_newcourses by deleting any rows in the table
 *   2. Pull new course information from Student Information System (Agresso)
 *   3. Save new course data in mdl_cace_newcourses table
 *   4. Rename any "shared" courses (a course that is shared by multiple programs) so only a single course is imported
 *   5. Use a query between mdl_cace_newcourses and mdl_course to identify all courses that need to be created
 *   6. Loop through courses to be created and create them through Moodle function
 *   7. Send notification email listing which courses were created and which courses failed and why
 *
 * 2011-04-28
 * @package      plug-in
 * @subpackage   cace
 * @copyright    2011 Andrew Zoltay, Royal Roads University
 * @license      http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("cacelib.php");

cace_import_course_shells();
?>
