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
	use Opcenter\Dns\Record as BaseRecord;

	class Module extends \Dns_Module implements ProviderInterface
	{
		use \NamespaceUtilitiesTrait;

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
		];

		// @var int minimum TTL
		public const DNS_TTL_MIN = 30;

		// @var array API credentials
		private $key;

		public function __construct()
		{
			parent::__construct();
			$this->key = $this->getServiceValue('dns', 'key', DNS_PROVIDER_KEY);
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
			if (!$this->owned_zone($zone)) {
				return error("Domain `%s' not owned by account", $zone);
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
				$this->formatRecord($record);
				$ret = $api->do('POST', "domains/${zone}/records", $this->formatRecord($record));
				if (!isset($ret['id']) && isset($ret['domain_record'])) {
					// pluck from domain_record.id
					$ret = $ret['domain_record'];
				}
				$record->setMeta('id', $ret['id']);
				$this->addCache($record);
			} catch (ClientException $e) {
				$fqdn = ltrim(implode('.', [$subdomain, $zone]), '.');
				return error("Failed to create record `%s' type %s: %s", $fqdn, $rr, $e->getMessage());
			}

			return (bool)$ret;
		}

		/**
		 * @inheritDoc
		 */
		public function remove_record(string $zone, string $subdomain, string $rr, string $param = ''): bool
		{
			if (!$this->canonicalizeRecord($zone, $subdomain, $rr, $param, $ttl)) {
				return false;
			}
			if (!$this->owned_zone($zone)) {
				return error("Domain `%s' not owned by account", $zone);
			}
			$api = $this->makeApi();

			$id = $this->getRecordId($r = new Record($zone,
				['name' => $subdomain, 'rr' => $rr, 'parameter' => $param, 'ttl' => null]));
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

			array_forget_first($this->zoneCache[$r->getZone()], $this->getCacheKey($r), static function ($v) use ($r) {
				return $v['id'] === $r['id'];
			});

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
					'name'       => $domain,
					'ip_address' => $ip
				]);
			} catch (ClientException $e) {
				return error("Failed to add zone `%s', error: %s", $domain, $e->getMessage());
			}

			return true;
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

		/**
		 * Get raw zone data
		 *
		 * @param string $domain
		 * @return null|string
		 */
		protected function zoneAxfr(string $domain): ?string
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
			for ($i = 0; $i < 5; $i++) {
				try {
					$zoneText = $axfr['domain']['zone_file'];
					$records = $client->do('GET', "domains/${domain}/records");
				} catch (ClientException $e) {
					error('Failed to transfer DNS records from DO - try again later');

					return null;
				}
			}

			$this->zoneCache[$domain] = [];
			foreach ($records['domain_records'] as $r) {
				switch ($r['type']) {
					case 'CAA':
						$parameter = $r['flags'] . ' ' . $r['tag'] . ' ' . $r['data'];
						break;
					case 'SRV':
						$parameter = $r['priority'] . ' ' . $r['weight'] . ' ' . $r['port'] . ' ' . $r['data'];
						break;
					case 'MX':
						$parameter = $r['priority'] . ' ' . $r['data'];
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

		private function makeApi(): Api
		{
			return new Api($this->key);
		}

		/**
		 * Modify a DNS record
		 *
		 * @param string $zone
		 * @param Record $old
		 * @param Record $new
		 * @return bool
		 */
		protected function atomicUpdate(string $zone, BaseRecord $old, BaseRecord $new): bool
		{
			// @var \Cloudflare\Api\Endpoints\DNS @api
			if (!$this->canonicalizeRecord($zone, $old['name'], $old['rr'], $old['parameter'], $old['ttl'])) {
				return false;
			}
			$old['ttl'] = null;
			if (!($id = $this->getRecordId($old))) {
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
				$ret = $api->do('PUT', "domains/${zone}/records/${id}", $this->formatRecord($new));
				if (isset($ret['domain_record'])) {
					$ret = $ret['domain_record'];
				}
				$new->setMeta('id', $ret['id'] ?? null);
			} catch (ClientException $e) {
				$reason = \json_decode($e->getResponse()->getBody()->getContents());
				$msg = $reason->errors[0]->message ?? $reason->message;
				return error("Failed to update record `%s' on zone `%s' (old - rr: `%s', param: `%s'; new - rr: `%s', param: `%s'): %s",
					$old['name'],
					$zone,
					$old['rr'],
					$old['parameter'], $new['name'] ?? $old['name'], $new['parameter'] ?? $old['parameter'],
					$msg
				);
			}

			array_forget_first($this->zoneCache[$old->getZone()], $this->getCacheKey($old), static function ($v) use ($old) {
				return $v['id'] === $old['id'];
			});

			$this->addCache($new);

			return true;
		}

		protected function formatRecord(Record $r)
		{
			$args = [
				'type' => strtoupper($r['rr']),
				'ttl'  => (int)($r['ttl'] ?? static::DNS_TTL),
				'name' => $r['name']
			];
			switch ($args['type']) {
				case 'CNAME':
				case 'NS':
					$r['parameter'] = rtrim($r['parameter'], '.') . '.';
				case 'A':
				case 'AAAA':
				case 'TXT':
					return $args + ['data' => $r['parameter']];
				case 'MX':
					return $args + [
						'priority' => (int)$r->getMeta('priority'),
						'data'     => rtrim($r->getMeta('data'), '.') . '.'
					];
				case 'SRV':
					return $args + [
						'priority' => (int)$r->getMeta('priority'),
						'weight'   => (int)$r->getMeta('weight'),
						'port'     => (int)$r->getMeta('port'),
						'data'     => rtrim($r->getMeta('data'), '.') . '.'
					];
				case 'CAA':
					return $args + [
						'flags' => (int)$r->getMeta('flags'),
						'tag'   => $r->getMeta('tag'),
						'data'  => $r->getMeta('data')
					];
				default:
					fatal("Unsupported DNS RR type `%s'", $r['type']);
			}
		}

		protected function hasCnameApexRestriction(): bool
		{
			return false;
		}


	}
