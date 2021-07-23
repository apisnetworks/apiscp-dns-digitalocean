<?php declare(strict_types=1);

	/**
	 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
	 *
	 * MIT License
	 *
	 * Written by Matt Saladna <matt@apisnetworks.com>, June 2017
	 */

	namespace Opcenter\Dns\Providers\Digitalocean;

	use GuzzleHttp\Exception\RequestException;
	use Opcenter\Dns\Contracts\ServiceProvider;
	use Opcenter\Service\ConfigurationContext;

	class Validator implements ServiceProvider
	{
		public function valid(ConfigurationContext $ctx, &$var): bool
		{
			return ctype_xdigit($var) && static::keyValid((string)$var);
		}

		public static function keyValid(string $key): bool
		{
			try {
				(new Api($key))->do('GET', 'account');
			} catch (RequestException $e) {
				$reason = $e->getMessage();
				if (null !== ($response = $e->getResponse())) {
					$response = \json_decode($response->getBody()->getContents(), true);
					$reason = array_get($response, 'message', 'Invalid key');
				}

				return error('%(provider)s key validation failed: %(reason)s', [
					'provider' => 'Digitalocean',
					'reason'   => $reason
				]);
			}

			return true;
		}
	}
