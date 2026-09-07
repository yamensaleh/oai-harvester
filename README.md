# OAI Harvester

OAI Harvester asynchronously imports OAI-PMH 2.0 metadata
into Drupal/Islandora repository objects. It uses Drupal entities and queues;
it does not write directly to Fedora, Solr, or Islandora database tables.
Saving nodes and media lets the normal Islandora event, derivative, Fedora,
and indexing pipelines run.

## Important queue behavior

Starting a harvest only creates queue items. It does **not** start a permanent
background process. Drupal cron or dedicated queue workers must run repeatedly
until both OAI queues are empty and the harvest reaches a completed state.

```text
Harvest now
  -> islandora_oai_discovery queue
  -> fetch an OAI page and follow its resumption token
  -> islandora_oai_import queue
  -> create, update, skip, or unpublish Drupal entities
  -> completed or completed_with_warnings
```

See [Cron and queue processing](#cron-and-queue-processing) before running a
large harvest.

## Features

- Multiple OAI-PMH source configuration entities.
- `Identify`, `ListMetadataFormats`, `ListSets`, `ListIdentifiers`,
  `ListRecords`, `GetRecord`, deleted headers, and resumption tokens.
- Namespace-aware `oai_dc` parsing with XXE protections.
- Full and incremental harvest modes with per-set discovery.
- Durable discovery/import queues, retry handling, and record locks.
- Stable source-record-to-node identity mapping.
- Replace, append, skip, and deleted-record behavior.
- Exact-name taxonomy reuse and configurable field mapping.
- Optional, allowlisted file import into Islandora media entities.
- Testing, preview, history, raw metadata, CSV error export, pause, resume,
  cancel, and retry controls.
- Drupal Key integration for optional HTTP Basic authentication.

## Repository assumptions

The default implementation is designed for this repository's data model:

- repository objects use `node:islandora_object`;
- `field_model` references `taxonomy_term:islandora_models`;
- `field_member_of` references a collection node;
- `field_category` references the repository category vocabulary;
- media link to repository objects through `field_media_of`;
- file-capable media bundles include file, document, image, audio, and video;
- new harvested nodes default to unpublished unless configured otherwise;
- source ID plus OAI identifier is the stable unique record identity.

The source form validates the selected bundle and relevant fields. Model term,
category, collection, field mappings, taxonomy vocabularies, and media settings
must be selected by an administrator.

The older `dar_oai_pmh_harvester` module is independent. Enabling this module
does not migrate, disable, or replace it.

## Requirements

- Drupal 10
- Islandora
- Node, Media, File, Taxonomy, and Options core modules
- Drupal Key module
- A working Drupal cron or queue-worker execution mechanism
- Network access from Drupal to the OAI-PMH endpoint
- For file imports: writable Drupal file storage and explicitly allowed source
  domains, MIME types, and extensions

This is a custom module and is not installed with `composer require`.

## Installation

1. Place the module in `web/modules/custom/oai_harvester`.
2. Ensure the dependencies in `oai_harvester.info.yml` are present.
3. From the Drupal project root, enable the module and rebuild caches:

   ```bash
   vendor/bin/drush en oai_harvester -y
   vendor/bin/drush cr
   ```

4. Grant the appropriate permissions to administrative roles.
5. Configure cron or a queue runner before starting a large harvest.

After deploying an updated version, back up the database, test the same release
in staging, and run reviewed update hooks:

```bash
vendor/bin/drush updb -y
vendor/bin/drush cr
```

### Permissions

Grant only the permissions each role requires:

| Permission | Purpose |
|---|---|
| Administer OAI-PMH harvester sources | Create, edit, enable, disable, and delete sources; restricted-access permission |
| Test OAI-PMH connections | Run connection tests and previews |
| Start OAI-PMH harvests | Start full or incremental runs |
| Pause or cancel OAI-PMH harvests | Pause, resume, or cancel active runs |
| View OAI-PMH harvest history | View run status and download warning/error CSV files |
| Retry failed OAI-PMH records | Requeue failures and re-harvest individual records |
| View raw OAI-PMH source metadata | View stored source XML; grant sparingly |

Configure these at **People -> Roles -> Edit permissions**. Raw metadata can
contain sensitive descriptive information and should not be broadly visible.

## Configuring a source

Open **Administration -> Configuration -> Islandora -> OAI Harvester**, or
go directly to:

```text
/admin/islandora/oai-harvester
```

Select **Add source**, then complete the following sections.

### 1. Connection

- **Source name:** Human-readable administrative label.
- **Machine name:** Stable ID used by Drush commands.
- **Enabled:** Disabled sources cannot start harvests.
- **Endpoint:** Absolute HTTP(S) OAI-PMH base URL. Embedded credentials are
  rejected.
- **Authentication key:** Optional Drupal Key entity. For HTTP Basic
  authentication, its value must be `username:password`. Source configuration
  stores only the Key entity ID.
- **Request timeout:** Timeout for each OAI request.
- **HTTP attempts:** Maximum request/import attempts.
- **Rate limit:** Minimum delay between source requests.

Save, then use **Test**. The test runs `Identify`, discovers metadata formats,
and reads up to ten pages of sets. Additional set specifications can be entered
manually if the source has more pages.

### 2. Content selection

- **Metadata format:** The included parser supports `oai_dc`.
- **Granularity:** Match the source's advertised date granularity.
- **Set specifications:** Leave empty to harvest everything, or select/enter
  exact OAI `setSpec` values.

Each set creates a discovery branch. The run-level unique key prevents
duplicate import rows when sets overlap.

### 3. Islandora destination

- **Content type:** Normally `islandora_object`.
- **Islandora model:** Required model term applied to new records.
- **Category:** Required repository category term.
- **Target collection:** Optional node placed in `field_member_of`.
- **Publish new records:** Leave disabled when staff review is required.

### 4. Field mapping

Map Dublin Core elements such as `dc:title`, `dc:creator`, and `dc:subject` to
fields on the selected node bundle.

- At least one element must map to the node title.
- **Replace** overwrites a mapped value during later synchronization.
- **Append** retains existing values and adds new source values.
- **First** imports only the first source value.
- **All values** imports up to the destination field's cardinality.
- Transformations can trim, lowercase, or normalize language codes.
- Entity-reference mappings must select a taxonomy vocabulary. Terms are
  reused by exact name or created when absent.
- **Skip empty** prevents an empty source element from clearing a field.

Use **Preview** to inspect up to five mapped records. Preview is read-only.

### 5. Optional file import

Standard OAI-PMH exposes metadata, not files. Enable this only when a mapped
element contains direct file URLs. Configure:

- exact allowed domains or `.example.org`-style subdomain suffixes;
- allowed MIME types and file extensions;
- maximum file size;
- destination media bundle and file field;
- media-to-node relationship field.

An empty domain allowlist denies every download. URL checks reject embedded
credentials and non-public network addresses, redirects are disabled, and
imported URLs are tracked to prevent duplicate media creation.

### 6. Schedule and update rules

- **Manual:** Does not automatically create future runs.
- **Hourly, daily, or weekly:** Drupal cron creates an incremental run when the
  source is due.
- **Preferred batch size:** Advisory; the OAI server controls the actual page
  size.
- **Replace mapped fields:** Synchronizes tracked nodes from later metadata.
- **Skip existing records:** Leaves tracked nodes unchanged.
- **Unpublish deleted records:** Unpublishes a node when OAI reports deletion.
- **Ignore deleted records:** Leaves the local node unchanged.

The source schedule controls when a **new** incremental harvest is created. It
does not control how frequently the current queues are processed.

After configuration, export it through the project's reviewed configuration
workflow. Authentication secrets belong in Drupal Key, not exported YAML.

## Running a harvest

### Administrative interface

1. Open the source list.
2. Test the connection.
3. Preview and verify the mappings.
4. Select **Harvest now**.
5. Choose **Incremental** or **Full**.
6. Optionally enter `from` and `until` OAI datestamps.
7. Confirm, then monitor the run while cron/workers process it.

Only one non-terminal run is permitted for a source at a time.

### Full harvest

A full harvest requests the selected sets without a `from` date. An optional
`until` date caps the upper boundary. It follows every resumption token, so the
discovered count can continue growing across several queue runs.

Full harvesting is asynchronous. Closing the browser does not cancel it, but
processing stops whenever no cron or queue worker is running.

### Incremental harvest

An incremental run uses an explicit `from` value or the `until` boundary from
the most recent successful run with no failures. A fixed `until` boundary is
recorded when the new run starts.

### Drush commands

```bash
# Queue an incremental harvest.
drush oai-harvester:run SOURCE_ID

# Queue a full harvest.
drush oai-harvester:run SOURCE_ID --full

# Queue an explicit incremental range.
drush oai-harvester:run SOURCE_ID \
  --from='2026-09-01T00:00:00Z' \
  --until='2026-09-07T00:00:00Z'

# Display run counters and state.
drush oai-harvester:status RUN_ID

# Requeue terminally failed records.
drush oai-harvester:retry RUN_ID
```

## Cron and queue processing

### Why cron is required

The form and Drush start commands return after adding initial discovery work.
This avoids browser, reverse-proxy, and PHP timeouts during large harvests.

The module defines two cron queue workers:

| Queue | Responsibility | Cron allowance |
|---|---|---:|
| `islandora_oai_discovery` | Fetch a page, persist XML, enqueue imports, and enqueue the next resumption token | 60 seconds per Drupal cron run |
| `islandora_oai_import` | Parse records and create/update/skip/unpublish entities and optional files | 60 seconds per Drupal cron run |

A large harvest normally needs many cron invocations. A run left in
`discovering` or `importing` with unchanged counts is commonly waiting for
another worker invocation; the original web request is no longer executing.

### Manual Drupal cron

Run all Drupal cron hooks and queues once:

```bash
vendor/bin/drush cron
```

Repeat during testing until the run is `completed` or
`completed_with_warnings`. One invocation is not guaranteed to finish a full
harvest.

### Targeted queue processing

Process only the OAI queues:

```bash
vendor/bin/drush queue:run islandora_oai_discovery --time-limit=60
vendor/bin/drush queue:run islandora_oai_import --time-limit=60
```

Run discovery first because it produces import items. Alternate the commands
until discovery is empty and all discovered records have been processed.

Inspect queue sizes without consuming items:

```bash
vendor/bin/drush php:eval 'foreach (["islandora_oai_discovery", "islandora_oai_import"] as $name) { echo $name . ": " . \Drupal::queue($name)->numberOfItems() . PHP_EOL; }'
```

### Automated Cron limitations

Drupal Automated Cron runs only when a web request arrives after its interval
has elapsed. It can slow the triggering request, and a quiet site will not run
it. It should not be the only worker for large harvests.

### Recommended production arrangement

Use both:

1. normal Drupal cron every one to five minutes for scheduled harvest creation
   and general maintenance; and
2. dedicated supervised discovery/import workers for sustained queues.

Dedicated workers should repeatedly launch finite `queue:run` commands rather
than keep one PHP process alive indefinitely. Finite processes release memory
accumulated during entity processing and are easier to restart safely. Use one
discovery worker per environment. Add import concurrency only after testing
database, Fedora, Solr, derivative-service, and source load.

Example Kubernetes Drupal cron job:

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: drupal-cron
spec:
  schedule: "*/5 * * * *"
  concurrencyPolicy: Forbid
  jobTemplate:
    spec:
      template:
        spec:
          restartPolicy: OnFailure
          containers:
            - name: drush
              image: YOUR_DRUPAL_IMAGE
              workingDir: /var/www/drupal
              command: ["vendor/bin/drush", "cron"]
```

Example Kubernetes discovery worker:

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: islandora-oai-discovery
spec:
  schedule: "*/2 * * * *"
  concurrencyPolicy: Forbid
  jobTemplate:
    spec:
      template:
        spec:
          restartPolicy: OnFailure
          containers:
            - name: drush
              image: YOUR_DRUPAL_IMAGE
              workingDir: /var/www/drupal
              command:
                - vendor/bin/drush
                - queue:run
                - islandora_oai_discovery
                - --time-limit=300
```

Create a corresponding job for `islandora_oai_import`. Both must use the same
Drupal image, settings, secrets, database, and persistent files as the web app.

## Monitoring and run controls

The source history and run pages show the state, discovery branches, counters,
date range, resumption token, record results, and a warning/error CSV export.

| State | Meaning |
|---|---|
| `pending` | Initial discovery work is queued |
| `discovering` | OAI pages/resumption tokens remain |
| `importing` | Discovery is finished; record imports remain |
| `paused` | Workers delay and requeue items until resumed |
| `completed` | Finished without recorded warnings/failures |
| `completed_with_warnings` | Finished with warnings or terminal failures |
| `failed` | Discovery or run-level processing failed |
| `cancelled` | Remaining work is discarded as claimed; imported entities remain |

Pause and cancel do not undo records already created or updated. Retry requeues
failed records but does not restart discovery.

## Troubleshooting

### Counts stop changing

1. Check whether the state is `paused`, `failed`, or `cancelled`.
2. Check both queue sizes.
3. Confirm cron or a queue worker is actually running.
4. Run discovery once, then import once, and refresh the run page.
5. Review logs:

   ```bash
   vendor/bin/drush watchdog:show --type=oai_harvester --count=50
   ```

6. Review the warning/error CSV and attempt counts.

Do not press **Harvest now** again merely because work is waiting for cron.

### Discovery remains pending

- Test the source again.
- Confirm the endpoint is reachable from the Drupal runtime.
- Check the authentication Key and `username:password` format.
- Confirm the selected `setSpec` and metadata prefix.
- Review timeouts, retries, rate limiting, resumption token, and logs.

### Imports fail or warn

- Verify the destination bundle and required model/category/member fields.
- Confirm selected term and collection IDs exist after config deployment.
- Check field types, cardinality, and taxonomy vocabulary mappings.
- For files, verify domains, MIME types, extensions, size, bundle, and fields.
- Confirm writable file storage and downstream Islandora service health.
- Correct the cause before retrying records.

### Interrupted workers

Queue items are leased while processing. If a worker is killed, an item can be
temporarily unavailable until its lease expires. Do not delete queue rows;
allow the lease to expire and run the worker again.

## Data and lifecycle

| Table | Purpose |
|---|---|
| `islandora_oai_harvest_run` | Run state, counters, boundaries, and progress |
| `islandora_oai_raw_record` | Raw XML and per-run record results |
| `islandora_oai_discovery_page` | Idempotency markers for OAI pages |
| `islandora_oai_record` | Stable source identifier to node mapping |
| `islandora_oai_imported_file` | Imported URL to media mapping |

Uninstall removes module configuration and tracking tables. Imported nodes,
files, and media are deliberately retained because they are repository content.
Back up the database and export required provenance/history before uninstalling.

## Security behavior

- Endpoints and file URLs permit only HTTP(S), prohibit embedded credentials,
  and reject non-public DNS/IP results.
- Redirects are disabled.
- File URLs additionally require a hostname allowlist.
- XML with DTD/entity declarations is rejected and parsed with `LIBXML_NONET`.
- Authentication remains in Drupal Key.
- Request attempts and delays are bounded.
- Raw XML has a separate restricted permission.
- CSV formula characters are neutralized.
- New content defaults to unpublished unless configured otherwise.

## Extending the module

Implement `MetadataParserInterface` with a `@MetadataParser` annotation to add
a metadata prefix. Implement `FieldTransformInterface` with `@FieldTransform`
to add a transformation. Interface-backed destination and file URL services
can be decorated or replaced.

Preserve run counters, stable record identity, access checks, URL guarding, and
queue idempotency when extending import behavior.

## Tests and quality checks

Tests use local fixtures and should not contact a live OAI repository:

```bash
vendor/bin/phpunit web/modules/custom/oai_harvester/tests/src/Unit
vendor/bin/phpunit web/modules/custom/oai_harvester/tests/src/Kernel
vendor/bin/phpunit web/modules/custom/oai_harvester/tests/src/Functional
vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  web/modules/custom/oai_harvester
vendor/bin/phpstan analyse \
  web/modules/custom/oai_harvester/src
```

Verify discovery and import against a small test set before a full production
harvest.
