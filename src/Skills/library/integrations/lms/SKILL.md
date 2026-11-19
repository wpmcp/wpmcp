---
name: Courses: Tutor LMS, LifterLMS, LearnDash
description: Build and reorder courses, sections, lessons and quizzes as drafts; read enrollments masked; enroll learners only after opt-in.
version: 1.0.0
tier: pro
tags: [integrations, lms]
requires_any:
  - wpmcp/plugin-data-read#tutor-list-courses
  - wpmcp/plugin-data-read#lifterlms-list-courses
  - wpmcp/plugin-data-read#learndash-list-courses
---

# Courses in Tutor LMS, LifterLMS and LearnDash

The three plugins share one set of operations, prefixed by plugin: `tutor-`,
`lifterlms-` and `learndash-`. Reads go to `wpmcp/plugin-data-read`, writes
to `wpmcp/plugin-data-write`. The examples use Tutor; swap the prefix for the
plugin the site runs (`list-operations` shows which have `dependency_met`).

A course is a tree. Tutor calls the middle level a topic, LifterLMS a
section, LearnDash a lesson; the operations call it a section and take the
plugin's own ids.

## Read first

1. `tutor-list-courses` to find the course (search matches the title).
2. `tutor-get-course` for the tree in order: sections, their lessons and
   quizzes, with ids and positions. Pass `questions: false` to skip quiz
   questions on a big course.
3. `tutor-list-enrollments` only when the request is about learners. Names
   and emails are masked, and it needs the list_users capability.

## Worked example: add a module to a course

User: "Add a section called Week 3 with two lessons to the Photography course."

1. `tutor-list-courses` with `search: "Photography"`, take the course id.
2. `tutor-get-course` to see the existing sections and where Week 3 belongs.
3. `tutor-add-section` with the course id, the title and the 0-based
   position. Note the new section id in the result.
4. `tutor-add-lesson` twice with that section as `parent_id`. Lessons default
   to publish; pass `status: "draft"` if the user wants to review first.
5. `tutor-get-course` again and show the user the new order.

## Other writes

- `tutor-create-course` starts a course as a draft.
- `tutor-update-item` edits the title, content, excerpt or status of any
  course, section, lesson or quiz. Publishing is a status change: confirm it
  with the user, learners see it at once.
- `tutor-move-item` reorders within a course; siblings are renumbered.
- `tutor-add-quiz` adds an empty quiz. Questions are written in the plugin's
  own quiz editor, not through these tools; say so.

## LearnDash learner records

`learndash-list-user-courses` reads one user's courses and progress.
`learndash-enroll-user`, `learndash-unenroll-user` and
`learndash-reset-progress` change a real learner's access and history: they
are off until the site owner opts in (the refusal is `opt_in_required`) and
need edit_users. Name the learner and the course and get a yes before each
call. There is no bulk enrollment; do not loop over a user list without the
user seeing the list first.

## Verify and undo

Read the course back after every structural change. Each write is
snapshotted: an edit is undone with `wpmcp/rollback-operation`, and adding
sections, lessons or a course is undone as a batch with
`wpmcp/rollback-session` (a new course goes to the trash). Never edit course
structure through post meta or the database tools; the plugins keep their
own ordering data that a raw write would break.
