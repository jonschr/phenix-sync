=== PLUGINNAME ===
Contributors: jonschr
Donate link: https://elod.in
Tags: comments, spam
Requires at least: 5.9
Tested up to: 6.7.1
Requires PHP: 7.4
Stable tag: 1.3.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Here is a short description of the plugin.  This should be no more than 150 characters.  No markup here.

== Description ==

This is the long description.  No limit, and you can use Markdown (as well as in the following sections).

Sample readme: https://github.com/wp-cli/sample-plugin/blob/master/readme.txt

== Installation ==

The usual way.

== Frequently Asked Questions ==

= A question that someone might have =

An answer to that question.

== Screenshots ==

1. This screen shot description corresponds to screenshot-1.(png|jpg|jpeg|gif). Note that the screenshot is taken from
the /assets directory or the directory that contains the stable readme.txt (tags or trunk). Screenshots in the /assets
directory take precedence. For example, `/assets/screenshot-1.png` would win over `/tags/4.3/screenshot-1.png`
(or jpg, jpeg, gif).
2. This is the second screen shot

== Changelog ==

= 1.3.2 =
* Added self-dispatching, fresh-request sync workers with a WP-Cron recovery watchdog.
* Prevented delayed or duplicate workers from reprocessing completed queue offsets or moving status backward.
* Added a versioned Phenix Sync user agent to internal continuation requests.

= 1.3.1 =
* Clarified location sync logging so changed and updated locations are explicitly distinguished from unchanged locations.

= 1.3 =
* Reduced full-sync memory usage and added a sequential locations-and-professionals pipeline.
* Moved compressed sync response history into an automatically created custom database table.
* Added full-sync progress, timing, memory, start, and stop controls.
* Avoided post updates and search reindexing when API data is unchanged.
* Added bounded legacy cleanup, safer retries, and S3 index searching in the Locations admin.

= 0.2 =
* ... silence is golden.

= 0.1 =
* Initial commit

== Upgrade Notice == 

* Silence is golden.
