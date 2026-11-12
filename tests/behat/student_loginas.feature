@local @local_vektradiscuss @javascript
Feature: No discussion in a "log in as" session
  In order to keep the discussion the student's own act (REQ-084)
  As a teacher logged in as a student
  I must not be able to start or reach a discussion, and Vektra must not be asked

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Student   | One      |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity | name     | course | idnumber |
      | quiz     | Quiz 1   | C1     | quiz1    |
      | assign   | Assign 1 | C1     | assign1  |
    And the Vektra assess stub is enabled
    And the Vektra assess stub returns state "eligible" for "quiz1"
    And the Vektra assess stub returns state "eligible" for "assign1"

  Scenario Outline: Logged in as the student, the panel shows the login-as state and Vektra receives nothing
    Given I log in as "admin"
    And I am on the "student1" "user > profile" page
    And I follow "Log in as"
    And I press "Continue"
    When I am on the "<activity>" Activity page
    Then I should see "Not available while logged in as another user" in the ".local-vektradiscuss-panel" "css_element"
    And the Vektra discussion button "Discuss this assessment" should be disabled with a reason
    And the Vektra assess stub received 0 requests in total

    Examples:
      | activity |
      | quiz1    |
      | assign1  |

  Scenario: Logged in as the student, the transcript download is refused before any request to Vektra
    Given the Vektra assess stub has the transcript of session 1 of "quiz1" for "student1" named "C1_42_7_s1_20261108.html"
    And I log in as "admin"
    And I am on the "student1" "user > profile" page
    And I follow "Log in as"
    And I press "Continue"
    And I am on the "quiz1" Activity page
    When I open the transcript download of session 1 of "quiz1"
    Then I should see "Not available while logged in as another user."
    And the Vektra assess stub received 0 requests in total
