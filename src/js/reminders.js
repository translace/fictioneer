// =============================================================================
// SETUP
// =============================================================================

var /** @type {Number} */ fcn_userRemindersTimeout;
var /** @type {Object} */ fcn_reminders;

// Initialize
document.addEventListener('fcnUserDataReady', event => {
  fcn_initializeReminders(event);
});

// =============================================================================
// INITIALIZE
// =============================================================================

/**
 * Initialize Reminders.
 *
 * @since 5.0.0
 * @param {Event} event - The fcnUserDataReady event.
 */

function fcn_initializeReminders(event) {
  // Unpack
  const reminders = event.detail.data.reminders;

  // Validate
  if (reminders === false) {
    return;
  }

  // Fix empty array that should be an object
  if (Array.isArray(reminders.data) && reminders.data.length === 0) {
    reminders.data = {};
  }

  // Set reminders instance
  fcn_reminders = reminders;

  // Update view
  fcn_updateRemindersView();

  // Clear cached bookshelf content (if any)
  localStorage.removeItem('fcnBookshelfContent');
}

// =============================================================================
// TOGGLE REMINDER - AJAX
// =============================================================================

/**
 * Adds or removes a story ID from the Reminders JSON, then calls for an update
 * of the view to reflect the changes and makes a save request to the database.
 *
 * @since 5.0.0
 * @see fcn_updateRemindersView()
 * @param {Number} storyId - The ID of the story.
 */

function fcn_toggleReminder(storyId) {
  // Get current data
  const currentUserData = fcn_getUserData();

  // Prevent error in case something went very wrong
  if (!fcn_reminders || !currentUserData.reminders) {
    return;
  }

  // Clear cached bookshelf content (if any)
  localStorage.removeItem('fcnBookshelfContent');

  // Re-synchronize if data has diverged
  if (JSON.stringify(fcn_reminders.data[storyId]) !== JSON.stringify(currentUserData.reminders.data[storyId])) {
    fcn_reminders = currentUserData.reminders;
    fcn_showNotification(fictioneer_tl.notification.remindersResynchronized);
    fcn_updateRemindersView();

    return;
  }

  // Set/Unset reminder
  if (fcn_reminders.data.hasOwnProperty(storyId)) {
    delete fcn_reminders.data[storyId];
  } else {
    fcn_reminders.data[storyId] = { 'story_id': parseInt(storyId), 'timestamp': Date.now() };
  }

  // Update local storage
  currentUserData.reminders.data[storyId] = fcn_reminders.data[storyId];
  currentUserData.lastLoaded = 0;
  fcn_setUserData(currentUserData);

  // Update view
  fcn_updateRemindersView();

  // Clear previous timeout (if still pending)
  clearTimeout(fcn_userRemindersTimeout);

  // Update in database; only one request every n seconds
  fcn_userRemindersTimeout = setTimeout(() => {
    fcn_ajaxPost({
      'action': 'fictioneer_ajax_toggle_reminder',
      'fcn_fast_ajax': 1,
      'story_id': storyId,
      'set': fcn_reminders.data.hasOwnProperty(storyId)
    })
    .then(response => {
      if (response.data.error) {
        fcn_showNotification(response.data.error, 5, 'warning');
      }
    })
    .catch(error => {
      if (error.status === 429) {
        fcn_showNotification(fictioneer_tl.notification.slowDown, 3, 'warning');
      } else if (error.status && error.statusText) {
        fcn_showNotification(`${error.status}: ${error.statusText}`, 5, 'warning');
      }
    });
  }, fictioneer_ajax.post_debounce_rate); // Debounce synchronization
}

// =============================================================================
// UPDATE REMINDERS VIEW
// =============================================================================

/**
 * Updates the view with the current Reminders state.
 *
 * @since 5.0.0
 */

function fcn_updateRemindersView() {
  // Get current data
  const currentUserData = fcn_getUserData();

  if (!fcn_reminders || !currentUserData.reminders) {
    return;
  }

  // Update button state
  _$$('.button-read-later').forEach(element => {
    element.classList.toggle(
      '_remembered',
      fcn_reminders.data.hasOwnProperty(element.dataset.storyId)
    );
  });

  // Update icon and buttons on cards
  _$$('.card').forEach(element => {
    element.classList.toggle(
      'has-reminder',
      fcn_reminders?.data.hasOwnProperty(element.dataset.storyId)
    );
  });
}

// =============================================================================
// EVENT LISTENERS
// =============================================================================

// Listen for clicks on any Read Later buttons
_$$('.button-read-later').forEach(element => {
  element.addEventListener('click', event => {
    fcn_toggleReminder(event.currentTarget.dataset.storyId);
  });
});
