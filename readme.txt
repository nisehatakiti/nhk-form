=== NHK Form ===
Contributors: nisehatakiti
Tags: form, contact form, form builder, post submission
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A flexible WordPress form plugin that converts external form input into WordPress data.

== Description ==

NHK Form is a flexible WordPress form plugin.

It can be used as a normal contact or application form and can also convert form submissions into WordPress draft posts for administrator review.

Main features:

* Text, email, telephone, number, date and textarea fields
* Select, radio and checkbox fields
* File uploads
* Validation for required values and field rules
* Field widths and line-break layout settings
* Multiple notification recipients
* From and Reply-To settings
* Automatic replies
* WordPress draft post generation
* Field mapping for post content, custom fields and files
* External schema integration

NHK Form works independently. Other plugins may optionally register schemas without NHK Form depending on any particular plugin.

== Installation ==

1. In WordPress, go to Plugins > Add New > Upload Plugin.
2. Select the NHK Form distribution ZIP.
3. Install and activate the plugin.
4. Create a form from the Forms menu.
5. Add [nhk_form id="123"] to a page or post.

== Frequently Asked Questions ==

= Does NHK Form require Alumni Core? =

No. NHK Form works as a standalone plugin.

= Can NHK Form create WordPress posts? =

Yes. A form submission can create a WordPress post as a draft for administrator review.

= Can another plugin provide a schema? =

Yes. External plugins can register schemas through the NHK Form schema integration hooks.

== Changelog ==

= 0.2.2 =
* Existing Alumni Core forms are automatically imported as editable NHK Form records.
* Saving an imported form in NHK Form updates the original Alumni Core form definition.

= 0.2.1 =
* Automatically detects Alumni Core and provides Alumni Core content schemas.
* Existing Alumni Core content-submission form definitions can be read automatically as NHK Form schemas.
* Generated submissions create Alumni Core draft content with the required content type metadata.

= 0.2.0 =
* Standalone NHK Form release packaging.
