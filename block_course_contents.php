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
 * Provides the {@see block_course_contents} class.
 *
 * @package    block_course_contents
 * @copyright  2009 David Mudrak <david@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/course/format/lib.php');

/**
 * Defines the Course contents block behaviour.
 *
 * Course contents block generates a table of course contents based on each
 * section title and/or summary.
 *
 * @copyright 2009 David Mudrak <david@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_course_contents extends block_base {

    /**
     * Initializes the block, called by the constructor
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_course_contents');
    }

    /**
     * Amend the block instance after it is loaded
     */
    public function specialization() {
        if (!empty($this->config->blocktitle)) {
            $this->title = $this->config->blocktitle;
        } else {
            $this->title = get_string('config_blocktitle_default', 'block_course_contents');
        }
    }

    /**
     * Which page types this block may appear on
     * @return array
     */
    public function applicable_formats() {
        return array('site-index' => true, 'course-view-*' => true);
    }

    /**
     * Does the block have a global settings.
     *
     * @return bool
     */
    public function has_config() {
        return true;
    }

    /**
     * Populate this block's content object
     * @return stdClass block content info
     */
    public function get_content() {
        global $OUTPUT;

        if (!is_null($this->content)) {
            return $this->content;
        }

        $selected = optional_param('section', null, PARAM_INT);

        $this->content = new stdClass();
        $this->content->footer = '';
        $this->content->text   = '';

        if (empty($this->instance)) {
            return $this->content;
        }

        $format = course_get_format($this->page->course);
        $course = $format->get_course(); // Needed to have numsections property available.

        if (!$format->uses_sections()) {
            if (debugging()) {
                $this->content->text = get_string('notusingsections', 'block_course_contents');
            }
            return $this->content;
        }

        $sections = $format->get_sections();

        if (empty($sections)) {
            return $this->content;
        }

        $context = context_course::instance($course->id);
        $globalconfig = get_config('block_course_contents');

        $text = html_writer::start_tag('ul', array('class' => 'section-list list-group'));
        foreach ($sections as $section) {
            $i = $section->section;
            if (isset($course->numsections) && ($i > $course->numsections)) {
                // Support for legacy formats that still provide numsections (see MDL-57769).
                break;
            }
            if (!$section->uservisible) {
                continue;
            }

            if ($globalconfig->autotitle === 'forced_off') {
                $autotitle = false;

            } else if ($globalconfig->autotitle === 'forced_on') {
                $autotitle = true;

            } else if (empty($this->config) or !isset($this->config->autotitle)) {
                // Instance not configured, use the globally defined default value.
                if ($globalconfig->autotitle === 'optional_on') {
                    $autotitle = true;
                } else {
                    $autotitle = false;
                }

            } else if (!empty($this->config->autotitle)) {
                $autotitle = true;

            } else {
                $autotitle = false;
            }

            $title = null;

            if (!empty($section->name)) {
                // If the section has explicit title defined, it is used.
                $title = format_string($section->name, true, array('context' => $context));

            } else if ($autotitle) {
                // Attempt to extract the title from the section summary.
                $summary = file_rewrite_pluginfile_urls($section->summary, 'pluginfile.php', $context->id, 'course',
                    'section', $section->id);
                $summary = '<nolink>' . $summary . '</nolink>';
                $summary = format_text($summary, $section->summaryformat, array('para' => false, 'context' => $context));
                $title = \block_course_contents\autotitle::extract_title($summary);
                $title = format_string($title, true, ['context' => $context]);
                unset($summary);
            }

            // If at this point we have no title available, use the default one.
            if (empty($title)) {
                $title = $format->get_section_name($section);
            }

            // Check if we want to display a course link.  Checked forced status from global config first,
            // then check block instance settings.
            if ($globalconfig->display_course_link === 'forced_off') {
                $displaycourselink = false;

            } else if ($globalconfig->display_course_link === 'forced_on') {
                $displaycourselink = true;

            } else if (empty($this->config) or !isset($this->config->display_course_link)) {
                // Instance not configured, use the globally defined default value.
                if ($globalconfig->display_course_link === 'optional_on') {
                    $displaycourselink = true;
                } else {
                    $displaycourselink = false;
                }
            } else if (!empty($this->config->display_course_link)) {
                $displaycourselink = true;

            } else {
                $displaycourselink = false;

            }

            // If we want to display the course link, display it before displaying the section 0.
            if (($i == 0) && ($displaycourselink)) {
                $sectionclass = 'section-item list-group-item';

                if ($selected === null) {
                    $sectionclass .= ' selected active';
                }
                $text .= html_writer::start_tag('li', array('class' => $sectionclass));

                $text .= html_writer::span($OUTPUT->pix_icon('i/course', ''), 'section-number');

                if (!empty($this->config->display_course_link_text)) {
                    $anchortext = $this->config->display_course_link_text;
                } else if (!empty($globalconfig->display_course_link_text)) {
                    $anchortext = $globalconfig->display_course_link_text;
                } else {
                    $anchortext = $course->shortname;
                }

                if ($selected === null) {
                    $text .= ' '.$anchortext;
                } else {
                    $text .= ' '.html_writer::link(course_get_url($course), $anchortext);
                }

                $text .= html_writer::end_tag('li');
            }

            // Check if we want to hide section 0.  Checked forced status from global config first,
            // then check block instance settings.
            if ($globalconfig->hide_section_0 === 'forced_off') {
                $hidesection0 = false;

            } else if ($globalconfig->hide_section_0 === 'forced_on') {
                $hidesection0 = true;

            } else if (empty($this->config) or !isset($this->config->hide_section_0)) {
                // Instance not configured, use the globally defined default value.
                if ($globalconfig->hide_section_0 === 'optional_on') {
                    $hidesection0 = true;
                } else {
                    $hidesection0 = false;
                }
            } else if (!empty($this->config->hide_section_0)) {
                $hidesection0 = true;

            } else {
                $hidesection0 = false;

            }

            if (($i == 0) && ($hidesection0)) {
                continue;
            }

            $sectionclass = 'section-item list-group-item';
            $numberclass = 'badge badge-secondary';

            if (isset($selected) && $i == $selected) {
                $sectionclass .= ' selected active';
                $numberclass .= ' badge-light';
            }

            if ($format->is_section_current($section)) {
                $sectionclass .= ' current active';
                $numberclass .= ' badge-light';
            }

            $text .= html_writer::start_tag('li', array('class' => $sectionclass));

            // Check if we want to enumerate section 0.  Checked forced status from global config first,
            // then check block instance settings.
            if ($globalconfig->enumerate_section_0 === 'forced_off') {
                $enumeratesection0 = false;
            } else if ($globalconfig->enumerate_section_0 === 'forced_on') {
                $enumeratesection0 = true;
            } else if (empty($this->config) or !isset($this->config->enumerate_section_0 )) {
                // Instance not configured, use the globally defined default value.
                if ($globalconfig->enumerate_section_0 === 'optional_on') {
                    $enumeratesection0 = true;
                } else {
                    $enumeratesection0 = false;
                }
            } else if (!empty($this->config->enumerate_section_0 )) {
                $enumeratesection0 = true;
            } else {
                $enumeratesection0 = false;
            }

            if ( ($i == 0)  && ($enumeratesection0 == false) ) {
                $enumerate = false;

            } else if ($globalconfig->enumerate === 'forced_off') {
                $enumerate = false;

            } else if ($globalconfig->enumerate === 'forced_on') {
                $enumerate = true;

            } else if (empty($this->config) or !isset($this->config->enumerate)) {
                // Instance not configured, use the globally defined default value.
                if ($globalconfig->enumerate === 'optional_on') {
                    $enumerate = true;
                } else {
                    $enumerate = false;
                }

            } else if (!empty($this->config->enumerate)) {
                $enumerate = true;

            } else {
                $enumerate = false;
            }

            $sectionnumber = $i;

            // If enumerating and showing section 0, then increment section number.
            if ($enumerate && $enumeratesection0) {
                $sectionnumber++;
            }

            if ($enumerate) {
                $title = html_writer::span($sectionnumber, 'section-number ' . $numberclass) . ' ' .
                    html_writer::span($title, 'section-title');

            } else {
                $title = html_writer::span($title, 'section-title not-enumerated');
            }

            if (is_null($selected) or $i <> $selected) {
                $text .= html_writer::link($format->get_view_url($section), $title, ['class' => $section->visible ? '' : 'dimmed']);
            } else {
                $text .= $title;
            }
            $text .= html_writer::end_tag('li');
        }
        $text .= html_writer::end_tag('ul');

        $this->content->text = $text;
        return $this->content;
    }
}
