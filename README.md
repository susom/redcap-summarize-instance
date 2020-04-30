# Select Repeat Instance
An EM that allows you to save a single instance of a repeating form mirrored to another event.

When the repeating form is saved, it can be compared against the singleton form in the other event to decide if the singleton
form should be replaced with the data from the current repeating form instance.

For example, imagine you have a monthly checkin process and want to have a report to show just the latest checkin
results.  With SRI you can simply report on the event_2 copy of the latest instance of the monthly checkin.

### Project Setup
 * Start with an event with a repeating form (e.g. medications in the baseline event) or a singleton form.
 * Enable this form in another event as a non-repeating form (e.g. medications in most_recent event)
 * When the source form is saved, it will be copied to the destination form (pending optional logic)

### EM Configuration
Configure the EM by specifying the:
 * [source-event-id]  (e.g. baseline_arm_1)
 * [source-form]      (e.g. medications)
 * [logic] (optional, e.g. [visit_date] > [most_recent_arm_1][visit_date])
   * Note how the first visit date is not prepended with an event_name.  This means it will be executed in the context of
   the current event in which it was saved.  The same applies to instance number.
 * [destination-event-id] (e.g. recent_arm_1)
 * [destination-summary-field] (optional, e.g. [source_instance].  Will save the source instance that was last copied
  to this destination event_id)
 * [ignore-empties] (if checked, an empty value in the source form will not erase a previous value in the destination
  form - it is like a file copy that doesn't purge deletes - request from
   [Steven Boren](https://community.projectredcap.org/questions/83301/select-repeat-instance.html)

It is possible to configure many summary instances in a single project

#### TODO Enhancements
- make it so that it also supports repeating events, not just repeating forms
- think about making it so you can evaluate logic across all instances... How would this work?  Imagine the summary instance
was the most recent visit detail.  If you deleted an instance, you would have to evaluate all of the instances to find the one
with the most recent visit_date...  Not sure how to approach this - it would require custom logic handling...  It could,
for example, sort all instances by a field and take the first or last of them.
- warn summary instance of form to be read-only when viewed?

