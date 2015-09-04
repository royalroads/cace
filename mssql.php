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
 * mssql.php, MS SQL Server functions for CACE plug-in
 *
 * Course Auto-Create and Enrol MS SQL Server functions
 * for such things as managing database connections
 *
 * 2010-04-28
 * @package      plug-in
 * @subpackage   RRU_CACE
 * @copyright    2010 Andrew Zoltay, Royal Roads University
 * @license      http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
defined('MOODLE_INTERNAL') || die();

require_once("cacelib.php");
require_once("cace_config.php");

// This script is only available from within the Moodle environment.
defined('MOODLE_INTERNAL') || die();

/**
 * Connect to MS SQL Server
 *
 * @author Andrew Zoltay
 * date    2010-04-28
 * @global $CACE_CFG configuration settings for CACE
 * @return MS SQL Link identifier or false
 */
function cace_mssql_connect() {
    global $CACE_CFG;

    cace_write_to_log('Connecting to ' . $CACE_CFG->mssqlserver);

    $mssqllink = mssql_connect($CACE_CFG->mssqlserver, $CACE_CFG->mssqluser, $CACE_CFG->mssqlpwd) or die('Unable to connect to MS SQL Server');
    if (mssql_select_db ($CACE_CFG->mssqldb, $mssqllink)) {
        return $mssqllink;
    } else {
        cace_write_to_log('ERROR - failed to select ' . $CACE_CFG->mssqldb . ' database');
        return false;
    }
}

/**
 * Close connection to MS SQL Server
 *
 * @author Andrew Zoltay
 * date    2010-04-28
 * @param MS SQL Link identifier $conn to be closed
 * @return none
 */
function cace_mssql_close(&$conn) {
    if (!mssql_close($conn)) {
        cace_write_to_log('Failed to close MS SQL Server connection');
    }
}
?>