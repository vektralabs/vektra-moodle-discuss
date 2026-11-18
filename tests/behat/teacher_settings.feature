@local @local_vektradiscuss
Feature: The discussion settings in the quiz form
  In order to set up the assessment discussion of a quiz
  As a teacher
  I need a settings section that shows Vektra's settings, sends only my changes and never shows back the solution

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name   | course | idnumber |
      | quiz     | Quiz 1 | C1     | quiz1    |
    And the Vektra assess stub is enabled

  Scenario: The section reads Vektra's settings and sends only what changed
    When I am on the "Quiz 1" "quiz activity editing" page logged in as "teacher1"
    Then I should see "Discussion with the assistant"
    And I should see "Set (not shown)"
    And I should see "Let the assistant search the course material"
    And the Vektra assess stub received 1 GET request to "config" of "quiz1"
    And the Vektra assess stub received 1 POST request to "gate-refresh" of "quiz1"
    When I set the field "vektradiscuss_cap" to "45"
    And I press "Save and return to course"
    Then I should see "The discussion settings were saved."
    And the Vektra assess stub received 1 PUT request to "config" of "quiz1"
    And the Vektra assess stub received 1 GET request to "config" of "quiz1"
    And the Vektra assess stub received 1 POST request to "gate-refresh" of "quiz1"
    And the last Vektra assess stub PUT to "config" of "quiz1" should have the field "cap_minutes"
    And the last Vektra assess stub PUT to "config" of "quiz1" should have the field "expected_version"
    And the last Vektra assess stub PUT to "config" of "quiz1" should not have the field "solution"
    And the last Vektra assess stub PUT to "config" of "quiz1" should not have the field "rag_enabled"

  Scenario: Turning course material search on sends the teacher's confirmation
    When I am on the "Quiz 1" "quiz activity editing" page logged in as "teacher1"
    And I set the field "vektradiscuss_rag" to "1"
    And I press "Save and return to course"
    Then I should see "Confirm the course material conditions to turn the search on."
    And the Vektra assess stub received 0 PUT requests to "config" of "quiz1"
    When I set the field "vektradiscuss_ragattest" to "1"
    And I press "Save and return to course"
    Then I should see "Course material search is on."
    And the Vektra assess stub received 1 PUT request to "config" of "quiz1"
    And the last Vektra assess stub PUT to "config" of "quiz1" should have the field "rag_enabled"
    And the last Vektra assess stub PUT to "config" of "quiz1" should have the field "rag_attested"

  Scenario: Saving the quiz without a change to the discussion sends nothing
    When I am on the "Quiz 1" "quiz activity editing" page logged in as "teacher1"
    And I press "Save and return to course"
    Then the Vektra assess stub received 0 PUT requests to "config" of "quiz1"

  Scenario: When Vektra cannot be reached the section is frozen and the save sends nothing
    Given the Vektra assess stub answers GET "config" of "quiz1" with status 503 and:
      """
      {"error": {"code": "ERR-ASSESS-001", "message": "unavailable"}}
      """
    When I am on the "Quiz 1" "quiz activity editing" page logged in as "teacher1"
    Then I should see "Vektra cannot be reached: the discussion settings cannot be changed now."
    When I press "Save and return to course"
    Then the Vektra assess stub received 0 PUT requests to "config" of "quiz1"

  Scenario: An enable that Vektra refuses is explained after saving
    Given the Vektra assess stub answers PUT "config" of "quiz1" with status 400 and:
      """
      {"error": {"code": "ERR-ASSESS-022", "message": "fields",
        "details": {"fields": [{"field": "enabled", "code": "quiz_without_close_date"}]}}}
      """
    When I am on the "Quiz 1" "quiz activity editing" page logged in as "teacher1"
    And I set the field "vektradiscuss_enabled" to "1"
    And I press "Save and return to course"
    Then I should see "The discussion settings were not saved:"
    And I should see "the quiz needs a close date before the discussion can be enabled"

  @javascript
  Scenario: Show reads a saved text on demand and Hide removes it from the page
    Given the Vektra assess stub answers POST "config-reveal" of "quiz1" with status 200 and:
      """
      {"field": "solution", "value": "Expected answer: 42"}
      """
    When I am on the "Quiz 1" "quiz activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    Then I should not see "Expected answer: 42"
    And the Vektra assess stub received 0 POST requests to "config-reveal" of "quiz1"
    When I click on "button[data-vektradiscuss-reveal='solution']" "css_element"
    Then I should see "Expected answer: 42"
    And I should see "Hide"
    And the last Vektra assess stub POST to "config-reveal" of "quiz1" should have the field "field"
    When I click on "button[data-vektradiscuss-reveal='solution']" "css_element"
    Then I should not see "Expected answer: 42"
    When I set the field "vektradiscuss_cap" to "45"
    And I press "Save and return to course"
    Then the last Vektra assess stub PUT to "config" of "quiz1" should not have the field "solution"

  @javascript
  Scenario: Show explains when Vektra cannot read the text
    Given the Vektra assess stub answers POST "config-reveal" of "quiz1" with status 503 and:
      """
      {"error": {"code": "ERR-ASSESS-031", "message": "unavailable", "details": {"reason": "reveal_unavailable", "retry_after_s": 2}}}
      """
    When I am on the "Quiz 1" "quiz activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I click on "button[data-vektradiscuss-reveal='recipients']" "css_element"
    Then I should see "Vektra cannot read the text right now; try again in a few seconds."
