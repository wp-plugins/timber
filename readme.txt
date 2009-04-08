=== Plugin Name ===
Contributors: jeff.smith
Tags: php, errors, logging
Requires at least: 2.7
Tested up to: 2.7.1
Stable tag: 0.8

Timber is a general-purpose error-logging and alert system for Wordpress.

== Description ==

Timber is a general-purpose error-logging and alert system for Wordpress. It traps and catalogues PHP 
errors at multiple levels, and provides detailed stack trace and debug information. It integrates with 
the [Role Manager](http://www.im-web-gefunden.de/wordpress-plugins/role-manager/) plugin to allow user 
& role specific permissions to be set to view and/or clear errors.

Timber is intended primarily as a developer’s tool, to be enabled in a sandbox environment while debugging 
plugins or themes in-progress. However, it also has potential in a production setting, as a way to hide 
errors from the end-user while still logging them for administrator review. The configuration settings 
allow for precise control of what type of errors and what error information is logged, so it can be deployed 
in varying scenarios without runaway logs filling the database.

Several additional features are planned, and a good deal of real-world testing is still required. Timber 
is currently in beta.

== Installation ==

Installing Timber is simple and straightforward.

1. Upload the plugin files to a folder named `timber` in the `/wp-content/plugins/` directory. (If the folder does not exist, create it.)
1. Activate the plugin through the 'Plugins' menu in WordPress.
1. (Optional) Follow the full [configuration guide](http://www.blurbia.com/plugins/timber/installation/) detailing all of Timber's options.

== Frequently Asked Questions ==

= Is Timber safe to install on my public blog? =

Maybe. There are several things that could wreak havoc when installing Timber on a live Wordpress site, 
and it’s important to understand what they are. As a start, follow these two steps:

**1) Don’t log Notices or Strict messages.**
The average PHP script, even the well-written ones, commits several “minor infractions” on every execution. 
This includes many of the scripts in the Wordpress core. If Notices or Strict messages are enabled for 
logging, it will result in many multiple errors logged each and every time any user loads any page of your site.

**2) Keep an eye on your logs for awhile.**
Even with Notices and Strict messages disabled, some themes or plugins may generate a number of Warnings 
or other error types. Since these will be logged every time the offending page is loaded, the logs may still 
fill quickly depending on your site traffic. If this starts to happen, you’ll want to disable logging entirely, 
or clear the logs regularly.

Clearly, the main concern is the logs filling too much or too fast. Why is this a problem? Timber logs errors 
to a custom table in your Wordpress database. Your database very likely has a space limit on it, the size 
varying dependent on your web host. This available space is already partially filled by your posts, comments, 
and other Wordpress data, as well as any custom data stored by other plugins. If the space runs out, your site 
may become inaccessible. In addition to the space limit, many database configurations have a limit on the number 
of connections or data operations. If a single page generates several errors, that multiplies the number of 
database calls for that page across every user. And if the limit is reached, again, the site may become 
unreachable.

Timber works best on a development server, as a tool for programmers to track down the bugs in their plugins 
or themes. It can be useful on a live blog, but make sure you understand the uses and potential hazards.

= Does Timber log non-PHP Wordpress errors? =

Not at this time. Timber is primarily a debugging tool for developers, meant to trace programming errors to 
their source. There are several types of “expected” errors that Wordpress handles, including those managed by 
the WP_Error class, that Timber does not monitor. Many of these are not solvable, in the permanent sense — such 
as an error message that occurs when a user attempts to login with an incorrect password. As such, they would 
only clutter the error logs.

That being said, there are certain error types, such as 404s, that future versions of Timber would benefit from 
logging. Feel free to request features and let us know how you’re using Timber.

= Why is Wordpress 2.7 required? =

Timber has been developed and tested only on Wordpress 2.7. In all likelihood, it will work fine on previous 
versions of Wordpress, although the admin screens may not match the formatting well. One of the goals of the 
upcoming 1.0 release is to be tested and fully compatible with versions at least as early as 2.5.

== Screenshots ==
