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

defined('MOODLE_INTERNAL') || die();

/**
 * Precision for grade's float calculations done by bcmath library.
 */
const GRADE_PRECISION = 2;

/**
 * Default number of ranges to show on the chart excluding last range [$max_grade, $max_grade].
 */
const DEFAULT_NUM_RANGES = 10;

/**
 * To avoid float rounding errors, all the math below is using bcmath library functions.
 */

/**
 * Library class for grades_chart feedback plugin extending feedback plugin base class.
 *
 * @package   assignfeedback_grades_chart
 * @copyright 2022 Kacper Rokicki <k.k.rokicki@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_feedback_grades_chart extends assign_feedback_plugin {

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
        global $DB, $OUTPUT;

        try {
            $db_params = array('id' => $grade->assignment);
            $assign = $DB->get_record('assign', $db_params);

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

            $max_grade = $assign->grade;
            $ranges = $this->generate_grade_ranges($max_grade);

            $db_params = array('assignment' => $grade->assignment);
            $assign_grades = $DB->get_records('assign_grades', $db_params);
            $last_attempt_grades = $this->filter_last_attempt_grades($assign_grades);

            $num_grades_in_range = $this->calculate_num_grades_in_range($ranges, $last_attempt_grades);

            $chart = $this->generate_chart($ranges, $num_grades_in_range);
            return $OUTPUT->render($chart);
        } catch (dml_exception | coding_exception $e) {
            return '';
        }
    }

    /**
     * Filter only last attempt grades
     *
     * @param array $assign_grades all grades for given assignment
     * @return array               last attempt grades
     */
    private function filter_last_attempt_grades(array $assign_grades): array {
        $latest_grades = [];
        foreach ($assign_grades as $single_grade) {
            $user_id = $single_grade->userid;
            $grade = $single_grade->grade;
            $attempt = $single_grade->attemptnumber;
            if (array_key_exists($user_id, $latest_grades)) {
                $entry = $latest_grades[$user_id];
                $latest_attempt = $entry['attempt'];
                if ($attempt > $latest_attempt) {
                    $this->update_user_grade($latest_grades, $user_id, $attempt, $grade);
                }
            } else {
                $this->update_user_grade($latest_grades, $user_id, $attempt, $grade);
            }
        }
        return array_column(array_values($latest_grades), 'grade');
    }

    /**
     * Calculate number of grades in the given ranges
     *
     * @param string $max_grade max possible grade
     * @param int $ranges       number of ranges to return (excluding last range [$max_grade, $max_grade])
     * @return array            grade ranges
     */
    private function generate_grade_ranges(string $max_grade, int $ranges = DEFAULT_NUM_RANGES): array {
        $num_ranges = strval($ranges);
        $step = bcdiv($max_grade, $num_ranges, GRADE_PRECISION);
        $ranges = array();

        $range_start = '0';

        do {
            $range_end = bcadd($range_start, $step, GRADE_PRECISION);
            $ranges[] = [$range_start, $range_end];
            $range_start = $range_end;
            $comparison = bccomp($range_start, $max_grade, GRADE_PRECISION);
        } while ($comparison < 0);

        $ranges[] = [$range_start, $range_start];

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
        $num_grades_in_range = array_fill(0, count($ranges), 0);

        foreach ($grades as $grade) {
            for ($i = count($ranges) - 1; $i >= 0; $i--) {
                $range = $ranges[$i];
                $range_start = $range[0];
                if (bccomp($grade, $range_start, GRADE_PRECISION) >= 0) {
                    $num_grades_in_range[$i]++;
                    break;
                }
            }
        }

        return array_values($num_grades_in_range);
    }

    /**
     * Save grade from user's latest attempt
     *
     * @param array $latest_grades array to update
     * @param int $user_id         user ID
     * @param int $attempt         attempt number
     * @param string $grade        grade
     * @return void
     */
    private function update_user_grade(array & $latest_grades, int $user_id, int $attempt, string $grade) {
        $new_entry = new stdClass();
        $new_entry->attempt = $attempt;
        $new_entry->grade = $grade;
        $latest_grades[$user_id] = $new_entry;
    }

    /**
     * Construct the chart for given grades and ranges.
     *
     * @param array $ranges              grade ranges
     * @param array $num_grades_in_range number of grades in a given range
     * @return chart_bar                 bar chart object
     * @throws coding_exception
     */
    private function generate_chart(array $ranges, array $num_grades_in_range): chart_bar {
        global $CFG;
        $chart = new chart_bar();

        $series = new chart_series(
            get_string('series_name', 'assignfeedback_grades_chart'),
            $num_grades_in_range
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
