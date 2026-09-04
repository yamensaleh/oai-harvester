# Islandora OAI-PMH Harvester

Production-oriented Drupal 10 queue workers for harvesting OAI-PMH 2.0
metadata into Islandora through Drupal entity APIs. The module never writes
directly to Fedora, Solr, or Islandora database tables. Saving nodes and media
allows the repository's normal event and indexing pipeline to run.

## Site-specific decisions

The implementation was based on this repository's `config/sync` export:

- repository objects are `node:islandora_object` (`Repository Item`);
- the required Islandora model is `field_model`, referencing
  `taxonomy_term:islandora_models`;
- collection membership is `field_member_of`, referencing a node;
- file-capable media bundles include `file`, `document`, `image`, `audio`, and
  `video`;
- media link to repository objects through `field_media_of`;
- the generic file bundle uses `field_media_file`;
- object publication is the node `status` field, and new objects default to
  unpublished in the harvester;
- `field_external_system_id` exists, but it is not used as the unique key
  because its value alone cannot safely distinguish two sources. The tracking
  table uniquely indexes `source_id + oai_identifier` instead.

Model term, target collection, element mappings, taxonomy vocabularies, and
media settings are source-specific and are deliberately selected by an
administrator. The source form does not guess term IDs or collection IDs.

The existing `dar_oai_pmh_harvester` module is independent and is not changed
or replaced by this module.

## Features

- Configuration entities for any number of sources.
- `Identify`, `ListMetadataFormats`, `ListSets`, `ListIdentifiers`,
  `ListRecords`, `GetRecord`, deleted headers, and resumption tokens.
- Namespace-aware, XXE-hardened `oai_dc` parser plugin.
- Replaceable metadata parser, field transformation, destination importer,
  and file URL extractor services.
- Durable discovery/import queues with small queue payloads; raw XML is stored
  in a separate table.
- Full and incremental harvests, per-set discovery, safe retries, idempotent
  source identities, and record locks.
- Create/update/append mappings, multiple values, exact-name taxonomy term
  reuse, title validation, and configurable deletion behavior.
- Progress, history, record-level messages, CSV error export, raw metadata
  access, cancellation, pause state, and failed-record retry.
- Optional streamed file ingestion with scheme, DNS/IP, hostname allowlist,
  MIME, extension, size, and redirect controls. Imported media are de-duplicated.
- Key module integration for optional HTTP Basic credentials. Configuration
  contains only the Key entity ID; credentials are never logged.

## Install

The site must have the Drupal Key module available (it is enabled in this
project). Then run:

```bash
drush en islandora_oai_harvester -y
drush cr
```

Grant only the permissions required by each administrative role. In
particular, raw source metadata and connection testing have separate
permissions.

Go to **Administration → Islandora → OAI-PMH Harvester**, add a source, and:

1. Enter its endpoint and optional Key entity. A Basic-authentication Key value
   uses `username:password`.
2. Save and use **Test** to run `Identify`, discover formats, and display the
   first page of sets.
3. Select the `oai_dc` prefix and optional setSpec values.
4. Select the repository bundle, model term, optional collection, and initial
   publication status.
5. Map at least one Dublin Core element to Title. For taxonomy reference
   fields, select the vocabulary in the same row.
6. Use **Preview** to inspect up to five records without creating anything.
7. Use **Harvest now** to queue a full or incremental run.

The disabled example in
`config/optional/islandora_oai_harvester.source.example.yml` demonstrates title,
description, and language-taxonomy mappings. It intentionally has no model
term ID and must be adapted before use.

## Queue operation

Browser and Drush start operations only enqueue discovery work and return.
Drupal cron processes both queues, or they can be run directly:

```bash
drush oai-harvester:run SOURCE_ID
drush oai-harvester:run SOURCE_ID --full
drush oai-harvester:status RUN_ID
drush oai-harvester:retry RUN_ID
drush queue:run islandora_oai_discovery --time-limit=300
drush queue:run islandora_oai_import --time-limit=300
```

OAI-PMH servers choose their own response page size. The source's preferred
batch-size setting is advisory for operations and does not truncate a protocol
page. Record totals are not predicted because OAI-PMH does not guarantee a
complete-list size.

### Kubernetes workers

Run finite queue commands under the same image, settings, secrets, database,
and persistent Drupal files volume as the web pods. A Kubernetes `CronJob` is
a simple deployment model:

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: islandora-oai-import
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
              command: ["vendor/bin/drush", "queue:run", "islandora_oai_import", "--time-limit=300"]
```

Create a second finite CronJob for `islandora_oai_discovery`. Alternatively, a
supervisor-managed worker may repeatedly launch those finite commands. The
module itself contains no infinite shell loop. Use a single discovery worker
per environment; multiple import workers are safe because Drupal queue claims
and per-record locks prevent concurrent duplicate imports.

## Data and lifecycle

`islandora_oai_harvest_run` stores run state and counts.
`islandora_oai_raw_record` stores one raw record per run.
`islandora_oai_record` provides the stable source identifier to node mapping.
`islandora_oai_imported_file` de-duplicates media URLs.

States are pending, discovering, importing, paused, completed,
completed-with-warnings, failed, and cancelled. Cancelling discards remaining
work when it is claimed and does not delete already imported entities.

Uninstall removes configuration and tracking tables through Drupal's normal
module uninstall process. Imported nodes, files, and media are deliberately
left in the repository.

## Security notes

- Endpoint and file URLs permit only HTTP(S), prohibit embedded credentials,
  reject non-public DNS/IP results, and disable redirects.
- File URLs additionally require an administrator allowlist. Empty allowlists
  deny all downloads.
- XML containing DTD or entity declarations is rejected and parsed with
  `LIBXML_NONET`.
- HTTP retries are bounded and use exponential backoff; request spacing is
  configurable.
- Responses and form output use Drupal render arrays or `text/plain`; CSV cells
  that could trigger spreadsheet formulas are neutralized.
- Authentication values remain in Key and exception/log messages never include
  request options or secrets.

## Extending metadata support

Implement `MetadataParserInterface` and annotate a class with
`@MetadataParser`, including its `metadata_prefix`. New transformations
implement `FieldTransformInterface` with `@FieldTransform`. Destination and file
URL behavior can be replaced by decorating or replacing the corresponding
interface-backed services.

## Tests and quality checks

Fixtures are local; tests never call a live repository. From a complete project
checkout with Composer dependencies and PHP available, run:

```bash
vendor/bin/phpunit web/modules/custom/islandora_oai_harvester/tests/src/Unit
vendor/bin/phpunit web/modules/custom/islandora_oai_harvester/tests/src/Functional
vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/islandora_oai_harvester
vendor/bin/phpstan analyse web/modules/custom/islandora_oai_harvester/src
drush en islandora_oai_harvester -y
drush cr
```

No test reaches the live endpoint. Connection behavior uses mocked HTTP
responses and fixed XML fixtures.

