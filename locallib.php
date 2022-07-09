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

const GRADE_PRECISION = 2;
const NUM_RANGES = 10;

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
            $dbparams = array('id' => $grade->assignment);
            $assign = $DB->get_record('assign', $dbparams);

            $due = $assign->duedate;
            $now = time();

            // Do not show chart when students can still make submissions
            if ($due && ($now < $due)) {
                return '';
            }

            // Do not show chart when student has not made a submission
            if ($grade->grade == -1) {
                return '';
            }

            $maxgrade = intval($assign->grade);
            $ranges = $this->generateGradeRanges($maxgrade);

            $dbparams = array('assignment' => $grade->assignment);
            $assignGrades = $DB->get_records('assign_grades', $dbparams);
            $lastAttemptGrades = $this->filterLastAttemptGrades($assignGrades);

            $numGradesInRange = $this->calculateNumGradesInRange($ranges, $lastAttemptGrades);

            $chart = $this->generateChart($ranges, $numGradesInRange);
            return $OUTPUT->render($chart);
        } catch (dml_exception | coding_exception $e) {
            return '';
        }
    }

    /**
     * Filter only last attempt grades
     *
     * @param array $assignGrades all grades for given assignment
     * @return array              last attempt grades
     */
    private function filterLastAttemptGrades(array $assignGrades): array {
        $latestGrades = [];
        foreach ($assignGrades as $singleGrade) {
            $userid = $singleGrade->userid;
            $grade = $singleGrade->grade;
            $attempt = $singleGrade->attemptnumber;
            if (array_key_exists($userid, $latestGrades)) {
                $entry = $latestGrades[$userid];
                $latestAttempt = $entry['attempt'];
                if ($attempt > $latestAttempt) {
                    $this->updateUserGrade($latestGrades, $userid, $attempt, $grade);
                }
            } else {
                $this->updateUserGrade($latestGrades, $userid, $attempt, $grade);
            }
        }
        return array_column(array_values($latestGrades), 'grade');
    }

    /**
     * Format float value
     *
     * @param float $float   number to format
     * @return string        formatted number
     */
    private function floatToString(float $float): string {
        return number_format($float, GRADE_PRECISION,'.','');
    }

    /**
     * Calculate number of grades in the given ranges
     *
     * @param int $maxgrade  max possible grade
     * @param int $numranges number of ranges to return (excluding last range [maxgrade, maxgrade])
     * @return array         grade ranges
     */
    private function generateGradeRanges(int $maxgrade, int $numranges = NUM_RANGES): array {
        $rangeEnds = range($maxgrade / $numranges, $maxgrade, $maxgrade / $numranges);
        $ranges = array([0, $rangeEnds[0]]);

        for ($i = 1; $i < count($rangeEnds); $i++) {
            $rangeEnd = $rangeEnds[$i];
            $rangeStart = $ranges[count($ranges) - 1][1];
            $ranges[] = [$rangeStart, $rangeEnd];
        }

        $ranges[] = [$maxgrade, $maxgrade];
        return $ranges;
    }

    /**
     * Calculate number of grades in the given ranges
     *
     * @param array $ranges grade ranges
     * @param array $grades grades
     * @return array        number of grades in the consecutive ranges
     */
    private function calculateNumGradesInRange(array $ranges, array $grades): array {
        $numGradesInRange = array_fill(0, count($ranges), 0);
        $ranges = array_map(function ($range) {
            return [$this->floatToString($range[0]), $this->floatToString($range[1])];
        }, $ranges);

        foreach ($grades as $grade) {
            for ($i = count($ranges) - 1; $i >= 0; $i--) {
                $range = $ranges[$i];
                $rangeStart = $range[0];
                if (bccomp($grade, $rangeStart, GRADE_PRECISION) >= 0) {
                    $numGradesInRange[$i]++;
                    break;
                }
            }
        }

        return array_values($numGradesInRange);
    }

    /**
     * Save grade from user's latest attempt
     *
     * @param array $latestGrades array to update
     * @param int $userid         user ID
     * @param int $attempt        attempt number
     * @param string $grade       grade
     * @return void
     */
    private function updateUserGrade(array & $latestGrades, int $userid, int $attempt, string $grade) {
        $newEntry = new stdClass();
        $newEntry->attempt = $attempt;
        $newEntry->grade = $grade;
        $latestGrades[$userid] = $newEntry;
    }

    /**
     * Construct the chart for given grades and ranges.
     *
     * @param array $ranges           grade ranges
     * @param array $numGradesInRange number of grades in a given range
     * @return chart_bar              bar chart object
     * @throws coding_exception
     */
    private function generateChart(array $ranges, array $numGradesInRange): chart_bar {
        global $CFG;
        $chart = new chart_bar();

        $series = new chart_series(
            get_string('series_name', 'assignfeedback_grades_chart'),
            $numGradesInRange
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
