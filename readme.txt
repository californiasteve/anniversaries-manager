=== Anniversaries Manager ===
Contributors: california-steve
Tags: anniversaries, birthdays, calendar, import/export, notifications
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.8.12
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Display anniversaries with a public form (pending approval), list & calendar shortcodes, CSV import/export, notifications, and custom CSS.

== Description ==

- Custom post type for anniversaries
- Public submission form creates **pending** items for admin approval
- Shortcodes:
  - `[abm_form]` – public form
  - `[abm_list show_next="1" orderby="next|name|date" order="ASC|DESC" limit="100"]` – public list (toggle Next column)
  - `[abm_calendar nav="1" month="1..12" year="YYYY"]` – public calendar with month/year navigation
- CSV import/export (uses WP_Filesystem; no direct PHP file handles)
- Email notifications with configurable recipients (suppressed for admin-created draft/published items)
- Admin-editable label to append to titles (Anniversary/Birthday/Hire Date, etc.) with auto-title option
- Styles page for custom CSS (and ability to disable default CSS)

== Installation ==

1. Upload the ZIP via *Plugins → Add New → Upload Plugin*.
2. Activate **Anniversaries Manager**.
3. Use the shortcodes on any page or post.

== Frequently Asked Questions ==

= My shortcodes don't render? =
Ensure the plugin is active. You can add `[abm_debug]` to a page to confirm registration and entry count.

= How do I hide the “Next” column in the list? =
Use `[abm_list show_next="0"]` or set the filter `abm_list_show_next` to return false.

== Changelog ==

= 1.8.8 =
* Remove `load_plugin_textdomain()` (WP 4.6+ auto-loading).
* Add missing `/* translators: */` comments anywhere placeholders are used.
* Keep CSV import/export using WP_Filesystem and in-memory CSV generation.

== Upgrade Notice ==

= 1.8.8 =
Recommended update for localization and coding standards.
