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
 * Dynamic UX helpers for relative due dates inside activity mod_form.
 *
 * @module     local_enrolduedate/modform
 * @copyright  2025 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    var FIXED_DATE_ENABLED_IDS = [
        'id_allowsubmissionsfromdate_enabled',
        'id_duedate_enabled',
        'id_cutoffdate_enabled'
    ];

    /**
     * Unchecks standard fixed assignment date selectors when relative mode is enabled.
     *
     * @returns {void}
     */
    var clearFixedDateCheckboxes = function() {
        FIXED_DATE_ENABLED_IDS.forEach(function(id) {
            var checkbox = document.getElementById(id);
            if (checkbox && checkbox.checked) {
                checkbox.checked = false;
                checkbox.dispatchEvent(new Event('change', {bubbles: true}));
            }
        });
    };

    /**
     * Filters reference activity options to match completion vs view completion capability.
     *
     * @param {HTMLSelectElement} triggerSelect Trigger type select element.
     * @param {HTMLSelectElement} refSelect Reference activity select element.
     * @returns {void}
     */
    var filterReferenceActivities = function(triggerSelect, refSelect) {
        if (!triggerSelect || !refSelect) {
            return;
        }
        var trigger = triggerSelect.value;
        var requireCompletion = (trigger === 'activity_completed' || trigger === 'activity_viewed');
        var requireView = (trigger === 'activity_viewed');

        Array.prototype.forEach.call(refSelect.options, function(option) {
            if (option.value === '0' || option.value === '') {
                option.hidden = false;
                option.disabled = false;
                return;
            }
            var hasCompletion = option.getAttribute('data-has-completion') === '1';
            var hasView = option.getAttribute('data-has-view-completion') === '1';
            var allowed = !requireCompletion || (requireView ? hasView : hasCompletion);
            option.hidden = !allowed;
            option.disabled = !allowed;
        });

        var selectedOption = refSelect.options[refSelect.selectedIndex];
        if (selectedOption && selectedOption.disabled) {
            refSelect.value = '0';
        }
    };

    /**
     * Initializes event listeners on the activity settings form.
     *
     * @returns {void}
     */
    var init = function() {
        var enabledCheckbox = document.getElementById('id_enrolduedate_enabled');
        var triggerSelect = document.getElementById('id_enrolduedate_triggertype');
        var refSelect = document.getElementById('id_enrolduedate_refcmid');

        if (enabledCheckbox) {
            if (enabledCheckbox.checked) {
                clearFixedDateCheckboxes();
            }
            enabledCheckbox.addEventListener('change', function() {
                if (enabledCheckbox.checked) {
                    clearFixedDateCheckboxes();
                }
            });
        }

        if (triggerSelect && refSelect) {
            filterReferenceActivities(triggerSelect, refSelect);
            triggerSelect.addEventListener('change', function() {
                filterReferenceActivities(triggerSelect, refSelect);
            });
        }
    };

    return {
        init: init
    };
});
