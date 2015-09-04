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
 * Populate RRU Moodle with a bunch of course categories based
 *          on Department codes, Program codes, and Specialization codes
 *
 * Note: depends on existance of mdl_tmp_category_source table
 * 2011-05-30
 * @package      plug-in
 * @subpackage   RRU_CACE
 * @copyright    2011 Andrew Zoltay, Royal Roads University
 * @license      http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');

// This script is only available from within the Moodle environment.
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . "/dmllib.php");
require_once($CFG->libdir . "/datalib.php");

global $DB;

echo 'Get categories to create...</br>';

$categorysource = $DB->get_records_select('tmp_category_source', '1=1');

echo 'Start adding categories...</br>';
echo '--------------------------</br>';

// Loop through categories from source.
foreach ($categorysource as $category) {
    // Save the categories.
    if ($parentid = save_category($category->programtype, 0)) {
        if ($parentid = save_category($category->department, $parentid)) {
            $parentid = save_category($category->program, $parentid);
        }
    }
}
echo '--------------------------</br>';
echo 'Finished!!</br>';
echo '--------------------------</br>';


/**
 * Save the course category
 *
 * @author Andrew Zoltay
 * date    2011-05-30
 * @global type $DB
 * @param string $categoryname
 * @param int $parent
 * @return boolean - success or failure
 */
function save_category($categoryname, $parent = 0) {
    global $DB;

    // First off - don't save any categories that are null.
    if (empty($categoryname) or is_null($categoryname)) {
        return false;
    }

    // Next check to see if category already exists.
    $categoryid = $DB->get_field_select('course_categories', 'id', 'name = ? AND parent = ?', array($categoryname, $parent));

    // Finally save the category if it hasnt been found.
    if ($categoryid) {
        echo 'Found existing cat: ' . $categoryname . ' with id=' . $categoryid . '</br>';
        return $categoryid;
    } else {
        // Save the new category.
        $newcategory->name = $categoryname;
        $newcategory->description = ''; // Don't define a description.
        $newcategory->descriptionformat = 1; // Default to HTML format.
        $newcategory->parent = $parent;
        $newcategory->sortorder = 999;

        $newcategory->id = $DB->insert_record('course_categories', $newcategory);
        $newcategory->context = get_context_instance(CONTEXT_COURSECAT, $newcategory->id);
        $categorycontext = $newcategory->context;
        mark_context_dirty($newcategory->context->path);

        // Now that we have the context, we need to update the category's path info.
        $DB->update_record('course_categories', $newcategory);
        fix_course_sortorder();

        echo 'Added new cat: ' . $categoryname . ' with id=' . $newcategory->id . '</br>';
        return $newcategory->id;
    }
}

?>
