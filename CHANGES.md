## 1.3.1

- Clarified location sync logging so changed locations explicitly report that their WordPress post was updated successfully.

## 1.3

- Reworked the daily and manual full sync into one locked, sequential pipeline: locations run one at a time, followed by professionals one location at a time.
- Reduced peak memory use by retaining only the compact location ID queue and requesting each complete location record by `location_index` when its worker runs.
- Removed obsolete full-response and individual-location transients, including one-time cleanup for legacy transient keys.
- Added one-minute retry delays for failed location and professional requests and restored the worker interval to 10 seconds.
- Added timeout and request-failure logging with consistent `Phenix Sync:` messages plus bounded memory checkpoints at major pipeline stages.
- Avoided post, taxonomy, FacetWP, and Relevanssi updates when location or professional data is unchanged while continuing to record each sync request.
- Consolidated location mutations so changed records receive one final post update after their meta and taxonomy data is ready for indexing.
- Replaced post-meta debug histories with an automatically created custom table and migrated legacy histories as each location syncs.
- Retained the latest five location and professional responses per location, storing complete password-redacted responses with gzip compression when available.
- Added readable, foldable debug response viewers for both location and professional sync histories.
- Added a settings-page status dashboard with current stage, progress, memory, start time, elapsed time, estimated remaining time, estimated completion time, retries, and errors.
- Added adjacent manual Start and Stop controls, live 10-second status refreshes, one-time action notices, and safe queue/lock cleanup when a run is stopped.
- Added bounded one-time cleanup for orphaned post-meta and taxonomy rows left by the former direct-delete process, with its completed notice expiring after 24 hours.
- Switched synced post deletion to WordPress deletion APIs so post meta, taxonomy relationships, and search-index hooks are cleaned correctly.
- Added S3 index matching to the Locations admin search.
- Updated the manual sync description to clarify that a full sync includes both locations and professionals.

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
