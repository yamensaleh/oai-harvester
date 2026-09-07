<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\oai_harvester\Entity\OaiSourceInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Streams allowlisted remote files into Drupal-managed files and media.
 */
final class FileImporter implements FileImporterInterface {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly UrlGuard $urlGuard,
    private readonly FileUrlExtractorInterface $extractor,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   *
   */
  public function import(OaiSourceInterface $source, string $recordXml, int $nodeId, string $oaiIdentifier): array {
    $settings = $source->getFileSettings();
    if (empty($settings['enabled'])) {
      return [];
    }
    $warnings = [];
    foreach ($this->extractor->extract($source, $recordXml) as $url) {
      try {
        $this->importUrl($source, $settings, $url, $nodeId, $oaiIdentifier);
      }
      catch (\Throwable $exception) {
        $warnings[] = 'File not imported: ' . $exception->getMessage();
        $this->logger->warning('A mapped file for {identifier} was rejected: {message}', ['identifier' => $oaiIdentifier, 'message' => $exception->getMessage()]);
      }
    }
    return $warnings;
  }

  /**
   *
   */
  private function importUrl(OaiSourceInterface $source, array $settings, string $url, int $nodeId, string $oaiIdentifier): void {
    $domains = array_values(array_filter(array_map('trim', $settings['allowed_domains'] ?? [])));
    if (!$domains) {
      throw new \InvalidArgumentException('No file-download domains are allowlisted.');
    }
    $this->urlGuard->assertSafe($url, $domains);
    $hash = hash('sha256', $url);
    $existing = $this->database->select('islandora_oai_imported_file', 'f')->fields('f', ['media_id'])->condition('source_id', $source->id())->condition('oai_identifier', $oaiIdentifier)->condition('url_hash', $hash)->execute()->fetchField();
    if ($existing && $this->entityTypeManager->getStorage('media')->load($existing)) {
      return;
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    $filename = basename(rawurldecode($path)) ?: 'download';
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'download';
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $extensions = array_map('strtolower', $settings['allowed_extensions'] ?? []);
    if (!$extension || !$extensions || !in_array($extension, $extensions, TRUE)) {
      throw new \InvalidArgumentException('The file extension is not allowed.');
    }
    $maximum = max(1, (int) ($settings['max_bytes'] ?? 0));
    $mediaBundle = (string) ($settings['media_bundle'] ?? '');
    $fileField = (string) ($settings['file_field'] ?? '');
    $mediaOfField = (string) ($settings['media_of_field'] ?? '');
    if (!$this->entityTypeManager->getStorage('media_type')->load($mediaBundle)) {
      throw new \UnexpectedValueException('Configured media type does not exist.');
    }
    $media = $this->entityTypeManager->getStorage('media')->create(['bundle' => $mediaBundle, 'name' => $filename]);
    if (!$media->hasField($fileField) || !$media->hasField($mediaOfField)) {
      throw new \UnexpectedValueException('Configured media file or relationship field does not exist.');
    }
    $temporary = tempnam(sys_get_temp_dir(), 'oai_');
    if ($temporary === FALSE) {
      throw new \RuntimeException('Could not allocate a temporary download file.');
    }
    $mime = '';
    try {
      $response = $this->httpClient->request('GET', $url, [
        'sink' => $temporary,
        'timeout' => 120,
        'connect_timeout' => 10,
        'allow_redirects' => FALSE,
        'headers' => ['Accept' => '*/*'],
        'on_headers' => static function ($response) use ($maximum, &$mime): void {
          $length = (int) $response->getHeaderLine('Content-Length');
          if ($length > $maximum) {
            throw new \RuntimeException('The remote file exceeds the configured size limit.');
          }
          $mime = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        },
        'progress' => static function (int $downloadTotal, int $downloadedBytes) use ($maximum): void {
          if ($downloadTotal > $maximum || $downloadedBytes > $maximum) {
            throw new \RuntimeException('The remote file exceeds the configured size limit.');
          }
        },
      ]);
      clearstatcache(TRUE, $temporary);
      if (!is_file($temporary) || filesize($temporary) > $maximum) {
        throw new \RuntimeException('The downloaded file exceeds the configured size limit.');
      }
      $allowedMime = array_map('strtolower', $settings['allowed_mime_types'] ?? []);
      $detectedMime = class_exists(\finfo::class) ? strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($temporary)) : $mime;
      if (!$detectedMime || !$allowedMime || !in_array($detectedMime, $allowedMime, TRUE) || ($mime !== '' && !in_array($mime, $allowedMime, TRUE))) {
        throw new \InvalidArgumentException('The remote MIME type is not allowed.');
      }
      $directory = 'public://oai-harvester/' . $source->id();
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $uri = $this->fileSystem->move($temporary, $directory . '/' . $filename, FileExists::Rename);
      $temporary = '';
      $file = $this->entityTypeManager->getStorage('file')->create(['uri' => $uri, 'filename' => basename($uri), 'status' => 1]);
      $file->save();
      $media->set($fileField, ['target_id' => $file->id()]);
      $media->set($mediaOfField, ['target_id' => $nodeId]);
      $media->save();
      $this->database->merge('islandora_oai_imported_file')->keys(['source_id' => $source->id(), 'oai_identifier' => $oaiIdentifier, 'url_hash' => $hash])->fields(['media_id' => (int) $media->id(), 'created' => $this->time->getRequestTime()])->execute();
    }
    finally {
      if ($temporary !== '' && is_file($temporary)) {
        unlink($temporary);
      }
    }
  }

}
