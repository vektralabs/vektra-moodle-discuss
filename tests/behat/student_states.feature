@local @local_vektradiscuss @javascript
Feature: Student entry point states
  In order to know whether and when I can discuss an assessment
  As a student
  I need the results page to show the state Vektra reports, with focusable disabled buttons and their reason

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

  Scenario Outline: Each state renders its sentence and button on the quiz and the assignment page
    Given the Vektra assess stub returns state "<state>" for "<activity>"
    When I am on the "<activity>" Activity page logged in as student1
    Then I should see "<text>" in the ".local-vektradiscuss-panel" "css_element"
    And the Vektra discussion button "<button>" should be <buttonstate>
    And the Vektra assess stub received 1 request to "/api/v1/assess/tokens"
    And the Vektra assess stub received 1 request to "/api/v1/assess/state"
    And every Vektra assess stub mint asserted no login-as

    Examples:
      | activity | state         | text                                               | button                               | buttonstate             |
      | quiz1    | not_graded    | Available when the grade is published.             | Discuss this assessment              | disabled with a reason  |
      | assign1  | not_graded    | Available when the grade is published.             | Discuss this assessment              | disabled with a reason  |
      | quiz1    | not_open      | Available after the activity has definitively closed. | Discuss this assessment           | disabled with a reason  |
      | assign1  | not_open      | Available after the activity has definitively closed. | Discuss this assessment           | disabled with a reason  |
      | quiz1    | eligible      | You can discuss this assessment with the assistant. | Discuss this assessment             | enabled                 |
      | assign1  | eligible      | You can discuss this assessment with the assistant. | Discuss this assessment             | enabled                 |
      | quiz1    | active        | 37 minutes left                                    | Resume                               | enabled                 |
      | assign1  | active        | 37 minutes left                                    | Resume                               | enabled                 |
      | quiz1    | unavailable   | The discussion is temporarily unavailable.         | Discuss this assessment              | disabled with a reason  |
      | assign1  | unavailable   | The discussion is temporarily unavailable.         | Discuss this assessment              | disabled with a reason  |
      | quiz1    | reset_granted | Your teacher has granted a new discussion session. | Discuss this assessment (session #2) | enabled                 |
      | assign1  | reset_granted | Your teacher has granted a new discussion session. | Discuss this assessment (session #2) | enabled                 |
      | quiz1    | ended         | The discussion is no longer available.             | Discuss this assessment              | disabled with a reason  |
      | assign1  | ended         | The discussion is no longer available.             | Discuss this assessment              | disabled with a reason  |

  Scenario Outline: A closed discussion suggests downloading a copy
    Given the Vektra assess stub returns state "closed" for "<activity>"
    When I am on the "<activity>" Activity page logged in as student1
    Then I should see "The discussion of this assessment has ended." in the ".local-vektradiscuss-panel" "css_element"
    And I should see "We recommend that you download a copy of the transcript." in the ".local-vektradiscuss-panel" "css_element"

    Examples:
      | activity |
      | quiz1    |
      | assign1  |

  Scenario Outline: Not enabled renders nothing
    Given the Vektra assess stub returns state "not_enabled" for "<activity>"
    When I am on the "<activity>" Activity page logged in as student1
    Then ".local-vektradiscuss-panel" "css_element" should not exist
    And the Vektra assess stub received 1 request to "/api/v1/assess/state"

    Examples:
      | activity |
      | quiz1    |
      | assign1  |

  Scenario Outline: A Vektra failure shows the generic sentence and no technical detail
    Given the Vektra assess stub fails state with HTTP <status> for "quiz1"
    When I am on the "quiz1" Activity page logged in as student1
    Then I should see "The discussion is temporarily unavailable." in the ".local-vektradiscuss-panel" "css_element"
    And I should not see "ERR-ASSESS"
    And I should not see "stub-request-id"
    And I should not see "stub failure"

    Examples:
      | status |
      | 500    |
      | 503    |
      | 429    |

  Scenario: Dismissing the pre-session screen sends nothing
    Given the Vektra assess stub returns state "eligible" for "quiz1"
    And I am on the "quiz1" Activity page logged in as student1
    When I click on "Discuss this assessment" "button" in the ".local-vektradiscuss-panel" "css_element"
    Then I should see "Before you start" in the ".modal-dialog" "css_element"
    And I should see "Duration: 30 minutes. If this does not match what you were told, contact your teacher before starting." in the ".modal-dialog" "css_element"
    And I should see "depends on your enrolment in this course" in the ".modal-dialog" "css_element"
    And I should see "Teacher One (Teacher)" in the ".modal-dialog" "css_element"
    And I click on "Not now" "button" in the ".modal-dialog" "css_element"
    And ".modal-dialog" "css_element" should not be visible
    And the Vektra assess stub received 0 requests to "/api/v1/assess/sessions"
    And the Vektra assess stub received 1 request to "/api/v1/assess/tokens"
    And the Vektra assess stub received 2 requests in total
