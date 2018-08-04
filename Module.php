<?php declare(strict_types=1);

	/**
	 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
	 *
	 * MIT License
	 *
	 * Written by Matt Saladna <matt@apisnetworks.com>, August 2018
	 */

	namespace Opcenter\Dns\Providers\Digitalocean;

	use GuzzleHttp\Exception\ClientException;
	use Module\Provider\Contracts\ProviderInterface;
	use Opcenter\Dns\Record;

	class Module extends \Dns_Module implements ProviderInterface
	{
		const DNS_TTL = 1800;

		/**
		 * apex markers are marked with @
		 */
		protected const HAS_ORIGIN_MARKER = true;
		protected static $permitted_records = [
			'A',
			'AAAA',
			'CAA',
			'CNAME',
			'MX',
			'NS',
			'SRV',
			'TXT',
			'ANY',
		];
		use \NamespaceUtilitiesTrait;
		// @var array API credentials
		private $key;

		public function __construct()
		{
			parent::__construct();
			$this->key = $this->get_service_value('dns', 'key', DNS_PROVIDER_KEY);
		}

		/**
		 * Get raw zone data
		 *
		 * @param string $domain
		 * @return null|string
		 */
		protected function zoneAxfr($domain): ?string
		{
			$client = $this->makeApi();
			try {
				$axfr = $client->do('GET', "domains/${domain}");
				if (empty($axfr['domain']['zone_file'])) {
					// zone doesn't exist
					return null;
				}
			} catch (ClientException $e) {
				return null;
			}

			try {
				$zoneText = $axfr['domain']['zone_file'];
				$records = $client->do('GET', "domains/${domain}/records");
			} catch (ClientException $e) {
				error("Failed to transfer DNS records from DO - try again later");
				return null;
			}

			$this->zoneCache[$domain] = [];
			foreach ($records['domain_records'] as $r) {
				switch ($r['type']) {
					case 'CAA':
						$parameter = $r['flags'] . " " . $r['tag'] . " " . $r['value'];
						break;
					case 'SRV':
						$parameter = $r['priority'] . " " . $r['weight'] . " " . $r['port'] . " " . $r['data'];
						break;
					case 'MX':
						$parameter = $r['priority'] . " " . $r['data'];
						break;
					default:
						$parameter = $r['data'];
				}
				$this->addCache(new Record($domain,
					[
						'name'      => $r['name'],
						'rr'        => $r['type'],
						'ttl'       => $r['ttl'] ?? static::DNS_TTL,
						'parameter' => $parameter,
						'meta'      => [
							'id' => $r['id']
						]
					]
				));
			}

			return $zoneText;
		}

		/**
		 * Modify a DNS record
		 *
		 * @param string $zone
		 * @param Record $old
		 * @param Record $new
		 * @return bool
		 */
		protected function atomicUpdate(string $zone, Record $old, Record $new): bool
		{
			// @var \Cloudflare\Api\Endpoints\DNS @api
			if (!$this->canonicalizeRecord($zone, $old['name'], $old['rr'], $old['parameter'], $old['ttl'])) {
				return false;
			}
			if (!$this->getRecordId($old)) {
				return error("failed to find record ID in DO zone `%s' - does `%s' (rr: `%s', parameter: `%s') exist?",
					$zone, $old['name'], $old['rr'], $old['parameter']);
			}
			if (!$this->canonicalizeRecord($zone, $new['name'], $new['rr'], $new['parameter'], $new['ttl'])) {
				return false;
			}
			$api = $this->makeApi();
			try {
				$merged = clone $old;
				$new = $merged->merge($new);
				$id = $this->getRecordId($old);
				$api->do('PUT', "domains/${zone}/records/${id}", $this->formatRecord($new));
			} catch (ClientException $e) {
				$reason = \json_decode($e->getResponse()->getBody()->getContents());

				return error("Failed to update record `%s' on zone `%s' (old - rr: `%s', param: `%s'; new - rr: `%s', param: `%s'): %s",
					$old['name'],
					$zone,
					$old['rr'],
					$old['parameter'], $new['name'] ?? $old['name'], $new['parameter'] ?? $old['parameter'],
					$reason->errors[0]->message
				);
			}
			array_forget($this->zoneCache[$old->getZone()], $this->getCacheKey($old));
			$this->addCache($new);

			return true;
		}

		/**
		 * Add a DNS record
		 *
		 * @param string $zone
		 * @param string $subdomain
		 * @param string $rr
		 * @param string $param
		 * @param int    $ttl
		 * @return bool
		 */
		public function add_record(
			string $zone,
			string $subdomain,
			string $rr,
			string $param,
			int $ttl = self::DNS_TTL
		): bool {
			if (!$this->canonicalizeRecord($zone, $subdomain, $rr, $param, $ttl)) {
				return false;
			}
			$api = $this->makeApi();
			$record = new Record($zone, [
				'name'      => $subdomain,
				'rr'        => $rr,
				'parameter' => $param,
				'ttl'       => $ttl
			]);
			if ($record['name'] === '') {
				$record['name'] = '@';
			}
			try {
				$ret = $api->do('POST', "domains/${zone}/records", $this->formatRecord($record));
				$this->addCache($record);
			} catch (ClientException $e) {
				$fqdn = ltrim(implode('.', [$subdomain, $zone]), '.');

				return error("Failed to create record `%s' type %s: %s", $fqdn, $rr, $e->getMessage());
			}

			return (bool)$ret;
		}

		/**
		 * Remove a DNS record
		 *
		 * @param string      $zone
		 * @param string      $subdomain
		 * @param string      $rr
		 * @param string|null $param
		 * @return bool
		 */
		public function remove_record(string $zone, string $subdomain, string $rr, string $param = null): bool
		{
			if (!$this->canonicalizeRecord($zone, $subdomain, $rr, $param, $ttl)) {
				return false;
			}
			$api = $this->makeApi();

			$id = $this->getRecordId($r = new Record($zone, ['name' => $subdomain, 'rr' => $rr, 'parameter' => $param]));
			if (!$id) {
				$fqdn = ltrim(implode('.', [$subdomain, $zone]), '.');

				return error("Record `%s' (rr: `%s', param: `%s')  does not exist", $fqdn, $rr, $param);
			}

			try {
				$api->do('DELETE', "domains/${zone}/records/${id}");
			} catch (ClientException $e) {
				$fqdn = ltrim(implode('.', [$subdomain, $zone]), '.');
				return error("Failed to delete record `%s' type %s", $fqdn, $rr);
			}
			array_forget($this->zoneCache[$r->getZone()], $this->getCacheKey($r));
			return $api->getResponse()->getStatusCode() === 204;
		}

		/**
		 * Get hosting nameservers
		 *
		 * @param string|null $domain
		 * @return array
		 */
		public function get_hosting_nameservers(string $domain = null): array
		{
			return ['ns1.digitalocean.com', 'ns2.digitalocean.com', 'ns3.digitalocean.com'];
		}

		/**
		 * Add DNS zone to service
		 *
		 * @param string $domain
		 * @param string $ip
		 * @return bool
		 */
		public function add_zone_backend(string $domain, string $ip): bool
		{
			/**
			 * @var Zones $api
			 */
			$api = $this->makeApi();
			try {
				$api->do('POST', 'domains', [
					'name' => $domain,
					'ip_address' => $ip
				]);
			} catch (ClientException $e) {
				return error("Failed to add zone `%s', error: %s", $domain, $e->getMessage());
			}

			return true;
		}

		public function add_zone(string $domain, string $ip): bool
		{
			if (!parent::add_zone($domain, $ip)) {
				return false;
			}

			for ($i = 0; $i < 10; $i++) {
				if (null !== $this->getZoneId($domain)) {
					return true;
				}
				sleep(1);
			}

			return warn("DigitalOcean zone index has not updated yet - DNS may be incomplete for `%s'", $domain);
		}

		/**
		 * Remove DNS zone from nameserver
		 *
		 * @param string $domain
		 * @return bool
		 */
		public function remove_zone_backend(string $domain): bool
		{
			$api = $this->makeApi();
			try {
				$api->do('DELETE', "domains/${domain}");
			} catch (ClientException $e) {
				return error("Failed to remove zone `%s', error: %s", $domain, $e->getMessage());
			}
			return true;
		}

		protected function hasCnameApexRestriction(): bool
		{
			return false;
		}


		private function makeApi(): Api
		{
			return new Api($this->key);
		}

		protected function formatRecord(Record $r) {
			$args = [
				'type' => strtoupper($r['rr']),
				'ttl' => $r['ttl'] ?? static::DNS_TTL
			];
			switch ($args['type']) {
				case 'A':
				case 'AAAA':
				case 'CNAME':
				case 'TXT':
				case 'NS':
					return $args + ['name' => $r['name'], 'data' => $r['parameter']];
				case 'MX':
					return $args + ['name' => $r['name'], 'priority' => (int)$r->getMeta('priority'), 'data' => rtrim($r->getMeta('data'),'.') . '.'];
				case 'SRV':
					return $args + [
						'name' => $r['name'],
						'priority' => $r->getMeta('priority'),
						'weight' => $r->getMeta('weight'),
						'port' => $r->getMeta('port'),
						'data' => $r->getMeta('data')
					];
				case 'CAA':
					return $args + [
						'flags' => $r->getMeta('flags'),
						'tag' => $r->getMeta('tag'),
						'data' => $r->getMeta('data')
					];
				default:
					fatal("Unsupported DNS RR type `%s'", $r['type']);
			}
		}


	}