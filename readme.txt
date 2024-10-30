=== JCP User Consent ===
Contributors: dc5ala
Tags: gdpr, consent, registration
Requires at least: 4.9.6
Tested up to: 4.9.6
Stable tag: 1.1.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

This plugin adds a checkbox to user registration form which has to be ticked to complete the registration.

== Description ==

I developed a few plugins for my sports club's website which I will release to the public. Maybe they are useful to other people. This plugin deals with GDPR and users giving their consent to store their data on your site. You also should be able to provide evidence for their consent which this plugin does by storing details when consent has been given. This is a really simple plugin for now, no fancy options page or further customization of texts. However you can translate it if you want by using the POT file with an editor like poedit.

What this plugin does:
* Adds checkbox to registration form
* Slightly changes default email sent to new user by adding an extra key
* Sets user role to 'none' until consent key has been received
* Stores email sent to user
* Stores date and ip address of client when link in email has been clicked
* Blocks users from login without a role
* Reverts user role to default after successful activation

== Installation ==

1. Extract the content of the archive to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress

== Changelog ==

= 1.1 =
* Shows user consent details for admins

= 1.0 =
* Initial release

