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
 * install.php, make necessary DB changes
 *
 * Make changes to the database for customizations that
 * are required by the CACE middleware
 *
 * 2011-06-01
 * @package      cace
 * @copyright    2011 Andy Zoltay, Royal Roads University
 * @license      http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This script is only available from within the Moodle environment.
defined('MOODLE_INTERNAL') || die();

/**
 * Hook into upgradelib.php to install any CACE db requirements
 */
function xmldb_local_cace_install() {
    global $DB;

    // Initialize the CACE last update run timestamp.
    set_config('autoupdate_last_run', time(), 'local_cace');
}