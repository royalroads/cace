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
 * cace_config.example.php, configuration settings for CACE plug-in
 *
 * EXAMPLE Course Auto-Create and Enrol configuration file for
 * such things as remote db connection info, etc.
 *
 * Before you can use CACE, you must:
 * (1) Copy or rename this file cace_config.php
 * (2) Enter your MS SQL Server credentials
 * (3) Configure other options below, such as email destination and log location, as needed.
 *
 * 2011-02-10
 * @package      plug-in
 * @subpackage   RRU_CACE
 * @copyright    2011 Andrew Zoltay, Royal Roads University
 * @license      http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

unset($CACE_CFG);

// Agresso_RR connection info
$CACE_CFG = new stdClass();
$CACE_CFG->mssqlserver = '<server>:<port>';
$CACE_CFG->mssqldb = '<database>';
$CACE_CFG->mssqluser = '<user>';
$CACE_CFG->mssqlpwd = '<password>';

// Miscellaneous CACE configuration settings
$CACE_CFG->emailto = 'administrator@your-school.edu';
$CACE_CFG->defaultcatid = 5;
$CACE_CFG->log = '/var/web/moodledata/logs/cace.log';
$CACE_CFG->monthsahead = 9;         // The number of months into the future to look for new/updated courses
$CACE_CFG->res_name = 'Development Notes';
$CACE_CFG->res_intro = 'Development Notes Introduction';
$CACE_CFG->default_courses = '3';   // List of Moodle course ids in which all students are to be enrolled
$CACE_CFG->admin_user = 2;

?>
