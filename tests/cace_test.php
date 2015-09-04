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
 * Unit test for CACE
 *
 * @package    CACE
 * @subpackage rru
 * @category   phpunit
 * @author     Carlos Chiarella
 * @copyright  2015 Royal Roads University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/cace/cacelib.php');

class local_cace_test extends advanced_testcase {
    public static $includecoverage = array('lib/moodlelib.php');
    protected function setUp() {
        // Reset the database state after the test is done.
        $this->resetAfterTest(true);
    }

    /*
     * Verify that this function returns the development notes of the course.
     */
    public function test_cace_load_dev_notes_fromfile_nonempty() {
        // Retrieve Development Notes page content from file.
        $pagecontent = cace_load_devnotes_from_file();
        $this->assertNotEmpty($pagecontent);
    }

    /*
     * Verify that the newcourses table is empty/ clean.
     */
    public function test_cace_prep_newcourse() {
        $result = cace_prep_newcourses_table();
        $this->assertEquals(true, $result);
    }

    /*
     * Test connection with agresso.
     */
    public function test_cace_mssql_connect() {
        $conn = cace_mssql_connect();
        $this->assertNotEmpty($conn);
        cace_mssql_close($conn);
    }

    /*
     * Test number of courses added to newcourses table.
     */
    public function test_cace_load_newcourses() {
        $conn = cace_mssql_connect();
        $newcoursecount = cace_load_newcourses_table($conn);
        $this->assertGreaterThan(0, $newcoursecount);
        cace_mssql_close($conn);
    }

    /*
     * Verify that the system returns a list of courses that does not exist in Moodle.
     */
    public function test_cace_fetch_courses_to_create() {
         $newcourses = cace_fetch_courses_to_create();
         $this->assertContainsOnlyInstancesOf($newcourses, array());
    }

    /*
     * Test the preparation of info for creating a new cace course.
     */
    public function test_cace_prep_newcourse() {
        global $DB;

        $rowdata            = new stdClass ();
        $rowdata->id        = 764;
        $rowdata->idnumber  = 'SOCC100__Y1516F-01';
        $rowdata->fullname  = 'SOCC100 [SOCCER-DIP OL] March 20 2015 - Soccer';
        $rowdata->shortname = 'SOCC100 [SOCCER-DIP OL]';
        $rowdata->startdate = 1426918467;;
        $rowdata->programtype = 'Undergraduate (4.33)';
        $rowdata->department  = 'TOURHOSPMGMT';
        $rowdata->program     = 'TOURHOSPMGMT';

        $newcourse = cace_prep_newcourse($rowdata);

        $this->assertNotEmpty($newcourse);

    }
    /*
     * Test the preparartion of data for a page to a created new cace course.
     */
    public function test_cace_prep_page_data() {
        global $DB;

        // Generate a dummy category for the dummy course.
        $this->dummycategory = $this->getDataGenerator()->create_category(array('name' => 'Dummy category',
                'parent' => null));
        // Generate a dummy course.
        $createdcourse = $this->getDataGenerator()->create_course(array('name' => 'Dummy Course',
                'category' => $this->dummycategory->id));
        $pagecontent = cace_load_devnotes_from_file();

        $data = cace_prep_page_data($createdcourse, 0, $pagecontent);

        $this->assertNotEmpty($data);

    }

    /*
     * Test the addition of a page in a created new cace course.
     */
    public function test_cace_add_page_resource() {
        global $DB;

        // Generate a dummy category for the dummy course.
        $this->dummycategory = $this->getDataGenerator()->create_category(array('name' => 'Dummy category',
                'parent' => null));
        // Generate a dummy course.
        $createdcourse = $this->getDataGenerator()->create_course(array('name' => 'Dummy Course',
                'category' => $this->dummycategory->id));
        $pagecontent = cace_load_devnotes_from_file();

        // Get Page module info.
        $module = $DB->get_record('modules', array('name' => PAGE_MOD), '*', MUST_EXIST);

        // Build the data class that stores the page info.
        $data = new stdClass();
        $data->section          = 0;  // The section number itself - relative!!! (section column in course_sections table).
        $data->course           = $createdcourse->id;  // Course ID.
        $data->coursemodule     = '';           // Course module id - leave empty since we're adding a new resource.
        $data->module           = $module->id;
        $data->modulename       = $module->name;
        $data->groupmode        = $createdcourse->groupmode;
        $data->groupingid       = $createdcourse->defaultgroupingid;
        $data->groupmembersonly = 0;
        $data->id               = '';
        $data->instance         = '';           // CM instance - leave empty since we're adding a new resource.
        $data->type             = 'course';     // Default to 'course'.
        $data->revision         = 1;            // first revision.

        $data->name             = 'Development Notes';  // Page resource name.
        $data->intro            = 'Development Notes Introduction'; // Page resource Description.
        $data->introformat      = 1;            // Default to HTML format.

        $data->page['text']     = $pagecontent;     // Page resource content - the meat.
        $data->page['format']   = '1';          // Default to HTML format.
        $data->page['itemid']   = '';           // Draft item id - default to empty string since no draft.

        $data->printheading     = 1;            // Display page name toggle - default to true.
        $data->printintro       = 0;            // Display page description - default to false.
        $data->visible          = 1;            // Default Visible to true.

        $data->add              = PAGE_MOD;     // Hard code to 'page'.
        $data->return           = 0;            // Must be false if this is an add.

        $data->display          = RESOURCELIB_DISPLAY_OPEN;

        $result = cace_add_page_resource($createdcourse, $data);

        // Returns true if the page has been added.
        $this->assertEquals(true, $result);

    }

    /*
     * Test update default course blocks.
     */
    public function test_cace_update_default_course_blocks() {
        global $DB;

        // Generate a dummy category for the dummy course.
        $this->dummycategory = $this->getDataGenerator()->create_category(array('name' => 'Dummy category',
                'parent' => null));
        // Generate a dummy course.
        $createdcourse = $this->getDataGenerator()->create_course(array('name' => 'Dummy Course',
                'category' => $this->dummycategory->id));
        $result = cace_update_default_course_blocks($createdcourse);

        // Returns true if the page has been added.
        $this->assertEquals(true, $result);

    }
}