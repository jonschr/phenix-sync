## 0.7

- Updated from utility24 to admin.ginasplatform.com
- Added sync enable toggle (default on) with admin error notice and disabled sync buttons when off.
- Prevented sync processing when disabled and cleared relevant scheduled hooks.
- Added editable location meta fields and warning notice on location edit screens.
- Hardened API sync handling for hidden/empty responses and fixed suites string warning.

## 0.6.4

- Adding SEOPress variables to the single-locations.php template.

## 0.6.3

- Updates should now trigger from the 'master' branch, not 'main'

## 0.6.2

- Order the pros by suite numbers in the main loop

## 0.5.4

- Adding functionality to better remove old tenants who have been orphaned (using a sql query to do this efficiently)

## 0.5.1

- Fixing a couple of errors where we call functions in plugins not present or which were in the main Phenix theme

## 0.5.0

- Adding new settings page
- Adding ability to sync select locations and their pros
- Adding shortcodes for displaying location/pro information at will on pages.
