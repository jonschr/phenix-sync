## 1.2

- Added individual location sync controls to location edit screens and reload the admin screen after a successful location or professionals sync.
- Added a Location Sync Debug panel alongside the existing Professionals Sync Debug panel, with the latest 10 responses retained for each sync type.
- Reworked sync debug into readable tables and modal response viewers with local-time timestamps, syntax highlighting, nested JSON expansion, and expand/collapse-all controls.
- Store full, redacted debug payloads in a storage-safe format so large location and professionals API responses remain available for the foldable viewer.
- Made location sync meta read-only, grouped it into compact cards, and added previews for synced image URLs.
- Added professional edit-screen panels for the associated location, current synced professional data, profile image, and a link to the location's sync history.

## 1.1

- Added the `[phenix_global_contact]` shortcode for rendering the Find a Suite global contact widget.
- Added automatic location token resolution from explicit shortcode attributes, single location posts, page S3 index meta, or a capped generic fallback of up to 20 synced locations.
- Added shortcode parameters for direct token overrides and redirect URL overrides, with the default redirect set to the current site home URL.
- Added scoped frontend styling for the Find a Suite widget form, including paired name/email fields, full-width location dropdown support, CAPTCHA layout adjustments, consent box cleanup, and responsive behavior.
- Documented the global contact widget shortcode on the Phenix Sync settings page.

## 0.9

- Added individual admin-side sync buttons on location and professional edit screens that reuse the existing location professionals sync path.
- Fixed professionals deletion so an authoritative empty API array (`[]`) removes all professionals for that location without treating failed or malformed responses the same way.
- Expanded professionals sync debug output to record response shape, counts, and a redacted response preview to make empty-array cases visible in the admin.

## 0.8

- Added a manual "Run Locations Sync Now" button to the Phenix Sync settings page to trigger the full locations sync initialization flow on demand.

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
