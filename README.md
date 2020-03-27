# Summarize Instance
An EM that allows you to save a single instance of a repeating form in another event.

When the repeating form is saved, it can be compared against the singleton form in the other event to decide if the singleton
form should be replaced with the data from the current form.

### Project Setup
 * Start with an event with a repeating form (e.g. medications in the baseline event)
 * Enable this form in another event as a non-repeating form (e.g. medications in most_recent event)

### EM Configuration
Configure the EM by specifying the:
 * [source-event-id]  (e.g. baseline_arm_1)
 * [source-form]      (e.g. medications)
 * [logic] (optional, e.g. [visit_date] > [most_recent_arm_1][visit_date])
   * Note how the first visit date is not prepended with an event_name.  This means it will be executed in the context of
   the current event in which it was saved.  The same applies to instance number.
 * [destination-event-id] (e.g. recent_arm_1)

It is possible to configure many summary instances in a single project

#### TODO Enhancements
- make it so that it also supports repeating events, not just repeating forms
- think about making it so you can evaluate logic across all instances... How would this work?  Imagine the summary instance
was the most recent visit detail.  If you deleted an instance, you would have to evaluate all of the instances to find the one
with the most recent visit_date...  Not sure how to approach this - it would require custom logic handling...  It could,
for example, sort all instances by a field and take the first or last of them.

