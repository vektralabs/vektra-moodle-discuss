@local @local_vektradiscuss
Feature: The teacher's Discussions page
  In order to follow and manage the assessment discussions of a quiz
  As a teacher
  I need the list of sessions and the actions on them

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | teacher2 | Teacher   | Two      |
      | student1 | Student   | One      |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | teacher        |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name   | course | idnumber |
      | quiz     | Quiz 1 | C1     | quiz1    |
    And the Vektra assess stub is enabled
    And the Vektra assess stub lists a closed session of "student1" in "quiz1" with 2 active sessions

  Scenario: An editing teacher sees the sessions and grants a new session with a reason
    Given I log in as "teacher1"
    When I am on the Vektra discussions page of "quiz1"
    Then I should see "Student One"
    And I should see "finished by the student"
    And I should see "Round 1: sent"
    And I should see "End 2 discussion(s) now"
    And I should see "Students are not notified by email when a new session is granted."
    When I set the field "Reason for a new session after #1" to "Connection lost during the session"
    And I press "Grant a new session"
    Then I should see "A new session was granted. The student is not notified by email."
    And the Vektra assess stub received 1 POST request to "reset" of "quiz1"
    And the last Vektra assess stub POST to "reset" of "quiz1" should have the field "reason"

  Scenario: A non-editing teacher sees the list without actions and without the override holder
    Given I log in as "teacher2"
    When I am on the Vektra discussions page of "quiz1"
    Then I should see "Student One"
    And I should not see "Grant a new session"
    And I should not see "End 2 discussion(s) now"
    And I should not see "Latest override"
