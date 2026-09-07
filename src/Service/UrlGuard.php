<?php

declare(strict_types=1);

namespace Drupal\oai_harvester\Service;

/**
 * Rejects URLs that could reach local or private network resources.
 */
final class UrlGuard {

  /**
   * Validates an outbound HTTP URL and optionally enforces an allowlist.
   *
   * @param string[] $allowedDomains
   *   Exact hostnames or dot-prefixed subdomain suffixes.
   */
  public function assertSafe(string $url, array $allowedDomains = []): void {
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], TRUE)) {
      throw new \InvalidArgumentException('Only absolute HTTP and HTTPS URLs are allowed.');
    }
    if (!empty($parts['user']) || !empty($parts['pass']) || empty($parts['host'])) {
      throw new \InvalidArgumentException('URLs must not contain credentials and must include a hostname.');
    }
    $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
    if ($allowedDomains && !$this->hostAllowed($host, $allowedDomains)) {
      throw new \InvalidArgumentException('The URL hostname is not administrator-allowlisted.');
    }
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || $this->isPrivateIp($host)) {
      throw new \InvalidArgumentException('Local and private-network URLs are not allowed.');
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
      return;
    }
    $addresses = gethostbynamel($host) ?: [];
    if (function_exists('dns_get_record')) {
      foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
        if (!empty($record['ipv6'])) {
          $addresses[] = $record['ipv6'];
        }
      }
    }
    if (!$addresses) {
      throw new \InvalidArgumentException('The URL hostname could not be resolved.');
    }
    foreach (array_unique($addresses) as $address) {
      if ($this->isPrivateIp($address)) {
        throw new \InvalidArgumentException('The URL resolves to a local or private network address.');
      }
    }
  }

  /**
   * @param string[] $allowedDomains
   *   */
  private function hostAllowed(string $host, array $allowedDomains): bool {
    foreach ($allowedDomains as $domain) {
      $domain = strtolower(trim($domain));
      if ($domain === $host || (str_starts_with($domain, '.') && str_ends_with($host, $domain))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   *
   */
  private function isPrivateIp(string $value): bool {
    if (filter_var($value, FILTER_VALIDATE_IP) === FALSE) {
      return FALSE;
    }
    return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE;
  }

}
