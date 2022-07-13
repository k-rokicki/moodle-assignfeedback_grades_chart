# Grades chart - Moodle assign feedback plugin

A simple Moodle assign feedback plugin,
allowing students to check how other students
handled a given task by presenting their grades on a chart.

![Grades chart preview](./preview.png)

Tested in: Moodle 3.11

## Quick install

Download zip package, extract to a folder and upload this folder
into `mod/assign/feedback` directory.

## Changelog

- Version 2022070900
  - Compatibility with Moodle 3.11
- Version 2022071000
  - Add missing `/classes/privacy/provider.php`
- Version 2022071200
  - Use `bcmath` library for all float calculations
- Version 2022071201
  - Fix coding style problems
- Version 2022071202
  - Fix coding style problems (again)
- Version 2022071300
  - Do not show charts in Grading table
  - Do not show chart when the grading type is not "Point"
  - Show chart when grading user submission

## About

Developed by: Kacper Rokicki <k.k.rokicki@gmail.com>

GIT: https://github.com/k-rokicki/moodle-assignfeedback_grades_chart
