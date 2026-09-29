<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Activity settings form.
 *
 * @package    mod_aianatomy
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/aianatomy/lib.php');

/**
 * Activity settings form.
 */
class mod_aianatomy_mod_form extends moodleform_mod {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $config = get_config('mod_aianatomy');

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        // Anatomy.
        $mform->addElement('header', 'anatomyhdr', get_string('anatomy', 'mod_aianatomy'));
        $mform->setExpanded('anatomyhdr');
        global $DB;
        $packs = \mod_aianatomy\local\pack::list();
        $mform->addElement(
            'selectgroups', 'pack', get_string('pack', 'mod_aianatomy'),
            \mod_aianatomy\local\pack::list_grouped()
        );
        $mform->addHelpButton('pack', 'pack', 'mod_aianatomy');
        $mform->setDefault('pack', isset($packs['hand_right']) ? 'hand_right' : array_key_first($packs));
        if ($this->_instance && $DB->record_exists('aianatomy_attempt', ['aianatomyid' => $this->_instance])) {
            // Changing the pack would orphan students' attempts and mastery.
            $mform->hardFreeze('pack');
            $mform->addElement('static', 'packlocked', '', get_string('packlocked', 'mod_aianatomy'));
        }
        $fieldgroup = [];
        foreach (array_merge(array_diff(\mod_aianatomy\local\pack::FIELDS, ['hint']), ['relationships']) as $field) {
            $fieldgroup[] = $mform->createElement(
                'advcheckbox', 'studyfieldset[' . $field . ']', '',
                get_string('field_' . $field, 'mod_aianatomy')
            );
            $default = in_array($field, \mod_aianatomy\local\pack::DEFAULT_STUDYFIELDS, true) ? 1 : 0;
            $mform->setDefault('studyfieldset[' . $field . ']', $default);
        }
        $mform->addGroup($fieldgroup, 'studyfieldsgroup', get_string('studyfields', 'mod_aianatomy'), '<br>', false);
        $mform->addHelpButton('studyfieldsgroup', 'studyfields', 'mod_aianatomy');
        $mform->addElement(
            'select', 'explode', get_string('explode', 'mod_aianatomy'), [
                0 => get_string('explode_none', 'mod_aianatomy'),
                60 => get_string('explode_small', 'mod_aianatomy'),
                100 => get_string('explode_standard', 'mod_aianatomy'),
                150 => get_string('explode_wide', 'mod_aianatomy'),
            ]
        );
        $mform->setDefault('explode', 100);
        $mform->addHelpButton('explode', 'explode', 'mod_aianatomy');
        $mform->addElement(
            'select', 'showcontext', get_string('showcontext', 'mod_aianatomy'), [
                1 => get_string('showcontext_faded', 'mod_aianatomy'),
                2 => get_string('showcontext_shown', 'mod_aianatomy'),
                0 => get_string('showcontext_hidden', 'mod_aianatomy'),
            ]
        );
        $mform->setDefault('showcontext', 1);
        $mform->addHelpButton('showcontext', 'showcontext', 'mod_aianatomy');
        $mform->addElement('textarea', 'studytip', get_string('studytip', 'mod_aianatomy'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('studytip', PARAM_TEXT);
        $mform->addHelpButton('studytip', 'studytip', 'mod_aianatomy');

        // Language and voiceover.
        $mform->addElement('header', 'languagehdr', get_string('languagevoice', 'mod_aianatomy'));
        $mform->setExpanded('languagehdr');
        $mform->addElement(
            'select', 'language', get_string('activitylanguage', 'mod_aianatomy'),
            \mod_aianatomy\local\language::options()
        );
        $mform->addHelpButton('language', 'activitylanguage', 'mod_aianatomy');
        $mform->setDefault('language', \mod_aianatomy\local\language::normalise(current_language()));
        $mform->addElement('static', 'languagenote', '', get_string('activitylanguage_note', 'mod_aianatomy'));

        // Voiceover is part of every activity (cards, prompts, questions and feedback); it is prepared before students
        // start. Only the voice and automatic reading are chosen here.
        $mform->addElement(
            'static', 'voiceincluded', get_string('voiceover', 'mod_aianatomy'),
            get_string('voiceover_included', 'mod_aianatomy')
        );
        $mform->addHelpButton('voiceincluded', 'voiceover', 'mod_aianatomy');
        if (!\mod_aianatomy\local\ai\tts_lmslabs::is_ready()) {
            $mform->addElement(
                'static', 'voicenotready', '',
                html_writer::div(get_string('voicenotconfigured', 'mod_aianatomy'), 'alert alert-warning')
            );
        }
        $voices = [];
        foreach (\mod_aianatomy\local\voice::VOICES as $v => $gender) {
            $voices[$v] = get_string(
                'voicename_option', 'mod_aianatomy',
                ['name' => $v, 'gender' => get_string('voice_' . $gender, 'mod_aianatomy')]
            );
        }
        $mform->addElement('select', 'voicename', get_string('voicename', 'mod_aianatomy'), $voices);
        $mform->setDefault('voicename', 'Kore');
        $mform->addElement(
            'advcheckbox', 'voiceauto', get_string('voiceauto', 'mod_aianatomy'),
            get_string('voiceauto_desc', 'mod_aianatomy')
        );

        // Modes.
        $mform->addElement('header', 'modeshdr', get_string('modes', 'mod_aianatomy'));
        $mform->setExpanded('modeshdr');
        $mform->addElement(
            'advcheckbox',
            'allowstudy',
            get_string('modestudy', 'mod_aianatomy'),
            get_string('modestudy_desc', 'mod_aianatomy')
        );
        $mform->setDefault('allowstudy', 1);
        $mform->addElement(
            'advcheckbox',
            'allowpractice',
            get_string('modepractice', 'mod_aianatomy'),
            get_string('modepractice_desc', 'mod_aianatomy')
        );
        $mform->setDefault('allowpractice', 1);
        $mform->addElement(
            'advcheckbox',
            'allowtest',
            get_string('modetest', 'mod_aianatomy'),
            get_string('modetest_desc', 'mod_aianatomy')
        );
        $mform->setDefault('allowtest', 1);
        $mform->addHelpButton('allowtest', 'modetest', 'mod_aianatomy');

        // Test settings.
        $mform->addElement('header', 'testhdr', get_string('testsettings', 'mod_aianatomy'));
        $attemptoptions = [0 => get_string('unlimited')];
        for ($i = 1; $i <= 10; $i++) {
            $attemptoptions[$i] = $i;
        }
        $mform->addElement('select', 'maxattempts', get_string('maxattempts', 'mod_aianatomy'), $attemptoptions);
        $mform->addElement(
            'duration',
            'timelimit',
            get_string('timelimit', 'mod_aianatomy'),
            ['optional' => true, 'defaultunit' => 60]
        );
        $mform->addHelpButton('timelimit', 'timelimit', 'mod_aianatomy');
        $counts = [0 => get_string('quizcount_none', 'mod_aianatomy')];
        foreach ([3, 5, 8, 10, 15, 20, 30] as $n) {
            $counts[$n] = $n;
        }
        $mform->addElement('select', 'quizcount', get_string('quizcount', 'mod_aianatomy'), $counts);
        $mform->setDefault('quizcount', 10);
        $mform->addHelpButton('quizcount', 'quizcount', 'mod_aianatomy');
        $weights = [];
        for ($w = 0; $w <= 100; $w += 10) {
            $weights[$w] = get_string('identweight_option', 'mod_aianatomy', ['ident' => $w, 'quiz' => 100 - $w]);
        }
        $mform->addElement('select', 'identweight', get_string('identweight', 'mod_aianatomy'), $weights);
        $mform->setDefault('identweight', 60);
        $mform->addHelpButton('identweight', 'identweight', 'mod_aianatomy');

        // Experience.
        $mform->addElement('header', 'experiencehdr', get_string('experience', 'mod_aianatomy'));
        $mform->addElement(
            'advcheckbox',
            'sounds',
            get_string('sounds', 'mod_aianatomy'),
            get_string('sounds_desc', 'mod_aianatomy')
        );
        $mform->setDefault('sounds', $config->defaultsounds ?? 1);

        // Grade.
        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', 100);
        $mform->addElement(
            'select',
            'grademethod',
            get_string('grademethod', 'mod_aianatomy'),
            [
            AIANATOMY_GRADEHIGHEST => get_string('gradehighest', 'mod_aianatomy'),
            AIANATOMY_GRADEAVERAGE => get_string('gradeaverage', 'mod_aianatomy'),
            AIANATOMY_GRADEFIRST => get_string('gradefirst', 'mod_aianatomy'),
            AIANATOMY_GRADELAST => get_string('gradelast', 'mod_aianatomy'),
            ]
        );
        $mform->addHelpButton('grademethod', 'grademethod', 'mod_aianatomy');
        $mform->hideIf('grademethod', 'grade[modgrade_type]', 'eq', 'none');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Loads the study field checkboxes from the stored list.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        if (isset($defaultvalues['studyfields'])) {
            $on = array_filter(array_map('trim', explode(',', (string)$defaultvalues['studyfields'])));
            foreach (array_merge(\mod_aianatomy\local\pack::FIELDS, ['relationships']) as $field) {
                $defaultvalues['studyfieldset'][$field] = in_array($field, $on, true) ? 1 : 0;
            }
        }
    }

    /**
     * Returns the form element name with the completion suffix (Moodle 4.3+).
     *
     * @param string $name
     * @return string
     */
    protected function suffixed(string $name): string {
        return method_exists($this, 'get_suffix') ? $name . $this->get_suffix() : $name;
    }

    /**
     * Adds custom completion rules.
     *
     * @return array element names
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $name = $this->suffixed('completionfinish');
        $mform->addElement(
            'advcheckbox',
            $name,
            get_string('completionfinish', 'mod_aianatomy'),
            get_string('completionfinish_desc', 'mod_aianatomy')
        );
        $mform->addHelpButton($name, 'completionfinish', 'mod_aianatomy');
        return [$name];
    }

    /**
     * Whether a custom completion rule is enabled.
     *
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data[$this->suffixed('completionfinish')]);
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['allowstudy']) && empty($data['allowpractice']) && empty($data['allowtest'])) {
            $errors['allowtest'] = get_string('errornomode', 'mod_aianatomy');
        }
        if (\mod_aianatomy\local\ai\tts_lmslabs::is_ready()) {
            // Only offer voices LMS Labs lists for the activity language (when the catalogue can be read).
            $locale = \mod_aianatomy\local\language::locale($data['language'] ?? 'en');
            $available = \mod_aianatomy\local\voice::available($locale, $known);
            if ($known && !$available) {
                $errors['voicename'] = get_string('voicenolocale', 'mod_aianatomy', $locale);
            } else if ($known && !in_array($data['voicename'] ?? '', $available, true)) {
                $errors['voicename'] = get_string(
                    'voicenotinlocale', 'mod_aianatomy',
                    ['locale' => $locale, 'voices' => implode(', ', $available)]
                );
            }
        }
        return $errors;
    }
}
