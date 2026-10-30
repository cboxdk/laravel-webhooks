<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Enums;

enum InboundStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Failed = 'failed';
}
