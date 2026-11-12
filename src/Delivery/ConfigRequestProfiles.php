<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Cbox\Webhooks\Contracts\RequestProfiles;
use Cbox\Webhooks\Support\Config;
use Cbox\Webhooks\Support\Json;
use Cbox\Webhooks\ValueObjects\Endpoint;
use Cbox\Webhooks\ValueObjects\RequestProfile;
use Illuminate\Contracts\Config\Repository;

/** The default: one user agent and one set of fixed headers, from config, for every endpoint. */
final readonly class ConfigRequestProfiles implements RequestProfiles
{
    public function __construct(
        private Config $config,
        private Repository $repository,
    ) {}

    public function for(Endpoint $endpoint): RequestProfile
    {
        return new RequestProfile(
            $this->config->string('http.user_agent'),
            Json::stringMap($this->repository->get('webhooks.http.headers')),
        );
    }
}
