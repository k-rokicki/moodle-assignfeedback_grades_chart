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
 * This file contains the definition for the library class for grades_chart feedback plugin
 *
 * @package   assignfeedback_grades_chart
 * @copyright 2022 Kacper Rokicki <k.k.rokicki@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\chart_bar;
use core\chart_series;

/**
 * Library class for grades_chart feedback plugin extending feedback plugin base class.
 *
 * @package   assignfeedback_grades_chart
 * @copyright 2022 Kacper Rokicki <k.k.rokicki@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_feedback_grades_chart extends assign_feedback_plugin {

    /**
     * Precision for grade's float calculations done by bcmath library.
     */
    const ASSIGNFEEDBACK_GRADES_CHART_GRADE_PRECISION = 2;

    /**
     * Default number of ranges to show on the chart excluding last range [maxgrade, maxgrade].
     */
    const ASSIGNFEEDBACK_GRADES_CHART_DEFAULT_NUM_RANGES = 10;

    /**
     * To avoid float rounding errors, all the math below is using bcmath library functions.
     */

    /**
     * Get the name of the online grades_chart feedback plugin.
     * @return string
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string('pluginname', 'assignfeedback_grades_chart');
    }

    /**
     * Display the chart in the feedback status table.
     *
     * @param stdClass $grade
     * @param bool $showviewlink (Always set to false).
     * @return string
     */
    public function view_summary(stdClass $grade, & $showviewlink): string {
        $showviewlink = false;
        return $this->view($grade);
    }

    /**
     * Display the chart in the feedback table.
     *
     * @param stdClass $grade
     * @return string
     */
    public function view(stdClass $grade): string {
        global $DB, $PAGE;

        if ($PAGE->url->get_param('action') == 'grading') {
            return '';
        }

        try {
            $dbparams = array('id' => $grade->assignment);
            $assign = $DB->get_record('assign', $dbparams);

            $due = $assign->duedate;
            $now = time();

            // Do not show chart when students can still make submissions.
            if ($due && ($now < $due)) {
                return '';
            }

            // Do not show chart when student has not made a submission.
            if ($grade->grade == -1) {
                return '';
            }

            return $this->get_rendered_chart($assign, $grade);
        } catch (dml_exception | coding_exception $e) {
            return '';
        }
    }

    /**
     * Get form elements for grading form.
     *
     * @param stdClass $grade
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @param int $userid The userid we are currently grading
     * @return bool true if elements were added to the form
     */
    public function get_form_elements_for_user($grade, MoodleQuickForm $mform, stdClass $data, $userid): bool {
        global $DB;

        try {
            $dbparams = array('id' => $grade->assignment);
            $assign = $DB->get_record('assign', $dbparams);
            $chart = $this->get_rendered_chart($assign, $grade);

            if ($chart == '') {
                return false;
            }

            $html = '<div class="form-group row fitem has-popout">
                        <div class="col-md-3 col-form-label d-flex pb-0 pr-md-0">
                            <p class="mb-0 d-inline">
                                ' . $this->get_name() . '
                            </p>
                        </div>
                        <div class="col-md-9 form-inline align-items-start felement">
                            <div class="w-100 m-0 p-0 border-0">
                               ' . $chart . '
                            </div>
                        </div>
                    </div>';
            $mform->addElement('html', $html);
            return true;
        } catch (dml_exception | coding_exception $e) {
            return false;
        }
    }

    /**
     * Get the rendered chart html.
     *
     * @param stdClass $assign
     * @param stdClass $grade
     * @return string
     * @throws coding_exception
     * @throws dml_exception
     */
    private function get_rendered_chart(stdClass $assign, stdClass $grade): string {
        global $DB, $OUTPUT;

        $maxgrade = $assign->grade;

        // Do not show chart when the grading type is not "Point".
        if ($maxgrade == -1) {
            return '';
        }

        $ranges = $this->generate_grade_ranges($maxgrade);

        $dbparams = array('assignment' => $grade->assignment);
        $assigngrades = $DB->get_records('assign_grades', $dbparams);
        $lastattemptgrades = $this->filter_last_attempt_grades($assigngrades);

        $numgradesinrange = $this->calculate_num_grades_in_range($ranges, $lastattemptgrades);

        $chart = $this->generate_chart($ranges, $numgradesinrange);
        return $OUTPUT->render($chart);
    }

    /**
     * Filter only last attempt grades
     *
     * @param array $assigngrades all grades for given assignment
     * @return array              last attempt grades
     */
    private function filter_last_attempt_grades(array $assigngrades): array {
        $latestgrades = [];
        foreach ($assigngrades as $singlegrade) {
            $userid = $singlegrade->userid;
            $grade = $singlegrade->grade;
            $attempt = $singlegrade->attemptnumber;
            if (array_key_exists($userid, $latestgrades)) {
                $entry = $latestgrades[$userid];
                $latestattempt = $entry['attempt'];
                if ($attempt > $latestattempt) {
                    $this->update_user_grade($latestgrades, $userid, $attempt, $grade);
                }
            } else {
                $this->update_user_grade($latestgrades, $userid, $attempt, $grade);
            }
        }
        return array_column(array_values($latestgrades), 'grade');
    }

    /**
     * Calculate number of grades in the given ranges
     *
     * @param string $maxgrade max possible grade
     * @param int $ranges      number of ranges to return (excluding last range [$maxgrade, $maxgrade])
     * @return array           grade ranges
     */
    private function generate_grade_ranges(string $maxgrade, int $ranges = ASSIGNFEEDBACK_GRADES_CHART_DEFAULT_NUM_RANGES): array {
        $numranges = strval($ranges);
        $step = bcdiv($maxgrade, $numranges, ASSIGNFEEDBACK_GRADES_CHART_GRADE_PRECISION);
        $ranges = array();

        $rangestart = '0';

        do {
            $rangeend = bcadd($rangestart, $step, ASSIGNFEEDBACK_GRADES_CHART_GRADE_PRECISION);
            $ranges[] = [$rangestart, $rangeend];
            $rangestart = $rangeend;
            $comparison = bccomp($rangestart, $maxgrade, ASSIGNFEEDBACK_GRADES_CHART_GRADE_PRECISION);
        } while ($comparison < 0);

        $ranges[] = [$rangestart, $rangestart];

        return $ranges;
    }

    /**
     * Calculate number of grades in the given ranges
     *
     * @param array $ranges grade ranges
     * @param array $grades grades
     * @return array        number of grades in the consecutive ranges
     */
    private function calculate_num_grades_in_range(array $ranges, array $grades): array {
        $numgradesinrange = array_fill(0, count($ranges), 0);

        foreach ($grades as $grade) {
            for ($i = count($ranges) - 1; $i >= 0; $i--) {
                $range = $ranges[$i];
                $rangestart = $range[0];
                if (bccomp($grade, $rangestart, ASSIGNFEEDBACK_GRADES_CHART_GRADE_PRECISION) >= 0) {
                    $numgradesinrange[$i]++;
                    break;
                }
            }
        }

        return array_values($numgradesinrange);
    }

    /**
     * Save grade from user's latest attempt
     *
     * @param array $latestgrades array to update
     * @param int $userid         user ID
     * @param int $attempt        attempt number
     * @param string $grade       grade
     * @return void
     */
    private function update_user_grade(array & $latestgrades, int $userid, int $attempt, string $grade) {
        $newentry = new stdClass();
        $newentry->attempt = $attempt;
        $newentry->grade = $grade;
        $latestgrades[$userid] = $newentry;
    }

    /**
     * Construct the chart for given grades and ranges.
     *
     * @param array $ranges           grade ranges
     * @param array $numgradesinrange number of grades in a given range
     * @return chart_bar              bar chart object
     * @throws coding_exception
     */
    private function generate_chart(array $ranges, array $numgradesinrange): chart_bar {
        global $CFG;
        $chart = new chart_bar();

        $series = new chart_series(
            get_string('series_name', 'assignfeedback_grades_chart'),
            $numgradesinrange
        );

        $chart->add_series($series);
        $chart->set_labels(array_map(function ($range) {
            return '[' . $range[0] . '; ' . $range[1] . ($range[0] != $range[1] ? ')' : ']');
        }, $ranges));

        $xaxis = $chart->get_xaxis(0, true);
        $xaxis->set_label(
            get_string('xasis_label', 'assignfeedback_grades_chart')
        );
        $chart->set_xaxis($xaxis);

        $yaxis = $chart->get_yaxis(0, true);
        $yaxis->set_label(
            get_string('yasis_label', 'assignfeedback_grades_chart')
        );
        $yaxis->set_stepsize(1);
        $chart->set_yaxis($yaxis);

        $chart->set_legend_options(['display' => false]);
        $CFG->chart_colorset = ['#0f6cbf'];

        return $chart;
    }

    /**
     * Return true if there is no chart to display.
     *
     * @param stdClass $grade
     * @return bool
     */
    public function is_empty(stdClass $grade): bool {
        return $this->view($grade) == '';
    }

    /**
     * Return the plugin configs for external functions.
     *
     * @return array the list of settings
     * @since Moodle 3.2
     */
    public function get_config_for_external(): array {
        return (array) $this->get_config();
    }
}
